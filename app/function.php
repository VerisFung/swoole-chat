<?php

function consoleLog($item, $msg)
{
	if (is_object($msg) || is_array($msg)) {
		echo "[" . date('Y-m-d H:i:s ') . msectime() . "] {$item}:\n";
		echo var_export($msg, true) . "\n";
	} else {
		echo "[" . date('Y-m-d H:i:s ') . msectime() . "] {$item} - {$msg}\n";
	}
}

function msectime()
{
	list($msec, $sec) = explode(' ', microtime());
	$t = sprintf('%.0f', floatval($msec) * 1000);
	return str_pad($t, 3, '0', STR_PAD_LEFT);
}

function device_model_type($model)
{
	if (strpos($model, '-108') !== false) {
		return 108;
	} elseif (strpos($model, '-80') !== false) {
		return 80;
	} elseif (strpos($model, '-58') !== false) {
		return 58;
	} else {
		return false;
	}
}