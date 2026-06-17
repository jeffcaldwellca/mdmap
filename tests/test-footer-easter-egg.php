<?php
/**
 * Standalone test for MultipleDomainMapper::footerEasterEggText().
 * Run: php tests/test-footer-easter-egg.php
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

$method = new ReflectionMethod('MultipleDomainMapper', 'footerEasterEggText');
$method->setAccessible(true);
$egg = function($text, $screenId, $hookSuffix) use ($method){
	return $method->invoke(null, $text, $screenId, $hookSuffix);
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

$original = 'Thank you for creating with WordPress';
$eggHtml  = '<span class="mdmap_app_easter_egg">Clever girl.</span>';

//on our own screen, the footer text becomes the easter egg
check(
	'shows easter egg on the Muldoon screen',
	$eggHtml,
	$egg($original, 'tools_page_app', 'tools_page_app')
);
//on any other screen, the original footer text is left untouched
check(
	'leaves other admin screens untouched',
	$original,
	$egg($original, 'dashboard', 'tools_page_app')
);
//safety: when the hook suffix is unknown/empty, never hijack the footer
check(
	'no hijack when hook suffix is empty',
	$original,
	$egg($original, '', '')
);

exit($failures === 0 ? 0 : 1);
