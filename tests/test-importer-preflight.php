<?php
/**
 * Tests for Bricks_IE_Importer::preflight() and the preflight result contract.
 *
 * Runs under the plugin-local harness (tests/bootstrap.php + tests/run.php)
 * or standalone via `php tests/test-importer-preflight.php`. Local fallback
 * guards below keep this file self-contained without editing harness files.
 *
 * Preflight is a strictly no-write operation. Every WordPress write function
 * the importer could reach is stubbed to throw, so any write attempted during
 * preflight fails the test loudly. The native Bricks stub records every call
 * so the tests can prove that only inspect (never import) runs.
 *
 * All fixtures are built below the system temporary directory. No database,
 * option, post, meta, transient, or cache mutation happens anywhere here.
 */

// ======================================================================
// Bootstrap: prefer the shared harness, fall back to local guards.
// ======================================================================

namespace {

	if ( ! function_exists( 'bricks_ie_test' ) ) {
		$bricks_ie_pf_bootstrap = __DIR__ . '/bootstrap.php';
		if ( file_exists( $bricks_ie_pf_bootstrap ) ) {
			require_once $bricks_ie_pf_bootstrap;
		}
	}

	if ( ! function_exists( 'bricks_ie_test' ) ) {
		// Minimal local fallback in case the harness is not available.
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

		$GLOBALS['bricks_ie_tests']          = array();
		$GLOBALS['bricks_ie_test_temp_dirs'] = array();

		function bricks_ie_test( $name, $test ) {
			$GLOBALS['bricks_ie_tests'][ $name ] = $test;
		}

		function bricks_ie_assert( $condition, $message = 'Assertion failed.' ) {
			if ( ! $condition ) {
				throw new RuntimeException( $message );
			}
		}

		function bricks_ie_assert_same( $expected, $actual, $message = '' ) {
			if ( $expected !== $actual ) {
				throw new RuntimeException( $message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.' );
			}
		}

		function bricks_ie_assert_instance_of( $class, $value, $message = '' ) {
			if ( ! $value instanceof $class ) {
				throw new RuntimeException( $message . ' Expected instance of ' . $class . '.' );
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
				foreach ( scandir( $path ) as $entry ) {
					if ( '.' !== $entry && '..' !== $entry ) {
						bricks_ie_remove_test_temp_path( $path . DIRECTORY_SEPARATOR . $entry );
					}
				}
				return rmdir( $path );
			}
			return ! file_exists( $path ) || unlink( $path );
		}

		register_shutdown_function(
			function () {
				foreach ( $GLOBALS['bricks_ie_test_temp_dirs'] as $directory ) {
					bricks_ie_remove_test_temp_path( $directory );
				}
			}
		);
	}

	// ==================================================================
	// WordPress primitive stubs (read-only, configurable per test).
	// ==================================================================

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value ) {
			if ( isset( $GLOBALS['bricks_ie_pf_filter_overrides'][ $tag ] ) ) {
				return $GLOBALS['bricks_ie_pf_filter_overrides'][ $tag ];
			}
			return $value;
		}
	}

	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return is_string( $url ) ? $url : '';
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) {
			return isset( $GLOBALS['bricks_ie_preflight_test']['home_url'] )
				? $GLOBALS['bricks_ie_preflight_test']['home_url']
				: 'https://target.example';
		}
	}

	if ( ! class_exists( 'Bricks_IE_PF_Theme_Stub' ) ) {
		class Bricks_IE_PF_Theme_Stub {
			public function exists() {
				return isset( $GLOBALS['bricks_ie_preflight_test']['bricks_version'] )
					&& null !== $GLOBALS['bricks_ie_preflight_test']['bricks_version'];
			}

			public function get( $header = '' ) {
				if ( 'Version' === $header ) {
					return isset( $GLOBALS['bricks_ie_preflight_test']['bricks_version'] )
						? $GLOBALS['bricks_ie_preflight_test']['bricks_version']
						: null;
				}
				return '';
			}
		}
	}

	if ( ! function_exists( 'wp_get_theme' ) ) {
		function wp_get_theme( $stylesheet = '' ) {
			return new Bricks_IE_PF_Theme_Stub();
		}
	}

	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( $post_type ) {
			$types = isset( $GLOBALS['bricks_ie_preflight_test']['post_types'] )
				? $GLOBALS['bricks_ie_preflight_test']['post_types']
				: array( 'page', 'post', 'bricks_template' );
			return in_array( $post_type, $types, true );
		}
	}

	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = array() ) {
			$posts = isset( $GLOBALS['bricks_ie_preflight_test']['existing_posts'] )
				? $GLOBALS['bricks_ie_preflight_test']['existing_posts']
				: array();
			$type  = isset( $args['post_type'] ) ? (string) $args['post_type'] : '';
			$name  = isset( $args['name'] ) ? (string) $args['name'] : '';
			$key   = $type . '|' . $name;

			return isset( $posts[ $key ] ) ? array( $posts[ $key ] ) : array();
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $capability ) {
			$caps = isset( $GLOBALS['bricks_ie_preflight_test']['caps'] )
				? $GLOBALS['bricks_ie_preflight_test']['caps']
				: array();
			return ! empty( $caps[ $capability ] );
		}
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( $name ) {
			return null;
		}
	}

	// Plugin bootstrap function stubs (normally defined in bricks-import-export.php).

	if ( ! function_exists( 'bricks_ie_get_option_names' ) ) {
		function bricks_ie_get_option_names() {
			return array(
				'bricks_global_settings',
				'bricks_theme_styles',
				'bricks_global_classes',
				'bricks_color_palette',
				'bricks_style_manager',
				'bricks_global_variables',
				'bricks_components',
				'bricks_global_queries',
				'bricks_global_elements',
				'bricks_breakpoints',
			);
		}
	}

	if ( ! function_exists( 'bricks_ie_get_create_missing_post_types' ) ) {
		function bricks_ie_get_create_missing_post_types() {
			return array( 'page', 'bricks_template' );
		}
	}

	if ( ! function_exists( 'bricks_ie_get_update_post_fields_post_types' ) ) {
		function bricks_ie_get_update_post_fields_post_types() {
			return array( 'page', 'bricks_template' );
		}
	}

	// ==================================================================
	// Write guards: preflight must never write. Any write function call
	// records the attempt and fails the running test immediately.
	//
	// Mutation-path tests (tests/test-importer-v1.php) reuse these same
	// process-wide stubs as write spies: when $GLOBALS['bricks_ie_pf_spy_mode']
	// is true, each call is recorded with its arguments in
	// $GLOBALS['bricks_ie_pf_write_log'] and a stubbed return value is
	// produced instead of throwing. Spy mode is off by default and every
	// bricks_ie_pf_reset() turns it off again, so all preflight tests keep
	// the strict throw-on-write behavior.
	// ==================================================================

	if ( ! function_exists( 'bricks_ie_pf_spy_mode' ) ) {
		function bricks_ie_pf_spy_mode() {
			return ! empty( $GLOBALS['bricks_ie_pf_spy_mode'] );
		}
	}

	if ( ! function_exists( 'bricks_ie_pf_guard_write' ) ) {
		function bricks_ie_pf_guard_write( $name, $args = array(), $spy_return = null ) {
			if ( bricks_ie_pf_spy_mode() ) {
				if ( isset( $GLOBALS['bricks_ie_preflight_test']['write_calls'] ) ) {
					$GLOBALS['bricks_ie_preflight_test']['write_calls'][] = $name;
				}
				$GLOBALS['bricks_ie_pf_write_log'][] = array( 'name' => $name, 'args' => $args );
				return $spy_return;
			}

			if ( isset( $GLOBALS['bricks_ie_preflight_test']['write_calls'] ) ) {
				$GLOBALS['bricks_ie_preflight_test']['write_calls'][] = $name;
			}
			throw new RuntimeException( 'Preflight must not write: ' . $name . '() was called.' );
		}
	}

	if ( ! function_exists( 'bricks_ie_pf_spy_option_store' ) ) {
		function bricks_ie_pf_spy_option_store() {
			if ( ! isset( $GLOBALS['bricks_ie_exporter_test']['options'] ) || ! is_array( $GLOBALS['bricks_ie_exporter_test']['options'] ) ) {
				$GLOBALS['bricks_ie_exporter_test']['options'] = array();
			}
			return 'options';
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $option, $value = null, $autoload = null ) {
			if ( bricks_ie_pf_spy_mode() ) {
				bricks_ie_pf_spy_option_store();
				$GLOBALS['bricks_ie_exporter_test']['options'][ $option ] = $value;
			}
			return bricks_ie_pf_guard_write( 'update_option', array( $option, $value ), true );
		}
	}

	if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = null, $deprecated = '', $autoload = null ) {
			if ( bricks_ie_pf_spy_mode() ) {
				bricks_ie_pf_spy_option_store();
				if ( array_key_exists( $option, $GLOBALS['bricks_ie_exporter_test']['options'] ) ) return false;
				$GLOBALS['bricks_ie_exporter_test']['options'][ $option ] = $value;
			}
			return bricks_ie_pf_guard_write( 'add_option', array( $option, $value ), true );
		}
	}

	if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
			if ( bricks_ie_pf_spy_mode() ) {
				bricks_ie_pf_spy_option_store();
				unset( $GLOBALS['bricks_ie_exporter_test']['options'][ $option ] );
				unset( $GLOBALS['bricks_ie_exporter_test']['options'][ $option ] );
			}
			return bricks_ie_pf_guard_write( 'delete_option', array( $option ), true );
		}
	}

	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( $postarr = array(), $wp_error = false ) {
			if ( bricks_ie_pf_spy_mode() ) {
				$next_post_id                       = isset( $GLOBALS['bricks_ie_pf_next_post_id'] ) ? (int) $GLOBALS['bricks_ie_pf_next_post_id'] : 101;
				$GLOBALS['bricks_ie_pf_next_post_id'] = $next_post_id + 1;
				return bricks_ie_pf_guard_write( 'wp_insert_post', array( $postarr ), $next_post_id );
			}
			bricks_ie_pf_guard_write( 'wp_insert_post', array( $postarr ) );
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( $postarr = array(), $wp_error = false ) {
			$post_id = is_array( $postarr ) && isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
			$result  = $post_id;
			if ( bricks_ie_pf_spy_mode() && array_key_exists( 'bricks_ie_pf_wp_update_post_result', $GLOBALS ) ) {
				$result = is_callable( $GLOBALS['bricks_ie_pf_wp_update_post_result'] )
					? call_user_func( $GLOBALS['bricks_ie_pf_wp_update_post_result'], $postarr, $wp_error )
					: $GLOBALS['bricks_ie_pf_wp_update_post_result'];
			}
			return bricks_ie_pf_guard_write( 'wp_update_post', array( $postarr, $wp_error ), $result );
		}
	}

	if ( ! function_exists( 'wp_delete_post' ) ) {
		function wp_delete_post( $post_id = 0, $force_delete = false ) {
			return bricks_ie_pf_guard_write( 'wp_delete_post', array( $post_id, $force_delete ), null );
		}
	}

	if ( ! function_exists( 'bricks_ie_pf_spy_meta_store' ) ) {
		function bricks_ie_pf_spy_meta_store( $post_id ) {
			if ( ! isset( $GLOBALS['bricks_ie_exporter_test']['post_meta'] ) || ! is_array( $GLOBALS['bricks_ie_exporter_test']['post_meta'] ) ) {
				$GLOBALS['bricks_ie_exporter_test']['post_meta'] = array();
			}
			if ( ! isset( $GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ] ) || ! is_array( $GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ] ) ) {
				$GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ] = array();
			}
		}
	}

	if ( ! function_exists( 'add_post_meta' ) ) {
		function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
			if ( bricks_ie_pf_spy_mode() ) {
				bricks_ie_pf_spy_meta_store( $post_id );
				$GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ][ $meta_key ] = $meta_value;
			}
			return bricks_ie_pf_guard_write( 'add_post_meta', array( $post_id, $meta_key, $meta_value ), true );
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
			$control = isset( $GLOBALS['bricks_ie_pf_meta_write_controls']['update_post_meta'] )
				? $GLOBALS['bricks_ie_pf_meta_write_controls']['update_post_meta']
				: array();
			if ( bricks_ie_pf_spy_mode() ) {
				bricks_ie_pf_spy_meta_store( $post_id );
				if ( empty( $control['unchanged'] ) ) {
					$GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ][ $meta_key ] = $meta_value;
				}
			}
			$return = array_key_exists( 'return', $control ) ? $control['return'] : true;
			return bricks_ie_pf_guard_write( 'update_post_meta', array( $post_id, $meta_key, $meta_value ), $return );
		}
	}

	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
			$control = isset( $GLOBALS['bricks_ie_pf_meta_write_controls']['delete_post_meta'] )
				? $GLOBALS['bricks_ie_pf_meta_write_controls']['delete_post_meta']
				: array();
			if ( bricks_ie_pf_spy_mode() ) {
				bricks_ie_pf_spy_meta_store( $post_id );
				if ( empty( $control['unchanged'] ) ) {
					unset( $GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ][ $meta_key ] );
				}
			}
			$return = array_key_exists( 'return', $control ) ? $control['return'] : true;
			return bricks_ie_pf_guard_write( 'delete_post_meta', array( $post_id, $meta_key ), $return );
		}
	}

	if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		if ( ! empty( $GLOBALS['bricks_ie_test_fail_set_transient'] ) ) {
			$GLOBALS['bricks_ie_test_fail_set_transient'] = false;
			return false;
		}
			if ( bricks_ie_pf_spy_mode() ) {
				if ( ! isset( $GLOBALS['bricks_ie_pf_transients'] ) || ! is_array( $GLOBALS['bricks_ie_pf_transients'] ) ) {
					$GLOBALS['bricks_ie_pf_transients'] = array();
				}
				$GLOBALS['bricks_ie_pf_transients'][ $transient ] = $value;
			}
			return bricks_ie_pf_guard_write( 'set_transient', array( $transient, $value, $expiration ), true );
		}
	}

	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $transient ) {
			if ( bricks_ie_pf_spy_mode() ) {
				if ( isset( $GLOBALS['bricks_ie_pf_transients'] ) && is_array( $GLOBALS['bricks_ie_pf_transients'] ) ) {
					unset( $GLOBALS['bricks_ie_pf_transients'][ $transient ] );
				}
			}
			return bricks_ie_pf_guard_write( 'delete_transient', array( $transient ), true );
		}
	}

	if ( ! function_exists( 'wp_cache_flush' ) ) {
		function wp_cache_flush() {
			return bricks_ie_pf_guard_write( 'wp_cache_flush', array(), true );
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( $target ) {
			return bricks_ie_pf_guard_write( 'wp_mkdir_p', array( $target ), true );
		}
	}

	if ( ! function_exists( 'wp_set_current_user' ) ) {
		function wp_set_current_user( $id, $name = '' ) {
			return bricks_ie_pf_guard_write( 'wp_set_current_user', array( $id, $name ), null );
		}
	}

	if ( ! function_exists( 'wp_insert_attachment' ) ) {
		function wp_insert_attachment( $args = array(), $file = false, $parent = 0, $wp_error = false ) {
			if ( bricks_ie_pf_spy_mode() ) {
				$next_post_id                         = isset( $GLOBALS['bricks_ie_pf_next_post_id'] ) ? (int) $GLOBALS['bricks_ie_pf_next_post_id'] : 101;
				$GLOBALS['bricks_ie_pf_next_post_id'] = $next_post_id + 1;
				return bricks_ie_pf_guard_write( 'wp_insert_attachment', array( $args, $file, $parent ), $next_post_id );
			}
			bricks_ie_pf_guard_write( 'wp_insert_attachment', array( $args, $file, $parent ) );
		}
	}

	if ( ! function_exists( 'wp_delete_attachment' ) ) {
		function wp_delete_attachment( $post_id, $force_delete = false ) {
			return bricks_ie_pf_guard_write( 'wp_delete_attachment', array( $post_id, $force_delete ), null );
		}
	}

	if ( ! function_exists( 'update_user_meta' ) ) {
		function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
			return bricks_ie_pf_guard_write( 'update_user_meta', array( $user_id, $meta_key, $meta_value ), true );
		}
	}
}

// ======================================================================
// Native Bricks stubs (only defined when the adapter tests have not
// already provided them; the API surface is compatible).
// ======================================================================

namespace Bricks {

	if ( ! class_exists( 'Bricks\Unified_Global_Transfer' ) ) {
		class Unified_Global_Transfer {
			const MANIFEST_SCHEMA  = 'bricks/unified-global-transfer';
			const MANIFEST_VERSION = 1;

			public static $type_ids       = array();
			public static $inspect_result = null;
			public static $import_result  = null;
			public static $calls          = array();

			public static function reset() {
				self::$type_ids       = array(
					'color-palettes',
					'theme-styles',
					'classes',
					'variables',
					'custom-fonts',
					'icon-manager',
					'breakpoints',
					'global-queries',
					'components',
					'templates',
					'settings',
					'custom-capabilities',
				);
				self::$inspect_result = null;
				self::$import_result  = null;
				self::$calls          = array();
			}

			public static function get_transfer_type_ids() {
				self::$calls[] = array( 'get_transfer_type_ids', array() );
				return self::$type_ids;
			}

			public static function list_export_items( $types = array() ) {
				self::$calls[] = array( 'list_export_items', array( $types ) );
				return array( 'types' => array() );
			}

			public static function export_package( $types, $items = array(), $payloads = array() ) {
				self::$calls[] = array( 'export_package', array( $types, $items, $payloads ) );
				return array();
			}

			public static function inspect_package_bytes( $bytes ) {
				self::$calls[] = array( 'inspect_package_bytes', array( strlen( $bytes ) ) );
			if ( null !== self::$inspect_result ) {
				$result = self::$inspect_result;
				if ( is_array( $result ) && isset( $result['zipHash'] ) && '__fixture__' === $result['zipHash'] ) {
					$result['zipHash']  = hash( 'sha256', $bytes );
					$result['zipBytes'] = strlen( $bytes );
				}
				return $result;
				}
				return array(
					'manifest' => array(
						'schema'  => self::MANIFEST_SCHEMA,
						'version' => self::MANIFEST_VERSION,
						'types'   => array(),
					),
					'zipHash'  => hash( 'sha256', $bytes ),
					'zipBytes' => strlen( $bytes ),
				);
			}

			public static function import_package_bytes( $bytes, $types, $items, $conflict_mode = 'skip', $conflict_decisions = array(), $import_images = false, $include_refresh = false ) {
				self::$calls[] = array( 'import_package_bytes', array( $types, $items, $conflict_mode ) );
				if ( null !== self::$import_result ) {
					return self::$import_result;
				}
				return array( 'results' => array() );
			}
		}
	}

	if ( ! class_exists( 'Bricks\Builder_Permissions' ) ) {
		class Builder_Permissions {
			public static $granted = array();

			public static function user_has_permission( $permission, $user_id = null ) {
				return ! empty( self::$granted[ $permission ] );
			}
		}
	}

	if ( ! class_exists( 'Bricks\Capabilities' ) ) {
		class Capabilities {
			public static $execute_code = false;
			public static $upload_svg   = false;

			public static function current_user_can_execute_code() {
				return self::$execute_code;
			}

			public static function current_user_can_upload_svg() {
				return self::$upload_svg;
			}
		}
	}
}

// ======================================================================
// Production classes, fixtures, and tests.
// ======================================================================

namespace {

	if ( ! class_exists( 'Bricks_IE_Archive_Validator' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-archive-validator.php';
	}

	if ( ! class_exists( 'Bricks_IE_Bricks_Transfer_Adapter' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-bricks-transfer-adapter.php';
	}

	if ( ! class_exists( 'Bricks_IE_Importer' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-bricks-importer.php';
	}

	// ==================================================================
	// Test helpers
	// ==================================================================

	function bricks_ie_pf_reset() {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && property_exists( $GLOBALS['wpdb'], 'query_result' ) ) {
			$GLOBALS['wpdb']->query_result = 1;
			if ( property_exists( $GLOBALS['wpdb'], 'queries' ) ) $GLOBALS['wpdb']->queries = array();
		}
		$GLOBALS['bricks_ie_preflight_test'] = array(
			'bricks_version' => '1.9.9-test',
			'home_url'       => 'https://target.example',
			'post_types'     => array( 'page', 'post', 'bricks_template' ),
			'existing_posts' => array(),
			'caps'           => array(
				'manage_options' => true,
				'upload_files'   => true,
			),
			'write_calls'    => array(),
		);

		$GLOBALS['bricks_ie_pf_filter_overrides'] = array();

		// Write-spy state (used by tests/test-importer-v1.php). Preflight
		// tests always run with spy mode off so every write attempt throws.
		$GLOBALS['bricks_ie_pf_spy_mode']     = false;
		$GLOBALS['bricks_ie_pf_write_log']    = array();
		$GLOBALS['bricks_ie_pf_next_post_id'] = 101;
		$GLOBALS['bricks_ie_pf_transients']   = array();
		$GLOBALS['bricks_ie_pf_meta_write_controls'] = array();
		$GLOBALS['bricks_ie_session_user']    = 42;
		unset( $GLOBALS['bricks_ie_pf_wp_update_post_result'] );

		// Keep the adapter-test capability stub in sync when it owns the
		// current_user_can() / wp_get_ability() definitions.
		$GLOBALS['bricks_ie_adapter_test'] = array(
			'caps'      => array(
				'manage_options' => true,
				'upload_files'   => true,
			),
			'abilities' => array(),
		);

		// Keep the exporter-test stubs in sync when they own get_posts(),
		// get_option(), or bricks_ie_get_option_names() in the shared
		// run.php process: preflight tests start from a clean target site
		// with the full option allowlist.
		$GLOBALS['bricks_ie_exporter_test'] = array(
			'options'      => array(),
			'posts'        => array(),
			'post_meta'    => array(),
			'option_names' => array(
				'bricks_global_settings',
				'bricks_theme_styles',
				'bricks_global_classes',
				'bricks_color_palette',
				'bricks_style_manager',
				'bricks_global_variables',
				'bricks_components',
				'bricks_global_queries',
				'bricks_global_elements',
				'bricks_breakpoints',
			),
			'post_types'   => array( 'page', 'bricks_template' ),
		);

		if ( class_exists( 'Bricks\Unified_Global_Transfer' ) ) {
			$class = 'Bricks\Unified_Global_Transfer';

			if ( is_callable( array( $class, 'reset' ) ) ) {
				call_user_func( array( $class, 'reset' ) );
			}

			$defaults = array(
				'type_ids'       => array(
					'color-palettes',
					'theme-styles',
					'classes',
					'variables',
					'custom-fonts',
					'icon-manager',
					'breakpoints',
					'global-queries',
					'components',
					'templates',
					'settings',
					'custom-capabilities',
				),
				'inspect_result' => null,
				'import_result'  => null,
				'calls'          => array(),
			);

			foreach ( $defaults as $property => $value ) {
				if ( property_exists( $class, $property ) ) {
					$class::${$property} = $value;
				}
			}
		}
	}

	function bricks_ie_pf_make_zip( array $files, $name ) {
		$directory = bricks_ie_test_temp_dir();
		$path      = $directory . DIRECTORY_SEPARATOR . $name;

		$zip = new ZipArchive();
		bricks_ie_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Could not create fixture zip.' );

		foreach ( $files as $entry_name => $content ) {
			bricks_ie_assert( true === $zip->addFromString( $entry_name, $content ), 'Could not add fixture entry: ' . $entry_name );
		}

		bricks_ie_assert( true === $zip->close(), 'Could not close fixture zip.' );

		return $path;
	}

	function bricks_ie_pf_native_package_bytes( array $types ) {
		$directory = bricks_ie_test_temp_dir();
		$path      = $directory . DIRECTORY_SEPARATOR . 'native-package-' . bin2hex( random_bytes( 4 ) ) . '.zip';

		$zip = new ZipArchive();
		bricks_ie_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Could not create native package fixture.' );

		$manifest = array(
			'schema'  => 'bricks/unified-global-transfer',
			'version' => 1,
			'types'   => $types,
		);

		bricks_ie_assert( true === $zip->addFromString( 'manifest.json', json_encode( $manifest ) ), 'Could not add native manifest.' );
		bricks_ie_assert( true === $zip->close(), 'Could not close native package fixture.' );

		$bytes = file_get_contents( $path );
		bricks_ie_assert( false !== $bytes && strlen( $bytes ) > 4, 'Could not read native package fixture.' );

		return $bytes;
	}

	function bricks_ie_pf_target_bricks_version() {
		$theme = wp_get_theme( 'bricks' );

		return $theme->exists() ? $theme->get( 'Version' ) : null;
	}

	function bricks_ie_pf_v1_archive( array $opts = array() ) {
		// Derive the fixture version from whichever wp_get_theme() stub owns
		// the shared process so the exact-version check passes everywhere.
		$target = bricks_ie_pf_target_bricks_version();

		if ( null === $target ) {
			$target = isset( $GLOBALS['bricks_ie_preflight_test']['bricks_version'] )
				? $GLOBALS['bricks_ie_preflight_test']['bricks_version']
				: '1.9.9-test';
		}

		$bricks_version = array_key_exists( 'bricks_version', $opts ) ? $opts['bricks_version'] : $target;

		$posts = isset( $opts['posts'] ) ? $opts['posts'] : array(
			array(
				'id'     => 7,
				'slug'   => 'home',
				'type'   => 'page',
				'status' => 'publish',
				'title'  => 'Home',
				'meta'   => array(
					'_bricks_page_content_2' => base64_encode( serialize( array( 'elements' => array() ) ) ),
				),
			),
		);

		$options = isset( $opts['options'] ) ? $opts['options'] : array(
			'bricks_global_settings' => array( 'postTypes' => array( 'page' ) ),
		);

		$manifest = array(
			'version'        => 1,
			'plugin_version' => '1.0.1',
			'generated_at'   => '2026-08-07T00:00:00+00:00',
			'site_url'       => 'https://source.example',
			'counts'         => array(
				'options' => count( $options ),
				'posts'   => count( $posts ),
			),
		);

		if ( null !== $bricks_version ) {
			$manifest['bricks_version'] = $bricks_version;
		}

		$files = array(
			'manifest.json' => json_encode( $manifest ),
		);

		foreach ( $options as $name => $value ) {
			$files[ 'options/' . $name . '.json' ] = json_encode( $value );
		}

		if ( ! empty( $posts ) ) {
			$index = array();
			foreach ( $posts as $post ) {
				$filename  = $post['type'] . '__' . $post['slug'] . '.json';
				$index[]   = array(
					'file' => $filename,
					'slug' => $post['slug'],
					'type' => $post['type'],
				);
				$files[ 'posts/' . $filename ] = json_encode( $post );
			}
			$files['posts/index.json'] = json_encode( $index );
		}

		return bricks_ie_pf_make_zip( $files, isset( $opts['name'] ) ? $opts['name'] : 'v1-archive.zip' );
	}

	function bricks_ie_pf_native_types_fixture() {
		return array(
			'classes'        => array(
				'id'        => 'classes',
				'label'     => 'Classes',
				'count'     => 2,
				'singleton' => false,
				'items'     => array(
					array( 'id' => 'c1', 'label' => 'Class One' ),
					array(
						'id'       => 'c2',
						'label'    => 'Class Two',
						'conflict' => array( 'message' => 'A class with the same name already exists on this site.' ),
					),
				),
			),
			'settings'       => array(
				'id'        => 'settings',
				'label'     => 'Settings',
				'count'     => 2,
				'singleton' => false,
				'items'     => array(
					array( 'id' => 'general', 'label' => 'General' ),
					array( 'id' => 'api-keys', 'label' => 'API keys' ),
				),
			),
			'breakpoints'    => array(
				'id'        => 'breakpoints',
				'label'     => 'Breakpoints',
				'count'     => 1,
				'singleton' => true,
				'items'     => array(
					array( 'id' => 'all', 'label' => 'Breakpoints' ),
				),
			),
			'global-queries' => array(
				'id'        => 'global-queries',
				'label'     => 'Global Queries',
				'count'     => 1,
				'singleton' => false,
				'items'     => array(
					array( 'id' => 'q1', 'label' => 'Query One' ),
				),
			),
		);
	}

	function bricks_ie_pf_v2_archive( array $opts = array() ) {
		$native_types = isset( $opts['native_types'] ) ? $opts['native_types'] : array();
		$warnings     = isset( $opts['warnings'] ) ? array_values( $opts['warnings'] ) : array();
		$omissions    = isset( $opts['omissions'] ) ? array_values( $opts['omissions'] ) : array();
		$posts        = isset( $opts['posts'] ) ? $opts['posts'] : array(
			array(
				'id'     => 11,
				'slug'   => 'about',
				'type'   => 'page',
				'status' => 'publish',
				'title'  => 'About',
				'meta'   => array(
					'_bricks_page_content_2' => array( 'elements' => array() ),
				),
			),
		);

		$native_bytes = '';
		$sha          = '';

		if ( ! empty( $native_types ) ) {
			$native_bytes = bricks_ie_pf_native_package_bytes( $native_types );
			$sha          = hash( 'sha256', $native_bytes );
		}

		$total_items = 0;
		foreach ( $native_types as $type ) {
			$total_items += isset( $type['items'] ) && is_array( $type['items'] ) ? count( $type['items'] ) : 0;
		}

		$bricks = array( 'version' => '2.4-beta2' );
		if ( ! empty( $native_types ) ) {
			$bricks['native_schema']  = 'bricks/unified-global-transfer';
			$bricks['native_version'] = 1;
			$bricks['package_sha256'] = $sha;
		}

		$manifest = array(
			'format'            => 'katsarov/bricks-import-export',
			'version'           => 2,
			'plugin_version'    => '1.1.0',
			'generated_at'      => '2026-08-07T00:00:00+00:00',
			'site_url'          => 'https://source.example',
			'wordpress_version' => '6.7',
			'php_version'       => '8.4.24',
			'bricks'            => $bricks,
			'domains'           => array(
				'native_bricks'       => ! empty( $native_types ),
				'posts'               => ! empty( $posts ),
				'template_conditions' => false,
				'media_files'         => false,
			),
			'counts'            => array(
				'native_types' => count( $native_types ),
				'native_items' => $total_items,
				'posts'        => count( $posts ),
			),
			'warnings'          => array(),
		);

		if ( isset( $opts['manifest_overrides'] ) && is_array( $opts['manifest_overrides'] ) ) {
			foreach ( $opts['manifest_overrides'] as $key => $value ) {
				$manifest[ $key ] = $value;
			}
		}

		$files = array(
			'manifest.json'                  => json_encode( $manifest ),
			'katsarov/export-warnings.json' => json_encode( array(
				'schema_version' => 2,
				'warnings'       => $warnings,
				'omissions'      => $omissions,
			) ),
		);

		if ( ! empty( $native_types ) ) {
			$files['bricks/package.zip']    = $native_bytes;
			$files['bricks/package.sha256'] = $sha;
		}

		if ( ! empty( $posts ) ) {
			$index = array();
			foreach ( $posts as $post ) {
				$filename = $post['type'] . '__' . $post['slug'] . '.json';
				$index[]  = array(
					'file' => $filename,
					'slug' => $post['slug'],
					'type' => $post['type'],
				);
				$files[ 'katsarov/posts/' . $filename ] = json_encode( $post );
			}
			$files['katsarov/posts/index.json'] = json_encode( $index );
		}

		return bricks_ie_pf_make_zip( $files, isset( $opts['name'] ) ? $opts['name'] : 'v2-archive.zip' );
	}

	function bricks_ie_pf_configure_v2_inspect() {
		$class = 'Bricks\Unified_Global_Transfer';

		$class::$inspect_result = array(
			'manifest' => array(
				'schema'  => 'bricks/unified-global-transfer',
				'version' => 1,
				'types'   => bricks_ie_pf_native_types_fixture(),
			),
			'zipHash'  => '__fixture__',
			'zipBytes' => 0,
		);
	}

	function bricks_ie_pf_native_call_methods() {
		$class = 'Bricks\Unified_Global_Transfer';
		$methods = array();

		foreach ( $class::$calls as $call ) {
			$methods[] = $call[0];
		}

		return $methods;
	}

	function bricks_ie_pf_find_by( array $list, $key, $value ) {
		foreach ( $list as $item ) {
			if ( is_array( $item ) && isset( $item[ $key ] ) && $item[ $key ] === $value ) {
				return $item;
			}
		}

		return null;
	}

	function bricks_ie_pf_assert_contains_substring( array $haystack, $needle, $message ) {
		foreach ( $haystack as $item ) {
			if ( is_string( $item ) && false !== strpos( $item, $needle ) ) {
				return;
			}
		}

		throw new RuntimeException( $message . ' Looked for "' . $needle . '" in: ' . var_export( $haystack, true ) . '.' );
	}

	function bricks_ie_pf_assert_report( $report, $context = '' ) {
		bricks_ie_assert( is_array( $report ), $context . ' Expected a report array, got: ' . ( is_wp_error( $report ) ? $report->get_error_code() . ': ' . $report->get_error_message() : var_export( $report, true ) ) );

		foreach ( array(
			'status',
			'format_version',
			'archive_hash',
			'source_environment',
			'target_environment',
			'native_domains',
			'posts',
			'conflicts',
			'dependencies',
			'omissions',
			'security_warnings',
			'warnings',
			'estimated_steps',
			'plan',
			'blocking',
		) as $key ) {
			bricks_ie_assert( array_key_exists( $key, $report ), $context . ' Report is missing key "' . $key . '".' );
		}
	}

	// ==================================================================
	// Schema version 1
	// ==================================================================

	bricks_ie_test(
		'preflight: v1 archive returns the normalized no-write report contract',
		function () {
			bricks_ie_pf_reset();

			$zip = bricks_ie_pf_v1_archive( array(
				'options' => array(
					'bricks_global_settings' => array( 'postTypes' => array( 'page' ) ),
					'not_allowed_option'     => array( 'x' => 1 ),
				),
			) );

			$target_bricks = bricks_ie_pf_target_bricks_version();

			$importer = new Bricks_IE_Importer();
			$report   = $importer->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v1 happy path:' );

			// The hardened validator flags schema 1 as a legacy format.
			bricks_ie_assert_same( 'warning', $report['status'], 'v1 legacy archive should be a warning.' );
			bricks_ie_assert_same( 1, $report['format_version'] );
			bricks_ie_assert_same( hash_file( 'sha256', $zip ), $report['archive_hash'] );
			bricks_ie_assert_same( array(), $report['blocking'] );

			bricks_ie_assert_same( 'https://source.example', $report['source_environment']['site_url'] );
			bricks_ie_assert_same( $target_bricks, $report['source_environment']['bricks_version'] );
			bricks_ie_assert_same( home_url(), $report['target_environment']['site_url'] );
			bricks_ie_assert_same( $target_bricks, $report['target_environment']['bricks_version'] );
			bricks_ie_assert_same( null, $report['target_environment']['native'] );

			bricks_ie_assert_same( 1, count( $report['posts'] ), 'One post should be planned.' );
			bricks_ie_assert_same( 'create', $report['posts'][0]['action'] );
			bricks_ie_assert_same( 'home', $report['posts'][0]['slug'] );
			bricks_ie_assert_same( array( '_bricks_page_content_2' ), $report['posts'][0]['meta_keys'] );
			bricks_ie_assert_same( array(), $report['posts'][0]['meta_rejected'] );

			$settings_domain = bricks_ie_pf_find_by( $report['native_domains'], 'domain', 'bricks_global_settings' );
			bricks_ie_assert( null !== $settings_domain, 'bricks_global_settings domain should be reported.' );
			bricks_ie_assert_same( 'raw_option', $settings_domain['transport'] );
			bricks_ie_assert_same( true, $settings_domain['selected'] );

			$foreign_domain = bricks_ie_pf_find_by( $report['native_domains'], 'domain', 'not_allowed_option' );
			bricks_ie_assert( null !== $foreign_domain, 'Foreign option domain should be reported.' );
			bricks_ie_assert_same( false, $foreign_domain['selected'] );

			bricks_ie_pf_assert_contains_substring( $report['omissions'], 'not_allowed_option', 'Non-allowlisted option should be an omission.' );

			bricks_ie_assert_same( array( 'bricks_global_settings' ), $report['plan']['options'] );
			bricks_ie_assert_same( 'legacy_v1', $report['plan']['path'] );
			bricks_ie_assert_same( 'skip', $report['plan']['conflict_mode'] );
			bricks_ie_assert_same( false, $report['plan']['import_images'] );

			// validate + 1 post + 1 option + remap/normalize/assets/signatures/cache.
			bricks_ie_assert_same( 8, $report['estimated_steps'] );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'], 'No write function may run during preflight.' );
		}
	);

	bricks_ie_test(
		'preflight: v1 keeps exact Bricks version validation',
		function () {
			bricks_ie_pf_reset();

			$mismatch_zip = bricks_ie_pf_v1_archive( array(
				'bricks_version' => '9.9.9-other',
				'name'           => 'v1-mismatch.zip',
			) );

			$importer = new Bricks_IE_Importer();
			$result   = $importer->preflight( $mismatch_zip );

			bricks_ie_assert_instance_of( 'WP_Error', $result, 'Version mismatch must be a WP_Error.' );
			bricks_ie_assert_same( 'bricks_version_mismatch', $result->get_error_code() );

			$missing_zip = bricks_ie_pf_v1_archive( array(
				'bricks_version' => null,
				'name'           => 'v1-missing-version.zip',
			) );

			$result = $importer->preflight( $missing_zip );

			bricks_ie_assert_instance_of( 'WP_Error', $result, 'Missing Bricks version must be a WP_Error.' );
			bricks_ie_assert_same( 'no_bricks_version', $result->get_error_code() );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v1 reports conflicts, missing post types, and meta allowlist violations',
		function () {
			bricks_ie_pf_reset();

			$GLOBALS['bricks_ie_preflight_test']['post_types']     = array( 'page', 'bricks_template' );
			$GLOBALS['bricks_ie_preflight_test']['existing_posts'] = array(
				'page|home' => (object) array(
					'ID'          => 42,
					'post_title'  => 'Home',
					'post_status' => 'publish',
				),
			);

			// The exporter-test get_posts() stub owns the shared process in
			// run.php and matches by post type only; give it the same single
			// existing page and no templates.
			$GLOBALS['bricks_ie_exporter_test']['posts'] = array(
				(object) array(
					'ID'          => 42,
					'post_type'   => 'page',
					'post_name'   => 'home',
					'post_title'  => 'Home',
					'post_status' => 'publish',
				),
			);

			$zip = bricks_ie_pf_v1_archive( array(
				'name'    => 'v1-conflicts.zip',
				'options' => array(),
				'posts'   => array(
					array(
						'id'     => 7,
						'slug'   => 'home',
						'type'   => 'page',
						'status' => 'publish',
						'title'  => 'Home',
						'meta'   => array(
							'_bricks_page_content_2' => base64_encode( serialize( array( 'elements' => array() ) ) ),
						),
					),
					array(
						'id'     => 8,
						'slug'   => 'widget-1',
						'type'   => 'product',
						'status' => 'publish',
						'title'  => 'Widget',
						'meta'   => array(),
					),
					array(
						// bricks_template (not page) so the shared-process
						// get_posts() stub, which matches by post type only,
						// does not collide this entry with the existing page.
						'id'     => 9,
						'slug'   => 'badmeta',
						'type'   => 'bricks_template',
						'status' => 'publish',
						'title'  => 'Bad Meta',
						'meta'   => array(
							'_evil_key'              => base64_encode( serialize( 'evil' ) ),
							'_bricks_page_content_2' => base64_encode( serialize( new stdClass() ) ),
							'_bricks_page_settings'  => '!!!not-base64!!!',
						),
					),
				),
			) );

			$importer = new Bricks_IE_Importer();
			$report   = $importer->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v1 conflicts:' );
			bricks_ie_assert_same( 'warning', $report['status'] );

			bricks_ie_assert_same( 'skip', $report['posts'][0]['action'], 'Existing page should be skipped under the default conflict policy.' );

			$conflict = bricks_ie_pf_find_by( $report['conflicts'], 'domain', 'posts' );
			bricks_ie_assert( null !== $conflict, 'Existing post should be reported as a conflict.' );
			bricks_ie_assert_same( 42, $conflict['id'] );
			bricks_ie_assert_same( 'home', $conflict['label'] );
			bricks_ie_assert_same( 'skip', $conflict['resolution'] );

			bricks_ie_assert_same( 'skip', $report['posts'][1]['action'] );
			bricks_ie_assert_same( 'post_type_missing', $report['posts'][1]['reason'] );
			bricks_ie_pf_assert_contains_substring( $report['omissions'], 'product', 'Missing CPT should be an omission.' );

			bricks_ie_assert( in_array( '_evil_key', $report['posts'][2]['meta_rejected'], true ), 'Non-allowlisted meta key must be rejected.' );
			bricks_ie_assert( in_array( '_bricks_page_content_2', $report['posts'][2]['meta_keys'], true ) );

			bricks_ie_pf_assert_contains_substring( $report['security_warnings'], '_evil_key', 'Non-allowlisted meta key must be a security warning.' );
			bricks_ie_pf_assert_contains_substring( $report['security_warnings'], 'serialized_object', 'Serialized objects must be flagged.' );
			bricks_ie_pf_assert_contains_substring( $report['security_warnings'], 'invalid_base64', 'Malformed base64 must be flagged.' );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v1 legacy fallback without the validator still plans the import',
		function () {
			bricks_ie_pf_reset();

			$zip = bricks_ie_pf_v1_archive( array( 'name' => 'v1-fallback.zip' ) );

			$importer = new Bricks_IE_Importer( array( 'disable_archive_validator' => true ) );
			$report   = $importer->preflight( $zip );

			bricks_ie_assert_instance_of( 'WP_Error', $report, 'The importer must fail closed without the validator.' );
			bricks_ie_assert_same( 'archive_validator_unavailable', $report->get_error_code() );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	// ==================================================================
	// Schema version 2
	// ==================================================================

	bricks_ie_test(
		'preflight: v2 derives an explicit native selection with default skip conflicts',
		function () {
			bricks_ie_pf_reset();
			bricks_ie_pf_configure_v2_inspect();

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => bricks_ie_pf_native_types_fixture(),
			) );

			$importer = new Bricks_IE_Importer();
			$report   = $importer->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v2 happy path:' );

			bricks_ie_assert_same( 2, $report['format_version'] );
			bricks_ie_assert_same( hash_file( 'sha256', $zip ), $report['archive_hash'] );
			bricks_ie_assert_same( '2.4-beta2', $report['source_environment']['bricks_version'] );
			bricks_ie_assert_same( array(), $report['blocking'] );

			// Settings + code-bearing domains and the sensitive exclusion warn.
			bricks_ie_assert_same( 'warning', $report['status'] );

			bricks_ie_assert_same( true, $report['target_environment']['native']['available'] );

			bricks_ie_assert_same( 4, count( $report['native_domains'] ), 'All four native domains should be reported.' );
			$classes_domain = bricks_ie_pf_find_by( $report['native_domains'], 'domain', 'classes' );
			bricks_ie_assert( null !== $classes_domain );
			bricks_ie_assert_same( 'native_package', $classes_domain['transport'] );
			bricks_ie_assert_same( true, $classes_domain['selected'] );
			bricks_ie_assert_same( 2, $classes_domain['count'] );
			bricks_ie_assert_same( 1, $classes_domain['conflicts'] );

			// Default conflict mode is skip.
			bricks_ie_assert_same( 1, count( $report['conflicts'] ), 'One native conflict should be reported.' );
			bricks_ie_assert_same( 'native:classes', $report['conflicts'][0]['domain'] );
			bricks_ie_assert_same( 'c2', $report['conflicts'][0]['id'] );
			bricks_ie_assert_same( 'skip', $report['conflicts'][0]['resolution'] );

			// Explicit selection derived from the inspected native manifest.
			bricks_ie_assert_same( array( 'classes', 'settings', 'breakpoints', 'global-queries' ), $report['plan']['native']['types'] );
			bricks_ie_assert_same( array( 'c1', 'c2' ), $report['plan']['native']['items']['classes'] );
			bricks_ie_assert_same( array(), $report['plan']['native']['items']['settings'] );
			bricks_ie_assert_same( array( 'all' ), $report['plan']['native']['items']['breakpoints'] );
			bricks_ie_assert_same( array( 'q1' ), $report['plan']['native']['items']['global-queries'] );

			// Sensitive settings are excluded without explicit authorization.
			bricks_ie_assert_same( array( 'general', 'api-keys' ), $report['plan']['native']['excluded_items']['settings'] );
			bricks_ie_pf_assert_contains_substring( $report['security_warnings'], 'api-keys', 'Sensitive settings exclusion must be a security warning.' );
			bricks_ie_pf_assert_contains_substring( $report['security_warnings'], 'global-queries', 'Code-bearing domains must be a security warning.' );

			bricks_ie_assert_same( 'skip', $report['plan']['conflict_mode'] );
			bricks_ie_assert_same( false, $report['plan']['import_images'] );
			bricks_ie_assert_same( false, $report['plan']['native']['import_images'] );
			bricks_ie_assert_same( 'absent', $report['plan']['sidecars']['template_conditions'] );

			// Plugin-owned post planning.
			bricks_ie_assert_same( 1, count( $report['posts'] ) );
			bricks_ie_assert_same( 'create', $report['posts'][0]['action'] );
			bricks_ie_assert_same( 'about', $report['posts'][0]['slug'] );

			// Structural dependencies satisfied by the native selection.
			bricks_ie_assert( null !== bricks_ie_pf_find_by( $report['dependencies'], 'type', 'classes' ), 'classes dependency should be satisfied.' );
			bricks_ie_assert( null !== bricks_ie_pf_find_by( $report['dependencies'], 'type', 'global-queries' ), 'global-queries dependency should be satisfied.' );

			bricks_ie_pf_assert_contains_substring( $report['omissions'], 'media', 'Media exclusion must be reported.' );

			// validate + 4 native types + 1 post + assets + cache.
			bricks_ie_assert_same( 8, $report['estimated_steps'] );

			// Native package inspected exactly once, never imported.
			$methods = bricks_ie_pf_native_call_methods();
			bricks_ie_assert_same( 1, count( array_keys( $methods, 'inspect_package_bytes', true ) ), 'inspect_package_bytes should run exactly once.' );
			bricks_ie_assert_same( 0, count( array_keys( $methods, 'import_package_bytes', true ) ), 'import_package_bytes must never run during preflight.' );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'], 'No write function may run during preflight.' );

			$authorized = ( new Bricks_IE_Importer() )->preflight( $zip, array( 'allow_sensitive_settings' => true ) );
			bricks_ie_assert_same( array( 'general', 'api-keys' ), $authorized['plan']['native']['items']['settings'] );
			bricks_ie_assert_same( array(), isset( $authorized['plan']['native']['excluded_items']['settings'] ) ? $authorized['plan']['native']['excluded_items']['settings'] : array() );
		}
	);

	bricks_ie_test( 'preflight: v2 propagates exporter sidecar warnings and omission messages', function () {
		bricks_ie_pf_reset();
		$zip = bricks_ie_pf_v2_archive( array(
			'name'       => 'v2-export-warnings.zip',
			'warnings'   => array( 'Exporter detected a portability risk.' ),
			'omissions'  => array(
				array( 'id' => 'sensitive_settings', 'message' => 'Sensitive settings were omitted by the exporter.' ),
			),
		) );

		$report = ( new Bricks_IE_Importer() )->preflight( $zip );
		bricks_ie_pf_assert_report( $report, 'v2 warning sidecar:' );
		bricks_ie_assert_same( 'warning', $report['status'] );
		bricks_ie_assert( in_array( 'Exporter detected a portability risk.', $report['warnings'], true ) );
		bricks_ie_assert( in_array( 'Sensitive settings were omitted by the exporter.', $report['omissions'], true ) );
		foreach ( $report['omissions'] as $omission ) bricks_ie_assert( is_string( $omission ), 'Preflight omissions must remain report-friendly strings.' );
	} );

	bricks_ie_test( 'preflight: sensitive choice is reported and bound into the confirmed plan', function () {
		bricks_ie_pf_reset();
		bricks_ie_pf_configure_v2_inspect();
		$zip = bricks_ie_pf_v2_archive( array( 'native_types' => bricks_ie_pf_native_types_fixture(), 'name' => 'v2-sensitive-plan.zip' ) );
		$importer = new Bricks_IE_Importer();
		$plain = $importer->preflight( $zip );
		$allowed = $importer->preflight( $zip, array( 'allow_sensitive_settings' => true ) );
		bricks_ie_assert_same( false, $plain['plan']['allow_sensitive_settings'] );
		bricks_ie_assert_same( true, $allowed['plan']['allow_sensitive_settings'] );
		bricks_ie_assert( in_array( 'api-keys', $plain['plan']['native']['excluded_items']['settings'], true ) );
		bricks_ie_assert( empty( $allowed['plan']['native']['excluded_items']['settings'] ) || ! in_array( 'api-keys', $allowed['plan']['native']['excluded_items']['settings'], true ) );
	} );

	bricks_ie_test(
		'preflight: v2 rejects replace without allow_overwrite and honors it with authorization',
		function () {
			bricks_ie_pf_reset();
			bricks_ie_pf_configure_v2_inspect();

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => bricks_ie_pf_native_types_fixture(),
				'name'         => 'v2-replace.zip',
			) );

			$importer = new Bricks_IE_Importer();

			$rejected = $importer->preflight( $zip, array( 'conflict_mode' => 'replace' ) );
			bricks_ie_assert_instance_of( 'WP_Error', $rejected, 'replace without allow_overwrite must be rejected.' );
			bricks_ie_assert_same( 'bricks_ie_overwrite_requires_authorization', $rejected->get_error_code() );

			$report = $importer->preflight( $zip, array(
				'conflict_mode'   => 'replace',
				'allow_overwrite' => true,
			) );

			bricks_ie_pf_assert_report( $report, 'v2 replace:' );
			bricks_ie_assert_same( 'replace', $report['plan']['conflict_mode'] );
			bricks_ie_assert_same( true, $report['plan']['allow_overwrite'] );
			bricks_ie_assert_same( 'replace', $report['conflicts'][0]['resolution'] );

			// Unknown conflict modes fall back to skip.
			$fallback = $importer->preflight( $zip, array( 'conflict_mode' => 'bogus' ) );
			bricks_ie_pf_assert_report( $fallback, 'v2 bogus conflict mode:' );
			bricks_ie_assert_same( 'skip', $fallback['plan']['conflict_mode'] );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v2 keeps import_images disabled even when requested',
		function () {
			bricks_ie_pf_reset();
			bricks_ie_pf_configure_v2_inspect();

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => bricks_ie_pf_native_types_fixture(),
				'name'         => 'v2-images.zip',
			) );

			$importer = new Bricks_IE_Importer();
			$report   = $importer->preflight( $zip, array( 'import_images' => true ) );

			bricks_ie_pf_assert_report( $report, 'v2 import_images:' );
			bricks_ie_assert_same( false, $report['plan']['import_images'] );
			bricks_ie_assert_same( false, $report['plan']['native']['import_images'] );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v2 blocks when the native target contract is unavailable',
		function () {
			bricks_ie_pf_reset();
			bricks_ie_pf_configure_v2_inspect();

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => bricks_ie_pf_native_types_fixture(),
				'name'         => 'v2-drift.zip',
			) );

			$adapter  = new Bricks_IE_Bricks_Transfer_Adapter( array( 'native_class' => 'Bricks\Does_Not_Exist' ) );
			$importer = new Bricks_IE_Importer( array( 'transfer_adapter' => $adapter ) );
			$report   = $importer->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v2 drifted contract:' );
			bricks_ie_assert_same( 'blocked', $report['status'] );
			bricks_ie_assert_same( 'native_contract_unavailable', $report['blocking'][0]['code'] );
			bricks_ie_assert_same( false, $report['target_environment']['native']['available'] );

			$methods = bricks_ie_pf_native_call_methods();
			bricks_ie_assert_same( 0, count( array_keys( $methods, 'inspect_package_bytes', true ) ), 'No inspection may run against a drifted contract.' );
			bricks_ie_assert_same( 0, count( array_keys( $methods, 'import_package_bytes', true ) ) );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v2 blocks when the native package schema does not match',
		function () {
			bricks_ie_pf_reset();

			$class                = 'Bricks\Unified_Global_Transfer';
			$class::$inspect_result = array(
				'manifest' => array(
					'schema'  => 'bricks/something-else',
					'version' => 1,
					'types'   => array(),
				),
				'zipHash'  => '__fixture__',
				'zipBytes' => 0,
			);

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => bricks_ie_pf_native_types_fixture(),
				'name'         => 'v2-bad-schema.zip',
			) );

			$importer = new Bricks_IE_Importer();
			$report   = $importer->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v2 native schema mismatch:' );
			bricks_ie_assert_same( 'blocked', $report['status'] );
			bricks_ie_assert_same( 'bricks_ie_native_result_invalid', $report['blocking'][0]['code'] );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v2 blocks fail-closed without the validator or the adapter',
		function () {
			bricks_ie_pf_reset();
			bricks_ie_pf_configure_v2_inspect();

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => bricks_ie_pf_native_types_fixture(),
				'name'         => 'v2-no-collaborators.zip',
			) );

			$without_validator = new Bricks_IE_Importer( array( 'disable_archive_validator' => true ) );
			$report            = $without_validator->preflight( $zip );

			bricks_ie_assert_instance_of( 'WP_Error', $report, 'The importer must fail closed without the validator.' );
			bricks_ie_assert_same( 'archive_validator_unavailable', $report->get_error_code() );

			$without_adapter = new Bricks_IE_Importer( array( 'disable_transfer_adapter' => true ) );
			$report          = $without_adapter->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v2 without adapter:' );
			bricks_ie_assert_same( 'blocked', $report['status'] );
			bricks_ie_assert_same( 'native_adapter_unavailable', $report['blocking'][0]['code'] );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'preflight: v2 blocks an archive with nothing to import',
		function () {
			bricks_ie_pf_reset();

			$zip = bricks_ie_pf_v2_archive( array(
				'native_types' => array(),
				'posts'        => array(),
				'name'         => 'v2-empty.zip',
			) );

			$importer = new Bricks_IE_Importer();
			$report   = $importer->preflight( $zip );

			bricks_ie_pf_assert_report( $report, 'v2 empty archive:' );
			bricks_ie_assert_same( 'blocked', $report['status'] );
			bricks_ie_assert_same( 'nothing_to_import', $report['blocking'][0]['code'] );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	// ==================================================================
	// General contract
	// ==================================================================

	bricks_ie_test(
		'preflight: missing archive returns file_not_found',
		function () {
			bricks_ie_pf_reset();

			$importer = new Bricks_IE_Importer();
			$result   = $importer->preflight( '/nonexistent/bricks-ie/archive.zip' );

			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'file_not_found', $result->get_error_code() );

			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		}
	);

	bricks_ie_test(
		'importer contract: public signatures remain backward compatible',
		function () {
			$class = new ReflectionClass( 'Bricks_IE_Importer' );

			// import_from_zip keeps its positional signature with the new
			// optional request parameter.
			$import = $class->getMethod( 'import_from_zip' );
			bricks_ie_assert_same( true, $import->isPublic() );
			bricks_ie_assert_same( 2, $import->getNumberOfParameters() );
			bricks_ie_assert_same( 1, $import->getNumberOfRequiredParameters() );
			bricks_ie_assert_same( array(), $import->getParameters()[1]->getDefaultValue() );

			// preflight is public with an optional request parameter.
			$preflight = $class->getMethod( 'preflight' );
			bricks_ie_assert_same( true, $preflight->isPublic() );
			bricks_ie_assert_same( 2, $preflight->getNumberOfParameters() );
			bricks_ie_assert_same( 1, $preflight->getNumberOfRequiredParameters() );
			bricks_ie_assert_same( array(), $preflight->getParameters()[1]->getDefaultValue() );

			// The dirty 1.0.2 AJAX surface is preserved.
			foreach ( array( 'start_import_session', 'run_import_session_step', 'upload' ) as $method ) {
				bricks_ie_assert_same( true, $class->hasMethod( $method ), 'Missing AJAX method: ' . $method );
				bricks_ie_assert_same( true, $class->getMethod( $method )->isPublic(), 'AJAX method must stay public: ' . $method );
			}
		}
	);

	// ==================================================================
	// Standalone execution (when not included by tests/run.php).
	// ==================================================================

	$bricks_ie_pf_self = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( $_SERVER['SCRIPT_FILENAME'] ) : false;

	if ( $bricks_ie_pf_self && realpath( __FILE__ ) === $bricks_ie_pf_self ) {
		$bricks_ie_pf_passed = 0;
		$bricks_ie_pf_failed = 0;

		foreach ( $GLOBALS['bricks_ie_tests'] as $bricks_ie_pf_name => $bricks_ie_pf_test ) {
			try {
				$bricks_ie_pf_test();
				$bricks_ie_pf_passed++;
				echo "PASS: {$bricks_ie_pf_name}\n";
			} catch ( Throwable $bricks_ie_pf_exception ) {
				$bricks_ie_pf_failed++;
				echo "FAIL: {$bricks_ie_pf_name}\n       " . $bricks_ie_pf_exception->getMessage() . "\n";
			}
		}

		$bricks_ie_pf_total = $bricks_ie_pf_passed + $bricks_ie_pf_failed;
		echo "\n{$bricks_ie_pf_total} tests, {$bricks_ie_pf_passed} passed, {$bricks_ie_pf_failed} failed.\n";

		if ( function_exists( 'bricks_ie_remove_test_temp_path' ) ) {
			foreach ( $GLOBALS['bricks_ie_test_temp_dirs'] as $bricks_ie_pf_dir ) {
				bricks_ie_remove_test_temp_path( $bricks_ie_pf_dir );
			}
		}

		exit( $bricks_ie_pf_failed > 0 ? 1 : 0 );
	}
}
