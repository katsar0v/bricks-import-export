<?php
/** Late-loaded maintenance ability tests, isolated from earlier fallback tests. */

namespace {
	if ( ! function_exists( 'bricks_ie_test' ) ) require_once __DIR__ . '/bootstrap.php';

	bricks_ie_test( 'adapter: public Maintenance CSS regeneration is preferred and normalizes cssLoading from settings', function () {
		if ( ! class_exists( 'Bricks\\Abilities\\Maintenance', false ) ) {
			eval( 'namespace Bricks\\Abilities; class Maintenance { public static $result; public static $calls = array(); public static function regenerate_css_files( $args = array() ) { self::$calls[] = $args; return self::$result; } }' );
		}
		bricks_ie_adapter_test_reset();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array( 'cssLoading' => 'file' );
		\Bricks\Abilities\Maintenance::$result = array( 'success' => true, 'generatedFileCount' => 1, 'generatedFiles' => array( 'maintenance.css' ), 'cssLoading' => 'file' );
		$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
		bricks_ie_assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() : var_export( $result, true ) );
		bricks_ie_assert_same( 'maintenance', $result['via'], var_export( $result, true ) );
		bricks_ie_assert_same( 'file', $result['css_loading'] );
		$lookup = new ReflectionMethod( bricks_ie_adapter_test_adapter(), 'get_css_loading' ); $lookup->setAccessible( true );
		bricks_ie_assert_same( 'file', $lookup->invoke( bricks_ie_adapter_test_adapter() ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_settings'] = array( 'cssLoading' => 'inline' );
		bricks_ie_assert_same( 'inline', $lookup->invoke( bricks_ie_adapter_test_adapter() ) );
	} );

	bricks_ie_test( 'adapter: Maintenance CSS errors and malformed results fail closed', function () {
		bricks_ie_adapter_test_reset();
		\Bricks\Abilities\Maintenance::$result = new WP_Error( 'maintenance_failed', 'maintenance failed' );
		$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'maintenance_failed', $result->get_error_code() );
		\Bricks\Abilities\Maintenance::$result = array( 'success' => true, 'generatedFileCount' => 0, 'generatedFiles' => array() );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_settings'] = array( 'cssLoading' => 'inline' );
		$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'bricks_ie_css_regeneration_result_invalid', $result->get_error_code() );
	} );
}
