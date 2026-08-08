<?php
/** Dependency-free test runner. Run from this plugin directory or anywhere. */

require_once __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/test-*.php' );
sort( $files );

foreach ( $files as $file ) {
	require_once $file;
}

$passed = 0;
$failed = 0;

foreach ( $GLOBALS['bricks_ie_tests'] as $name => $test ) {
	try {
		$test();
		$passed++;
		fwrite( STDOUT, "PASS: {$name}\n" );
	} catch ( Throwable $exception ) {
		$failed++;
		fwrite( STDERR, "FAIL: {$name}\n       " . $exception->getMessage() . "\n" );
	}
}

$total = $passed + $failed;
fwrite( STDOUT, "\n{$total} tests, {$passed} passed, {$failed} failed.\n" );
exit( $failed > 0 ? 1 : 0 );
