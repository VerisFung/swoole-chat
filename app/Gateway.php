<?php

namespace MostclanChat;

use Swoole\Coroutine;
use Swoole\Database\PDOConfig;
use Swoole\Database\RedisPool;
use Swoole\Database\RedisConfig;
use Swoole\Database\PDOPool;
use Swoole\Runtime;

class Gateway
{
	const WORKER_NUM   = 4; // 进程数
	const SERVER_PORT  = 5628; // 端口
	const REDIS_CONFIG = [ // Redis配置
		'host' => '127.0.0.1',
		'port' => 6379,
		'auth' => '',
		'db'   => 0,
	];
	protected static $redisPool;

	static public function initServer()
	{
		consoleLog('system', 'WebSocket 服务初始化');
		self::$redisPool = new RedisPool((new RedisConfig)
			->withHost(self::REDIS_CONFIG['host'])
			->withPort(self::REDIS_CONFIG['port'])
			->withAuth(self::REDIS_CONFIG['auth'])
			->withDbIndex(self::REDIS_CONFIG['db'])
			->withTimeout(1)
		);
		$server          = new \Swoole\Websocket\Server('0.0.0.0', self::SERVER_PORT);
		$server->set([
			'worker_num'               => self::WORKER_NUM,
			'max_connection'           => 256,
			'socket_buffer_size'       => 4096 * 1024 * 1024,
			'daemonize'                => 0,
			'backlog'                  => 128,
			'reload_async'             => true, // 异步重启
			'log_file'                 => __DIR__ . '/../log/server.log',
			'pid_file'                 => __DIR__ . '/../log/server.pid',
			'heartbeat_check_interval' => 10, // 心跳检测周期
			'heartbeat_idle_time'      => 40, // 心跳检测时长（超时剔除）
			'buffer_output_size'       => 2 * 1024 * 1024, //发送输出缓存区内存尺寸
		]);
		$server->on('open', '\MostclanChat\Gateway::onOpen');
		$server->on('message', '\MostclanChat\Gateway::onMessage');
		$server->on('close', '\MostclanChat\Gateway::onClose');

		// 初始化
		self::redis(function ($redis) {
			$redis->del('room:1:msg');
			$redis->del('room:1:online');
		});

		$server->start();
	}

	static protected function redis(callable $func)
	{
		return Coroutine::create(function () use ($func) {
			$redis = self::$redisPool->get();
			$func($redis);
			self::$redisPool->put($redis);
		});
	}

	static public function onOpen($server, $req)
	{
		$fd = $req->fd;
		consoleLog('system', "new connect {$fd}");
	}

	static public function onMessage($server, $frame)
	{
		$data = json_decode($frame->data, true);
		$fd   = $frame->fd;
//		consoleLog("fd|{$fd}", $data);
		if (!isset($data['action']) || !isset($data['params'])) {
			return false;
		}
		switch ($data['action']) {
			default:
				self::redis(function ($redis) use ($fd, $server) {
					$server->push($fd, static::response('system', 500, 'Internal service error.'));
				});
				break;
			case 'heart.ping':
				break;
			case 'session.create': // 登录
				self::redis(function ($redis) use ($fd, $server) {
					$redis->zAdd('room:1:online', time(), $fd);
					mt_srand();
					$nickname = '游客_' . $fd;
					$time     = time();
					$redis->hSet("client:{$fd}:session", 'nickname', $nickname);
					$redis->hSet("client:{$fd}:session", 'login_at', $time);

					// 向所有客户端发送登录状态
					$lists     = $redis->zRange('room:1:online', 0, -1);
					$onlineFds = [];
					if ($lists) {
						foreach ($lists as $otherFd) {
							if ($server->isEstablished($otherFd)) {
								$onlineFds[] = [
									'fd'       => $otherFd,
									'nickname' => $redis->hGet("client:{$otherFd}:session", 'nickname'),
								];
								$server->push($otherFd, static::response('session.create', 0, 'success', [
									'fd'       => $fd,
									'nickname' => $nickname,
									'login_at' => $time,
								]));
							}
						}
					}
					// 向登录用户发送所有用户信息列表
					$server->push($fd, static::response('online.init', 0, 'success', $onlineFds));
				});
				break;
			case 'msg.send':
				self::redis(function ($redis) use ($fd, $server, $data) {
					$time     = time();
					$content  = $data['params']['content'];
					$nickname = $redis->hGet("client:{$fd}:session", 'nickname');
					// 向所有客户端发送消息
					$lists = $redis->zRange('room:1:online', 0, -1);
					if ($lists) {
						foreach ($lists as $otherFd) {
							if ($server->isEstablished($otherFd)) {
								$server->push($otherFd, static::response('msg.send', 0, 'success', [
									'fd'       => $fd,
									'nickname' => $nickname,
									'content'  => $content,
									'time'     => $time,
								]));
							}
						}
					}
				});
				break;
		}
		return true;
	}

	static public function onClose($server, $fd)
	{
		consoleLog('system', "close connect {$fd}");
		self::redis(function ($redis) use ($fd, $server) {
			$redis->zRem('room:1:online', $fd);
			$redis->del("client:{$fd}:session");
			// 向所有客户端发送登录状态
			$lists = $redis->zRange('room:1:online', 0, -1);
			if ($lists) {
				foreach ($lists as $otherFd) {
					if ($server->isEstablished($otherFd)) {
						$server->push($otherFd, static::response('session.close', 0, 'success', [
							'fd' => $fd,
						]));
					}
				}
			}
		});
	}

	static public function response($action, $code, $msg = '', $data = [])
	{
		return json_encode([
			'action' => $action,
			'code'   => $code,
			'msg'    => $msg,
			'data'   => $data,
		]);
	}
}