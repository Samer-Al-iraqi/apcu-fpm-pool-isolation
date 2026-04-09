<?php
const keys = [
    "test_key_1",
    "test_key_222222",
	"test_key_333",
    "test_key_4_long_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx".
	"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx".
	"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
		,
    "test_key_5_edge_\0_char", // weird key
];



if(php_sapi_name() !== 'fpm-fcgi') {
	echo "This test must be run under FPM (php-fpm) to properly test the isolation hack";
	exit(1);
}
ob_start(fn($buf)=>addcslashes($buf, "\0"));
header("Content-Type: text/plain; charset=utf-8");

if (!extension_loaded('apcu')) {
    fail("APCu extension is not loaded\n");
}

if (!ini_get('apc.enabled')) {
    fail("APCu is disabled (apc.enabled=0)\n");
}
echo "Starting APCu test...\n\n";

/* ---------- Helpers ---------- */

function fail($msg) {
	http_response_code(418);
    echo "❌ TEST FAILED: $msg\n";
    exit(1);
}
function trimKey($key) {
	if(strlen($key) > 20) return (substr($key, 0, 20) . '...');
	return $key;
}
function eachKey($fn) {
	foreach (keys as $key) $fn($key);
}
function str_args($args, $fn=null){
	foreach($args AS &$arg) $arg=var_export($fn? $fn($arg): $arg, true);
	return implode(', ', $args);
}


function withKeys($expected, string $fn, ...$args) {
	$argsStr = $args? (', '.str_args($args)) : '';
	$expected_export = var_export($expected, true);
	echo "\n=== Testing $fn | expected return is $expected_export ===\n";
	foreach (keys as $key) {
		$eKey = var_export(trimKey($key), true);
		$ret=$fn($key, ...$args);
		echo "$fn($eKey$argsStr) = ", var_export($ret, true);
		if ($ret === $expected) {
			echo " ✔\n";
		}
		else {
			echo " ❌\n";
			fail("$fn returned unexpected value for key $eKey");
		}
	}
}

eachKey(fn($k)=>apcu_delete($k)); // clean up any existing keys before starting

/*
testing 
apcu_add
apcu_cas
apcu_dec
apcu_delete
apcu_entry
apcu_exists
apcu_fetch
apcu_inc
apcu_key_info
apcu_store
*/

withKeys(true, 'apcu_add', 'value', 10);
withKeys(false, 'apcu_add', 'value', 10);
withKeys(true, 'apcu_exists');
withKeys(true, 'apcu_exists');
withKeys(true, 'apcu_delete');
withKeys(false, 'apcu_delete');
withKeys(false, 'apcu_exists');
withKeys(false, 'apcu_fetch');
withKeys(true, 'apcu_store', 'value2', 10);
withKeys(true, 'apcu_store', 1000);
withKeys(1000, 'apcu_fetch');
withKeys(true, 'apcu_cas', 1000, 1001);
withKeys(false, 'apcu_cas', 1000, 1001);
withKeys(1001, 'apcu_fetch');
withKeys(true, 'apcu_delete');
withKeys(1, 'apcu_inc', 1, $success, 10);
withKeys(2, 'apcu_inc', 1, $success, 10);
withKeys(2, 'apcu_fetch');
withKeys(1, 'apcu_dec', 1, $success, 10);


$ekeys=str_args(keys, 'trimKey');
$multiKeys=function($fn, $expected) use($ekeys) {
	$ret=$fn(keys);
	$ok= ($expected==$ret);
	$ret=str_args($ret, 'trimKey');
	echo "\n$fn($ekeys) = [$ret] ";
	if($ok) {
		echo "✔\n";
	}
	else {
		echo "❌\n";
		fail("$fn failed for multiple keys, expected is " . var_export($expected,true));
	}

};

$multiKeys('apcu_delete', []);
$multiKeys('apcu_delete', keys);
$multiKeys('apcu_exists', []);
withKeys('value', 'apcu_entry', fn($key) => "value", 10);
$multiKeys('apcu_exists', array_fill_keys(keys, true));
$multiKeys('apcu_fetch', array_fill_keys(keys, 'value'));


echo "\nAPCu contents:\n";
$it = new APCUIterator('/_test_key_/');
foreach ($it as $i=>$item) {
	$key=strlen($item['key']) > 50 ? (substr($item['key'], 0, 50) . '...') : $item['key'];
	echo "Key: $key, Value: {$item['value']}\n";
}
echo "\n";


foreach (keys as $key) {
	$ret=apcu_key_info($key);
	if(!$ret || !isset($ret['hits'])) fail("apcu_key_info failed for key " . trimKey($key));
}
eachKey(fn($k)=>apcu_delete($k)); // clean up before stress test


/* ---------- Stress loop ---------- */

echo "\nRunning stress loop...\n";

for ($i = 0; $i < 1000; $i++) {
    $k = "loop_" . ($i % 10);

    if(!apcu_store($k, $i)) fail("loop store failed at $k");
	if(apcu_fetch($k) !== $i) fail("loop fetch mismatch at $k");
	if(!apcu_delete($k)) fail("loop delete failed at $k");
	if(apcu_exists($k)) fail("loop delete failed at $k");
}

echo "\nStress loop passed\n";


echo "\n✅ ALL TESTS PASSED\n";
