<?php

bricks_ie_test(
	'harness registers tests and exposes a WordPress error stub',
	function () {
		$error = new WP_Error( 'example', 'Example failure', array( 'status' => 400 ) );

		bricks_ie_assert( is_wp_error( $error ), 'WP_Error should be recognised.' );
		bricks_ie_assert_same( 'example', $error->get_error_code() );
		bricks_ie_assert_same( 'Example failure', $error->get_error_message() );
		bricks_ie_assert_same( array( 'status' => 400 ), $error->get_error_data() );
	}
);

bricks_ie_test(
	'temporary test directories are created below the system temporary directory',
	function () {
		$directory = bricks_ie_test_temp_dir();
		$file      = $directory . DIRECTORY_SEPARATOR . 'fixture.txt';

		bricks_ie_assert( 0 === strpos( $directory, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) );
		bricks_ie_assert( false !== file_put_contents( $file, 'fixture' ) );
		bricks_ie_assert_same( 'fixture', file_get_contents( $file ) );
	}
);
