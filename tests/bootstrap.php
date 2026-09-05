<?php
/**
 * Small, dependency-free test bootstrap for isolated plugin tests.
 *
 * This deliberately does not load WordPress or the plugin bootstrap. Tests can
 * include individual classes after requiring this file and provide only the
 * collaborators that class needs.
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message( $code = '' ) {
			return $this->message;
		}

		public function get_error_data( $code = '' ) {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

/** @var array<string, callable> */
$GLOBALS['bricks_ie_tests'] = array();

function bricks_ie_test( $name, $test ) {
	if ( ! is_string( $name ) || '' === $name || ! is_callable( $test ) ) {
		throw new InvalidArgumentException( 'A test needs a name and a callable.' );
	}

	$GLOBALS['bricks_ie_tests'][ $name ] = $test;
}

function bricks_ie_assert( $condition, $message = 'Assertion failed.' ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function bricks_ie_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		$detail = '' === $message ? 'Values are not identical.' : $message;
		throw new RuntimeException( $detail . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' );
	}
}

function bricks_ie_assert_instance_of( $class, $value, $message = '' ) {
	if ( ! $value instanceof $class ) {
		$detail = '' === $message ? 'Value has the wrong type.' : $message;
		throw new RuntimeException( $detail . ' Expected instance of ' . $class . '.' );
	}
}

function bricks_ie_test_temp_dir() {
	$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bricks-ie-tests-' . bin2hex( random_bytes( 8 ) );
	if ( ! mkdir( $directory, 0700 ) ) {
		throw new RuntimeException( 'Could not create the test temporary directory.' );
	}

	$GLOBALS['bricks_ie_test_temp_dirs'][] = $directory;
	return $directory;
}

function bricks_ie_remove_test_temp_path( $path ) {
	if ( is_dir( $path ) ) {
		$entries = scandir( $path );
		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				bricks_ie_remove_test_temp_path( $path . DIRECTORY_SEPARATOR . $entry );
			}
		}
		return rmdir( $path );
	}

	return ! file_exists( $path ) || unlink( $path );
}

$GLOBALS['bricks_ie_test_temp_dirs'] = array();
register_shutdown_function(
	function () {
		foreach ( $GLOBALS['bricks_ie_test_temp_dirs'] as $directory ) {
			bricks_ie_remove_test_temp_path( $directory );
		}
	}
);
