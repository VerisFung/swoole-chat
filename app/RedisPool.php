<?php

namespace MostclanChat;
class RedisPool
{
	/**
	 * @var \Swoole\Coroutine\Channel
	 */
	protected $pool;
	const POOL_TIME_OUT = 10;

	/**
	 * RedisPool constructor.
	 * @param int $size 连接池的尺寸
	 */
	public function __construct($config, $size)
	{
		$this->pool = new \Swoole\Coroutine\Channel($size);
		for ($i = 0; $i < $size; $i++) {
			$redis = new \Swoole\Coroutine\Redis();
			$res   = $redis->connect($config['host'], $config['port']);
			if ($res == false) {
				throw new \RuntimeException("Redis_{$i} 连接失败");
			} else {
				$this->pushRedis($redis);
			}
			if ($config['auth']) {
				$redis->auth($config['auth']);
			}
			$redis->select($config['db']);
		}
	}

	public function pushRedis($redis)
	{
		$this->pool->push($redis);
	}

	public function getRedis()
	{
		$redis = $this->pool->pop(self::POOL_TIME_OUT);
		if (false === $redis) {
			throw new \RuntimeException("Pop redis timeout");
		}
		return $redis;
	}

	public function __call($name, $arguments)
	{
		$redis = $this->getRedis();
		try {
			$res = call_user_func_array([$redis, $name], $arguments);
		} catch (\Exception $e) {
			$res = false;
			consoleLog('Redis执行错误', $name . ' | ' . $e->__toString());
		}
		// 循环连接
		$this->pushRedis($redis);
		return $res;
	}
}