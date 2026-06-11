<?php
/**
 * Standalone test for MultipleDomainMapper::resolvePhpServerVar().
 * Run: php tests/test-resolve-php-server-var.php
 * Exits non-zero on failure.
 */

//minimal WordPress stubs so app.php can load outside WP
define('ABSPATH', sys_get_temp_dir() . '/');
function plugin_basename($file){ return basename($file); }
function get_home_url(){ return 'http://example.com'; }
function get_site_url(){ return 'http://example.com'; }
function get_option($name){ return false; }
function add_action(){}
function add_filter(){}
function is_admin(){ return false; }
function trailingslashit($string){ return rtrim($string, '/') . '/'; }

require dirname(__DIR__) . '/app.php';

$method = new ReflectionMethod('MultipleDomainMapper', 'resolvePhpServerVar');
$resolve = function($settings, $server) use ($method){
	return $method->invoke(null, $settings, $server);
};

$failures = 0;
function check($label, $expected, $actual){
	global $failures;
	if($expected === $actual){
		echo "PASS: $label\n";
	}else{
		echo "FAIL: $label — expected '$expected', got '$actual'\n";
		$failures++;
	}
}

//explicitly saved setting is honored even when the two server vars disagree
check(
	'explicit SERVER_NAME honored when HTTP_HOST differs',
	'SERVER_NAME',
	$resolve(array('php_server' => 'SERVER_NAME'), array('SERVER_NAME' => 'internal.local', 'HTTP_HOST' => 'example.com'))
);
check(
	'explicit HTTP_HOST honored',
	'HTTP_HOST',
	$resolve(array('php_server' => 'HTTP_HOST'), array('SERVER_NAME' => 'example.com', 'HTTP_HOST' => 'example.com'))
);

//no saved setting: auto mode prefers HTTP_HOST when SERVER_NAME is missing or disagrees
check(
	'auto: falls back to HTTP_HOST when hosts differ',
	'HTTP_HOST',
	$resolve(false, array('SERVER_NAME' => 'internal.local', 'HTTP_HOST' => 'example.com'))
);
check(
	'auto: falls back to HTTP_HOST when SERVER_NAME unset',
	'HTTP_HOST',
	$resolve(false, array('HTTP_HOST' => 'example.com'))
);
check(
	'auto: uses SERVER_NAME when hosts agree',
	'SERVER_NAME',
	$resolve(false, array('SERVER_NAME' => 'example.com', 'HTTP_HOST' => 'example.com'))
);
check(
	'auto: uses SERVER_NAME when HTTP_HOST empty',
	'SERVER_NAME',
	$resolve(array(), array('SERVER_NAME' => 'example.com'))
);

//corrupted/unknown saved value is treated as unset (auto mode)
check(
	'invalid saved value falls back to auto mode',
	'HTTP_HOST',
	$resolve(array('php_server' => 'bogus'), array('SERVER_NAME' => 'internal.local', 'HTTP_HOST' => 'example.com'))
);

exit($failures === 0 ? 0 : 1);
