<?php
/**
 * Swoole-chat - 基于swoole的聊天室
 * @author Veris <https://github.com/VerisFung/swoole-chat>
 * @website http://mostclan.com
 */

include __DIR__ . '/app/Gateway.php';
include __DIR__ . '/app/function.php';
include __DIR__ . '/app/RedisPool.php';

\MostclanChat\Gateway::initServer();