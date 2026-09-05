<?php
/**
 * Contract tests for the Bricks 2.4 native transfer adapter.
 *
 * These tests run against lightweight stubs of the native Bricks classes and
 * WordPress primitives; they never touch a real database, the real Bricks
 * theme, or the filesystem. The native stubs are intentionally injectable so
 * schema drift, missing methods, and permission failures can be simulated.
 */

// ======================================================================
// WordPress primitive stubs (process-wide, configurable per test).
// ======================================================================

namespace {
	if ( ! defined( 'MCP_MAX_ZIP_BYTES' ) ) {
		define( 'MCP_MAX_ZIP_BYTES', 1048576 );
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $capability ) {
			$caps = isset( $GLOBALS['bricks_ie_adapter_test']['caps'] ) ? $GLOBALS['bricks_ie_adapter_test']['caps'] : array();
			return ! empty( $caps[ $capability ] );
		}
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( $name ) {
			$abilities = isset( $GLOBALS['bricks_ie_adapter_test']['abilities'] ) ? $GLOBALS['bricks_ie_adapter_test']['abilities'] : array();
			return isset( $abilities[ $name ] ) ? $abilities[ $name ] : null;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}
}

// ======================================================================
// Native Bricks stubs.
// ======================================================================

namespace Bricks {

	/**
	 * Stub of the audited Bricks 2.4 unified transfer engine. Behavior is
	 * controlled through static properties so each test can configure results.
	 */
	class Unified_Global_Transfer {
		const MANIFEST_SCHEMA  = 'bricks/unified-global-transfer';
		const MANIFEST_VERSION = 1;

		public static $type_ids       = array();
		public static $list_result    = null;
		public static $export_result  = null;
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
			self::$list_result    = null;
			self::$export_result  = null;
			self::$inspect_result = null;
			self::$import_result  = null;
			self::$calls          = array();
		}

		public static function get_transfer_type_ids() {
			self::$calls[] = array( 'get_transfer_type_ids', array() );
			return self::$type_ids;
		}

		public static function get_mcp_transfer_max_zip_bytes() {
			return 1024 * 1024;
		}

		public static function list_export_items( $types = array() ) {
			self::$calls[] = array( 'list_export_items', array( $types ) );
			if ( null !== self::$list_result ) {
				return self::$list_result;
			}
			$items = array();
			foreach ( $types as $type ) {
				$items[] = array( 'id' => $type, 'items' => array( array( 'id' => 'classOne' ), array( 'id' => 'classTwo' ), array( 'id' => 'one' ), array( 'id' => 'icon1' ), array( 'id' => 't1' ), array( 'id' => 'general' ), array( 'id' => 'custom-code' ), array( 'id' => 'templates' ), array( 'id' => 'api-keys' ) ) );
			}
			return array( 'types' => $items );
		}

		/**
		 * Build a minimal but structurally valid ZIP package (one stored
		 * `manifest.json` entry) for default export results.
		 */
		public static function default_package_bytes() {
			$name    = 'manifest.json';
			$content = '{"schema":"bricks/unified-global-transfer","version":1}';

			$crc  = crc32( $content );
			$size = strlen( $content );

			$local = pack( 'V', 0x04034b50 )
				. pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0x21 )
				. pack( 'V', $crc ) . pack( 'V', $size ) . pack( 'V', $size )
				. pack( 'v', strlen( $name ) ) . pack( 'v', 0 )
				. $name . $content;

			$central = pack( 'V', 0x02014b50 )
				. pack( 'v', 20 ) . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0x21 )
				. pack( 'V', $crc ) . pack( 'V', $size ) . pack( 'V', $size )
				. pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'V', 0 ) . pack( 'V', 0 )
				. $name;

			$eocd = pack( 'V', 0x06054b50 )
				. pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 1 ) . pack( 'v', 1 )
				. pack( 'V', strlen( $central ) ) . pack( 'V', strlen( $local ) )
				. pack( 'v', 0 );

			return $local . $central . $eocd;
		}

		/**
		 * Build a complete, internally consistent export result payload.
		 */
		public static function valid_export_result( $bytes = null ) {
			if ( null === $bytes ) {
				$bytes = self::default_package_bytes();
			}
			return array(
				'filename'  => 'bricks-global-data.zip',
				'zipBase64' => base64_encode( $bytes ),
				'zipHash'   => hash( 'sha256', $bytes ),
				'zipBytes'  => strlen( $bytes ),
				'manifest'  => array(
					'schema'  => self::MANIFEST_SCHEMA,
					'version' => self::MANIFEST_VERSION,
				'types'   => array( array( 'id' => 'classes', 'items' => array( array( 'id' => 'classOne' ), array( 'id' => 'classTwo' ) ) ) ),
				),
			);
		}

		public static function export_package( $types, $items = array(), $payloads = array() ) {
			self::$calls[] = array( 'export_package', array( $types, $items, $payloads ) );
			if ( null !== self::$export_result ) {
				return self::$export_result;
			}
			return self::valid_export_result();
		}

		public static function inspect_package_bytes( $bytes ) {
			self::$calls[] = array( 'inspect_package_bytes', array( $bytes ) );
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
					'types'   => self::fixture_manifest_types(),
				),
				'zipHash'  => hash( 'sha256', $bytes ),
				'zipBytes' => strlen( $bytes ),
			);
		}

		public static function fixture_manifest_types() {
			$types = array();
			foreach ( self::$type_ids as $type ) {
				$types[] = array( 'id' => $type, 'items' => array( array( 'id' => 'classOne' ), array( 'id' => 'classTwo' ), array( 'id' => 'one' ), array( 'id' => 'icon1' ), array( 'id' => 't1' ), array( 'id' => 'general' ), array( 'id' => 'custom-code' ), array( 'id' => 'templates' ), array( 'id' => 'api-keys' ) ) );
			}
			return $types;
		}

		public static function import_package_bytes( $bytes, $types, $items, $conflict_mode = 'skip', $conflict_decisions = array(), $import_images = false, $include_refresh = false ) {
			self::$calls[] = array(
				'import_package_bytes',
				array(
					'bytes'              => $bytes,
					'types'              => $types,
					'items'              => $items,
					'conflict_mode'      => $conflict_mode,
					'conflict_decisions' => $conflict_decisions,
					'import_images'      => $import_images,
					'include_refresh'    => $include_refresh,
				),
			);
			if ( null !== self::$import_result ) {
				return self::$import_result;
			}
			return array( 'results' => array() );
		}
	}

	/**
	 * Stub of the Bricks builder-permissions gate.
	 */
	class Builder_Permissions {
		public static $granted = array();

		public static function user_has_permission( $permission, $user_id = null ) {
			return ! empty( self::$granted[ $permission ] );
		}
	}

	/**
	 * Stub of the Bricks capabilities gate.
	 */
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

// Drift variants used to simulate an unrecognized native contract.
namespace BricksIETest {

	class Drifted_Schema_Transfer extends \Bricks\Unified_Global_Transfer {
		const MANIFEST_SCHEMA = 'bricks/something-else';
	}

	class Drifted_Version_Transfer extends \Bricks\Unified_Global_Transfer {
		const MANIFEST_VERSION = 2;
	}
}

namespace {

	/**
	 * Reduced stub that is missing required public methods, so it can be
	 * injected to simulate a drifted native contract.
	 */
	class Bricks_IE_Test_Missing_Method_Transfer {
		const MANIFEST_SCHEMA  = 'bricks/unified-global-transfer';
		const MANIFEST_VERSION = 1;

		public static function get_transfer_type_ids() {
			return array();
		}

		public static function list_export_items( $types = array() ) {
			return array( 'types' => array() );
		}
		// export_package / inspect_package_bytes / import_package_bytes omitted.
	}

	class Bricks_IE_Test_No_Type_Method_Transfer {
		const MANIFEST_SCHEMA = 'bricks/unified-global-transfer';
		const MANIFEST_VERSION = 1;
		public static function list_export_items( $types = array() ) { return array( 'types' => array() ); }
		public static function export_package( $types, $items = array(), $payloads = array() ) { return \Bricks\Unified_Global_Transfer::valid_export_result(); }
		public static function inspect_package_bytes( $bytes ) { return array( 'manifest' => array( 'schema' => self::MANIFEST_SCHEMA, 'version' => 1, 'types' => array() ), 'zipHash' => hash( 'sha256', $bytes ), 'zipBytes' => strlen( $bytes ) ); }
		public static function import_package_bytes( $bytes, $types, $items, $conflict_mode = 'skip', $conflict_decisions = array(), $import_images = false ) { return array( 'results' => array() ); }
	}

	class Bricks_IE_Test_Operations_Missing_Transfer {
		const MANIFEST_SCHEMA = 'bricks/unified-global-transfer';
		const MANIFEST_VERSION = 1;
		const MCP_MAX_ZIP_BYTES = 4096;
	}

	class Bricks_IE_Test_Fallback_Limit_Transfer {
		const MANIFEST_SCHEMA = 'bricks/unified-global-transfer';
		const MANIFEST_VERSION = 1;
		const MCP_MAX_ZIP_BYTES = 4096;
		public static function get_transfer_type_ids() { return \Bricks\Unified_Global_Transfer::get_transfer_type_ids(); }
		public static function list_export_items( $types = array() ) { return \Bricks\Unified_Global_Transfer::list_export_items( $types ); }
		public static function export_package( $types, $items = array() ) { return \Bricks\Unified_Global_Transfer::export_package( $types, $items ); }
		public static function inspect_package_bytes( $bytes ) { return \Bricks\Unified_Global_Transfer::inspect_package_bytes( $bytes ); }
		public static function import_package_bytes( $bytes, $types, $items ) { return array( 'results' => array() ); }
	}

	/**
	 * Minimal ability stub implementing the safe callable invocation surface.
	 */
	class Bricks_IE_Test_Stub_Ability {
		public $name;
		public $result;
		public $last_input;

		public function __construct( $name, $result ) {
			$this->name   = $name;
			$this->result = $result;
		}

		public function execute( $input = null ) {
			$this->last_input = $input;
			if ( is_callable( $this->result ) ) {
				return call_user_func( $this->result, $input );
			}
			return $this->result;
		}
	}

	/**
	 * Ability stub whose execute() is private: visible to method_exists() but
	 * not invocable from the adapter's scope. The adapter must never route to
	 * it.
	 */
	class Bricks_IE_Test_Private_Execute_Ability {
		public $name;

		public function __construct( $name ) {
			$this->name = $name;
		}

		private function execute( $input = null ) {
			throw new RuntimeException( 'A private execute() must never be invoked.' );
		}
	}

	/**
	 * Ability stub without any execute() method at all.
	 */
	class Bricks_IE_Test_No_Execute_Ability {
		public $name;

		public function __construct( $name ) {
			$this->name = $name;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-bricks-transfer-adapter.php';

	// ==================================================================
	// Test helpers
	// ==================================================================

	function bricks_ie_adapter_test_reset() {
		$GLOBALS['bricks_ie_adapter_test'] = array(
			'caps'      => array(
				'manage_options' => true,
				'upload_files'   => true,
			),
			'abilities' => array(),
		);

		\Bricks\Unified_Global_Transfer::reset();

		\Bricks\Builder_Permissions::$granted = array(
			'edit_color_palettes'        => true,
			'access_theme_styles'        => true,
			'access_class_manager'       => true,
			'create_global_classes'      => true,
			'edit_global_classes'        => true,
			'access_variable_manager'    => true,
			'access_font_manager'        => true,
			'access_icon_manager'        => true,
			'access_breakpoints_manager' => true,
			'access_query_manager'       => true,
			'import_export_components'   => true,
			'import_export_templates'    => true,
		);

		\Bricks\Capabilities::$execute_code = true;
		\Bricks\Capabilities::$upload_svg   = true;
	}

	function bricks_ie_adapter_test_adapter( array $options = array() ) {
		return new Bricks_IE_Bricks_Transfer_Adapter( $options );
	}

	function bricks_ie_adapter_test_last_call( $method ) {
		$calls = \Bricks\Unified_Global_Transfer::$calls;
		for ( $i = count( $calls ) - 1; $i >= 0; $i-- ) {
			if ( $calls[ $i ][0] === $method ) {
				return $calls[ $i ][1];
			}
		}
		return null;
	}

	function bricks_ie_adapter_test_full_selection() {
		return array(
			'types' => array( 'classes' ),
			'items' => array( 'classes' => array( 'classOne', 'classTwo' ) ),
		);
	}

	// ==================================================================
	// Capability detection
	// ==================================================================

	bricks_ie_test(
		'adapter: detect_capabilities reports a valid audited contract',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();
			$report  = $adapter->detect_capabilities();

			bricks_ie_assert_same( true, $report['available'], 'contract should be available' );
			bricks_ie_assert_same( 'bricks/unified-global-transfer', $report['schema'] );
			bricks_ie_assert_same( true, $report['schema_valid'] );
			bricks_ie_assert_same( 1, $report['version'] );
			bricks_ie_assert_same( true, $report['version_valid'] );
			bricks_ie_assert_same( true, $report['methods_valid'] );
			bricks_ie_assert_same( true, $report['types_valid'] );
			bricks_ie_assert_same( array(), $report['errors'] );
			bricks_ie_assert_same( false, $report['use_abilities'], 'no abilities registered in this scenario' );
		}
	);

	bricks_ie_test(
		'adapter: detect_capabilities flags schema and version drift',
		function () {
			bricks_ie_adapter_test_reset();

			$drifted = bricks_ie_adapter_test_adapter( array( 'native_class' => 'BricksIETest\Drifted_Schema_Transfer' ) );
			$report  = $drifted->detect_capabilities();
			bricks_ie_assert_same( false, $report['available'] );
			bricks_ie_assert_same( false, $report['schema_valid'] );
			bricks_ie_assert( in_array( 'schema_mismatch', $report['errors'], true ) );

			$drifted_version = bricks_ie_adapter_test_adapter( array( 'native_class' => 'BricksIETest\Drifted_Version_Transfer' ) );
			$report          = $drifted_version->detect_capabilities();
			bricks_ie_assert_same( false, $report['available'] );
			bricks_ie_assert_same( false, $report['version_valid'] );
			bricks_ie_assert( in_array( 'version_mismatch', $report['errors'], true ) );
		}
	);

	bricks_ie_test(
		'adapter: detect_capabilities flags a missing native class',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'Bricks\Does_Not_Exist' ) );
			$report  = $adapter->detect_capabilities();

			bricks_ie_assert_same( false, $report['available'] );
			bricks_ie_assert( in_array( 'native_class_missing', $report['errors'], true ) );
		}
	);

	bricks_ie_test(
		'adapter: detect_capabilities reports registered abilities when present',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
				'bricks/list-transfer-items'      => new Bricks_IE_Test_Stub_Ability( 'bricks/list-transfer-items', array( 'types' => array() ) ),
				'bricks/export-transfer-package'  => new Bricks_IE_Test_Stub_Ability( 'bricks/export-transfer-package', array() ),
				'bricks/inspect-transfer-package' => new Bricks_IE_Test_Stub_Ability( 'bricks/inspect-transfer-package', array() ),
				'bricks/import-transfer-package'  => new Bricks_IE_Test_Stub_Ability( 'bricks/import-transfer-package', array() ),
			);

			$adapter = bricks_ie_adapter_test_adapter();
			$report  = $adapter->detect_capabilities();

			bricks_ie_assert_same( true, $report['use_abilities'] );
			bricks_ie_assert_same( true, $report['abilities']['bricks/import-transfer-package'] );
		}
	);

	bricks_ie_test( 'adapter: complete callable abilities cover missing static operations', function () {
		bricks_ie_adapter_test_reset();
		$bytes = \Bricks\Unified_Global_Transfer::default_package_bytes();
		$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
			'bricks/list-transfer-items' => new Bricks_IE_Test_Stub_Ability( 'list', array( 'types' => array( array( 'id' => 'classes', 'items' => array( array( 'id' => 'classOne' ), array( 'id' => 'classTwo' ) ) ) ) ) ),
			'bricks/export-transfer-package' => new Bricks_IE_Test_Stub_Ability( 'export', \Bricks\Unified_Global_Transfer::valid_export_result( $bytes ) ),
			'bricks/inspect-transfer-package' => new Bricks_IE_Test_Stub_Ability( 'inspect', function ( $input ) { $decoded = base64_decode( $input['zipBase64'], true ); return array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( array( 'id' => 'classes', 'items' => array( array( 'id' => 'classOne' ), array( 'id' => 'classTwo' ) ) ) ) ), 'zipHash' => hash( 'sha256', $decoded ), 'zipBytes' => strlen( $decoded ) ); } ),
			'bricks/import-transfer-package' => new Bricks_IE_Test_Stub_Ability( 'import', array( 'results' => array() ) ),
		);
		$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'Bricks_IE_Test_Operations_Missing_Transfer' ) );
		$report = $adapter->detect_capabilities();
		bricks_ie_assert_same( false, $report['methods_valid'] );
		bricks_ie_assert_same( true, $report['routes_valid'] );
		bricks_ie_assert_same( true, $report['available'] );
		bricks_ie_assert_same( 'ability', $adapter->list_items( array( 'classes' ) )['via'] );
		bricks_ie_assert_same( 'ability', $adapter->export_package( bricks_ie_adapter_test_full_selection() )['via'] );
		bricks_ie_assert_same( 'ability', $adapter->inspect_package( $bytes )['via'] );
		bricks_ie_assert_same( 'ability', $adapter->import_package( $bytes, bricks_ie_adapter_test_full_selection() )['via'] );
	} );

	bricks_ie_test( 'adapter: callable list ability supplies KNOWN_TYPE_IDS without get_transfer_type_ids', function () {
		bricks_ie_adapter_test_reset();
		$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/list-transfer-items'] = new Bricks_IE_Test_Stub_Ability( 'list', array( 'types' => array( array( 'id' => 'classes', 'items' => array( array( 'id' => 'classOne' ), array( 'id' => 'classTwo' ) ) ) ) ) );
		$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'Bricks_IE_Test_No_Type_Method_Transfer' ) );
		$report = $adapter->detect_capabilities();
		bricks_ie_assert_same( true, $report['types_valid'] );
		bricks_ie_assert_same( Bricks_IE_Bricks_Transfer_Adapter::KNOWN_TYPE_IDS, $report['type_ids'] );
		bricks_ie_assert( ! is_wp_error( $adapter->export_package( bricks_ie_adapter_test_full_selection() ) ) );
	} );

	// ==================================================================
	// Schema drift / fail-closed behavior on operations
	// ==================================================================

	bricks_ie_test(
		'adapter: operations fail closed on schema drift',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'BricksIETest\Drifted_Schema_Transfer' ) );

			$result = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_schema_mismatch', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: operations fail closed on version drift',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'BricksIETest\Drifted_Version_Transfer' ) );

			$result = $adapter->import_package( 'bytes', bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_version_mismatch', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: operations fail closed when a required method is missing',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'Bricks_IE_Test_Missing_Method_Transfer' ) );

			$result = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_method_missing', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: operations fail closed when the native class is missing',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'Bricks\Does_Not_Exist' ) );

			$result = $adapter->list_items();
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_unavailable', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: unsupported or native-unavailable type ids are rejected',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$unknown = $adapter->export_package(
				array(
					'types' => array( 'not-a-real-type' ),
					'items' => array( 'not-a-real-type' => array( 'x' ) ),
				)
			);
			bricks_ie_assert_instance_of( 'WP_Error', $unknown );
			bricks_ie_assert_same( 'bricks_ie_unsupported_transfer_type', $unknown->get_error_code() );

			// Remove a type from the native engine to simulate drift.
			\Bricks\Unified_Global_Transfer::$type_ids = array( 'classes' );
			$missing                                   = $adapter->export_package(
				array(
					'types' => array( 'templates' ),
					'items' => array( 'templates' => array( 't1' ) ),
				)
			);
			bricks_ie_assert_instance_of( 'WP_Error', $missing );
			bricks_ie_assert_same( 'bricks_ie_native_type_unavailable', $missing->get_error_code() );
		}
	);

	// ==================================================================
	// Success paths (native fallback)
	// ==================================================================

	bricks_ie_test(
		'adapter: list_items succeeds via the native fallback and normalizes the result',
		function () {
			bricks_ie_adapter_test_reset();
			\Bricks\Unified_Global_Transfer::$list_result = array(
				'types' => array( array( 'id' => 'classes', 'items' => array() ) ),
			);

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->list_items( array( 'classes' ) );

			bricks_ie_assert( ! is_wp_error( $result ), 'list_items should succeed' );
			bricks_ie_assert_same( 'native', $result['via'] );
			bricks_ie_assert_same( 1, count( $result['types'] ) );
		}
	);

	bricks_ie_test(
		'adapter: export_package succeeds and returns a normalized package',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->export_package( bricks_ie_adapter_test_full_selection() );

			bricks_ie_assert( ! is_wp_error( $result ), 'export_package should succeed' );
			bricks_ie_assert_same( 'native', $result['via'] );
			bricks_ie_assert_same( 'bricks-global-data.zip', $result['filename'] );
			bricks_ie_assert( '' !== $result['zip_base64'] );
			bricks_ie_assert( '' !== $result['zip_hash'] );
			bricks_ie_assert_same( true, is_array( $result['manifest'] ) );

			// The normalized package must be internally consistent: decodable
			// ZIP bytes whose hash and count match the declared values.
			$bytes = base64_decode( $result['zip_base64'], true );
			bricks_ie_assert( is_string( $bytes ) && '' !== $bytes, 'zip_base64 must decode' );
			bricks_ie_assert_same( "PK\x03\x04", substr( $bytes, 0, 4 ), 'decoded bytes must carry the ZIP signature' );
			bricks_ie_assert_same( hash( 'sha256', $bytes ), $result['zip_hash'] );
			bricks_ie_assert_same( strlen( $bytes ), $result['zip_bytes'] );
			bricks_ie_assert_same( 'bricks/unified-global-transfer', $result['manifest']['schema'] );
			bricks_ie_assert_same( 1, $result['manifest']['version'] );

			$call = bricks_ie_adapter_test_last_call( 'export_package' );
			bricks_ie_assert_same( array( 'classes' ), $call[0] );
			bricks_ie_assert_same( array( 'classOne', 'classTwo' ), $call[1]['classes'] );
		}
	);

	bricks_ie_test(
		'adapter: inspect_package succeeds and returns a normalized manifest',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();
			$bytes   = \Bricks\Unified_Global_Transfer::default_package_bytes();
			$result  = $adapter->inspect_package( $bytes );

			bricks_ie_assert( ! is_wp_error( $result ), 'inspect_package should succeed' );
			bricks_ie_assert_same( 'native', $result['via'] );
			bricks_ie_assert_same( hash( 'sha256', $bytes ), $result['zip_hash'] );
			bricks_ie_assert_same( strlen( $bytes ), $result['zip_bytes'] );
		}
	);

	bricks_ie_test(
		'adapter: import_package succeeds with safe defaults (skip, no images)',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->import_package( 'package-bytes', bricks_ie_adapter_test_full_selection() );

			bricks_ie_assert( ! is_wp_error( $result ), 'import_package should succeed' );
			bricks_ie_assert_same( true, $result['success'] );
			bricks_ie_assert_same( 'native', $result['via'] );

			$call = bricks_ie_adapter_test_last_call( 'import_package_bytes' );
			bricks_ie_assert_same( 'skip', $call['conflict_mode'], 'default conflict mode must be skip' );
			bricks_ie_assert_same( false, $call['import_images'], 'remote image import must default to false' );
		}
	);

	bricks_ie_test( 'adapter: native import success respects explicit booleans and omitted fallback', function () {
		$adapter = bricks_ie_adapter_test_adapter();
		foreach ( array( false, true, null ) as $success ) {
			bricks_ie_adapter_test_reset();
			$result = array( 'results' => array( 'classes' => array() ) );
			if ( null !== $success ) {
				$result['success'] = $success;
			}
			\Bricks\Unified_Global_Transfer::$import_result = $result;

			$normalized = $adapter->import_package( 'package-bytes', bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert( ! is_wp_error( $normalized ) );
			bricks_ie_assert_same( null === $success ? true : $success, $normalized['success'] );
		}
	} );

	bricks_ie_test( 'adapter: native import rejects a non-boolean explicit success', function () {
		bricks_ie_adapter_test_reset();
		\Bricks\Unified_Global_Transfer::$import_result = array(
			'success' => 1,
			'results' => array(),
		);

		$result = bricks_ie_adapter_test_adapter()->import_package( 'package-bytes', bricks_ie_adapter_test_full_selection() );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'bricks_ie_native_result_invalid', $result->get_error_code() );
		bricks_ie_assert_same( 'import', $result->get_error_data()['operation'] );
		bricks_ie_assert_same( 'success_invalid', $result->get_error_data()['reason'] );
	} );

	// ==================================================================
	// Permission matrix
	// ==================================================================

	bricks_ie_test(
		'adapter: manage_options baseline is required',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->export_package( bricks_ie_adapter_test_full_selection() );

			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: a missing Bricks manager permission blocks the type',
		function () {
			bricks_ie_adapter_test_reset();
			\Bricks\Builder_Permissions::$granted['access_class_manager'] = false;

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->export_package( bricks_ie_adapter_test_full_selection() );

			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: code-bearing types require the code-execution capability',
		function () {
			bricks_ie_adapter_test_reset();
			\Bricks\Capabilities::$execute_code = false;

			$adapter   = bricks_ie_adapter_test_adapter();
			foreach ( array( 'global-queries', 'components' ) as $type ) {
				$selection = array( 'types' => array( $type ), 'items' => array( $type => array( 'one' ) ) );
				$result = $adapter->export_package( $selection );
				bricks_ie_assert_instance_of( 'WP_Error', $result );
				bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );
				\Bricks\Capabilities::$execute_code = true;
				$result = $adapter->export_package( $selection );
				bricks_ie_assert( ! is_wp_error( $result ), $type . ' static export should require execute_code and then succeed.' );
				\Bricks\Capabilities::$execute_code = false;
			}
		}
	);

	bricks_ie_test( 'adapter: ability-backed components and global queries bypass execute_code', function () {
		bricks_ie_adapter_test_reset();
		\Bricks\Capabilities::$execute_code = false;
		$valid = \Bricks\Unified_Global_Transfer::valid_export_result();
		foreach ( array( 'global-queries', 'components' ) as $type ) {
			$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/export-transfer-package'] = new Bricks_IE_Test_Stub_Ability( 'export', $valid );
			$result = bricks_ie_adapter_test_adapter()->export_package( array( 'types' => array( $type ), 'items' => array( $type => array( 'one' ) ) ) );
			bricks_ie_assert( ! is_wp_error( $result ), $type . ' ability route should not require execute_code.' );
		}
	} );

	bricks_ie_test(
		'adapter: icon-manager import requires upload_files and SVG upload',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter   = bricks_ie_adapter_test_adapter();
			$selection = array(
				'types' => array( 'icon-manager' ),
				'items' => array( 'icon-manager' => array( 'icon1' ) ),
			);

			$GLOBALS['bricks_ie_adapter_test']['caps']['upload_files'] = false;
			$result                                                    = $adapter->import_package( 'bytes', $selection );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );

			$GLOBALS['bricks_ie_adapter_test']['caps']['upload_files'] = true;
			\Bricks\Capabilities::$upload_svg                          = false;
			$result                                                    = $adapter->import_package( 'bytes', $selection );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );

			\Bricks\Capabilities::$upload_svg = true;
			$result                           = $adapter->import_package( 'bytes', $selection );
			bricks_ie_assert( ! is_wp_error( $result ), 'icon-manager import should succeed with upload+svg' );
		}
	);

	bricks_ie_test(
		'adapter: fails closed when a required permission cannot be evaluated',
		function () {
			bricks_ie_adapter_test_reset();
			// Point the adapter at a permissions class that does not exist.
			$adapter = bricks_ie_adapter_test_adapter( array( 'permissions_class' => 'Bricks\Does_Not_Exist_Permissions' ) );

			$result = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_permission_unevaluable', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: classes import requires create or edit global classes',
		function () {
			bricks_ie_adapter_test_reset();
			\Bricks\Builder_Permissions::$granted['create_global_classes'] = false;
			\Bricks\Builder_Permissions::$granted['edit_global_classes']   = false;

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->import_package( 'bytes', bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );

			\Bricks\Builder_Permissions::$granted['edit_global_classes'] = true;
			$result                                                      = $adapter->import_package( 'bytes', bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert( ! is_wp_error( $result ), 'classes import should succeed with edit_global_classes' );
		}
	);

	// ==================================================================
	// Sensitive settings policy
	// ==================================================================

	bricks_ie_test(
		'adapter: sensitive settings are rejected without explicit authorization',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter   = bricks_ie_adapter_test_adapter();
			$selection = array(
				'types' => array( 'settings' ),
				'items' => array( 'settings' => array( 'api-keys' ) ),
			);

			$result = $adapter->export_package( $selection );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_sensitive_settings_requires_authorization', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: sensitive settings pass with authorization and manage_options',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter   = bricks_ie_adapter_test_adapter();
			$selection = array(
				'types' => array( 'settings' ),
				'items' => array( 'settings' => array( 'custom-code' ) ),
			);

			$result = $adapter->export_package( $selection, array( 'allow_sensitive_settings' => true ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'authorized sensitive export should succeed' );
		}
	);

	bricks_ie_test(
		'adapter: template passwords (templates settings tab) are treated as sensitive',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter   = bricks_ie_adapter_test_adapter();
			$selection = array(
				'types' => array( 'settings' ),
				'items' => array( 'settings' => array( 'templates' ) ),
			);

			$result = $adapter->import_package( 'bytes', $selection );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_sensitive_settings_requires_authorization', $result->get_error_code() );

			$result = $adapter->import_package( 'bytes', $selection, array( 'allow_sensitive_settings' => true ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'authorized templates-settings import should succeed' );
		}
	);

	bricks_ie_test(
		'adapter: non-sensitive settings tabs do not require authorization',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter   = bricks_ie_adapter_test_adapter();
			$selection = array(
				'types' => array( 'settings' ),
				'items' => array( 'settings' => array( 'general' ) ),
			);

			$result = $adapter->export_package( $selection );
			bricks_ie_assert( ! is_wp_error( $result ), 'non-sensitive settings export should succeed' );
		}
	);

	bricks_ie_test( 'adapter: settings general import is denied by default and admitted only when authorized', function () {
		bricks_ie_adapter_test_reset();
		$adapter = bricks_ie_adapter_test_adapter();
		$selection = array(
			'types' => array( 'settings' ),
			'items' => array( 'settings' => array( 'general' ) ),
		);

		$denied = $adapter->import_package( 'bytes', $selection );
		bricks_ie_assert_instance_of( 'WP_Error', $denied );
		bricks_ie_assert_same( 'bricks_ie_sensitive_settings_requires_authorization', $denied->get_error_code() );
		bricks_ie_assert_same( null, bricks_ie_adapter_test_last_call( 'import_package_bytes' ), 'Denied settings must not reach native import.' );

		$allowed = $adapter->import_package( 'bytes', $selection, array( 'allow_sensitive_settings' => true ) );
		bricks_ie_assert( ! is_wp_error( $allowed ), 'Explicitly authorized general settings import should succeed.' );
		$call = bricks_ie_adapter_test_last_call( 'import_package_bytes' );
		bricks_ie_assert_same( array( 'general' ), $call['items']['settings'] );
	} );

	// ==================================================================
	// Overwrite policy
	// ==================================================================

	bricks_ie_test(
		'adapter: replace conflict mode requires explicit overwrite authorization',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$result = $adapter->import_package(
				'bytes',
				bricks_ie_adapter_test_full_selection(),
				array( 'conflict_mode' => 'replace' )
			);
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_overwrite_requires_authorization', $result->get_error_code() );

			$result = $adapter->import_package(
				'bytes',
				bricks_ie_adapter_test_full_selection(),
				array( 'conflict_mode' => 'replace', 'allow_overwrite' => true )
			);
			bricks_ie_assert( ! is_wp_error( $result ), 'authorized replace should succeed' );
			$call = bricks_ie_adapter_test_last_call( 'import_package_bytes' );
			bricks_ie_assert_same( 'replace', $call['conflict_mode'] );
		}
	);

	bricks_ie_test(
		'adapter: a per-item replace decision also requires overwrite authorization',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$result = $adapter->import_package(
				'bytes',
				bricks_ie_adapter_test_full_selection(),
				array( 'conflict_decisions' => array( 'classes' => array( 'classOne' => 'replace' ) ) )
			);
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_overwrite_requires_authorization', $result->get_error_code() );
		}
	);

	// ==================================================================
	// Remote image policy
	// ==================================================================

	bricks_ie_test(
		'adapter: enabling template image import requires upload_files',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter   = bricks_ie_adapter_test_adapter();
			$selection = array(
				'types' => array( 'templates' ),
				'items' => array( 'templates' => array( 't1' ) ),
			);

			$GLOBALS['bricks_ie_adapter_test']['caps']['upload_files'] = false;
			$result                                                    = $adapter->import_package( 'bytes', $selection, array( 'import_images' => true ) );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );

			$GLOBALS['bricks_ie_adapter_test']['caps']['upload_files'] = true;
			$result                                                    = $adapter->import_package( 'bytes', $selection, array( 'import_images' => true ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'authorized image import should succeed' );
			$call = bricks_ie_adapter_test_last_call( 'import_package_bytes' );
			bricks_ie_assert_same( true, $call['import_images'] );
		}
	);

	// ==================================================================
	// Explicit item selection
	// ==================================================================

	bricks_ie_test( 'adapter: explicit list filtered to zero permissions does not invoke a route', function () {
		bricks_ie_adapter_test_reset();
		\Bricks\Builder_Permissions::$granted['access_class_manager'] = false;
		$result = bricks_ie_adapter_test_adapter()->list_items( array( 'classes' ) );
		bricks_ie_assert_same( array(), $result['types'] );
		$called = array_column( \Bricks\Unified_Global_Transfer::$calls, 0 );
		bricks_ie_assert( ! in_array( 'list_export_items', $called, true ) && ! in_array( 'export_package', $called, true ) && ! in_array( 'inspect_package_bytes', $called, true ) && ! in_array( 'import_package_bytes', $called, true ) );
	} );

	bricks_ie_test(
		'adapter: an empty item selection is rejected (no implicit all)',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$result = $adapter->export_package(
				array(
					'types' => array( 'classes' ),
					'items' => array( 'classes' => array() ),
				)
			);
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_explicit_items_required', $result->get_error_code() );

			$result = $adapter->export_package( array( 'types' => array( 'classes' ) ) );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_explicit_items_required', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: empty bytes are rejected for inspect and import',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			bricks_ie_assert_instance_of( 'WP_Error', $adapter->inspect_package( '' ) );
			bricks_ie_assert_instance_of( 'WP_Error', $adapter->import_package( '', bricks_ie_adapter_test_full_selection() ) );
		}
	);

	bricks_ie_test( 'adapter: non-ZIP inspect bytes are rejected before native result validation', function () {
		bricks_ie_adapter_test_reset();
		$result = bricks_ie_adapter_test_adapter()->inspect_package( 'not-a-zip' );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'bricks_ie_native_package_invalid', $result->get_error_code() );
	} );

	bricks_ie_test( 'adapter: unauthorized import performs no native inspection', function () {
		bricks_ie_adapter_test_reset();
		$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;
		$result = bricks_ie_adapter_test_adapter()->import_package( 'bytes', bricks_ie_adapter_test_full_selection() );
		bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );
		$calls = array_column( \Bricks\Unified_Global_Transfer::$calls, 0 );
		bricks_ie_assert( ! in_array( 'inspect_package_bytes', $calls, true ), 'unauthorized import must not inspect package bytes' );
	} );

	bricks_ie_test( 'adapter: inspect and import reject oversized bytes on static and ability routes', function () {
		bricks_ie_adapter_test_reset();
		$bytes = "PK\x03\x04" . str_repeat( 'x', 1048577 );
		foreach ( array( false, true ) as $ability_route ) {
			bricks_ie_adapter_test_reset();
			if ( $ability_route ) {
				$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/inspect-transfer-package'] = new Bricks_IE_Test_Stub_Ability( 'inspect', array() );
				$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/import-transfer-package'] = new Bricks_IE_Test_Stub_Ability( 'import', array() );
			}
			$result = bricks_ie_adapter_test_adapter()->inspect_package( $bytes );
			bricks_ie_assert_same( 'bricks_ie_native_package_too_large', $result->get_error_code() );
			$result = bricks_ie_adapter_test_adapter()->import_package( $bytes, bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_same( 'bricks_ie_native_package_too_large', $result->get_error_code() );
		}
	} );

	bricks_ie_test( 'adapter: fallback size limit is resolved from the native class constant', function () {
		bricks_ie_adapter_test_reset();
		$adapter = bricks_ie_adapter_test_adapter( array( 'native_class' => 'Bricks_IE_Test_Fallback_Limit_Transfer' ) );
		$result = $adapter->inspect_package( "PK\x03\x04" . str_repeat( 'x', MCP_MAX_ZIP_BYTES ) );
		bricks_ie_assert_same( 'bricks_ie_native_package_too_large', $result->get_error_code() );
	} );

	bricks_ie_test( 'adapter: malformed duplicate and unknown export IDs are rejected', function () {
		bricks_ie_adapter_test_reset();
		$adapter = bricks_ie_adapter_test_adapter();
		foreach ( array(
			array( 'classes', array( array() ) ),
			array( 'classes', array( 'classOne', 'classOne' ) ),
			array( 'classes', array( 'not-listed' ) ),
		) as $case ) {
			$result = $adapter->export_package( array( 'types' => array( $case[0] ), 'items' => array( $case[0] => $case[1] ) ) );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
		}
	} );

	bricks_ie_test( 'adapter: unknown import IDs are rejected against the inspected manifest', function () {
		bricks_ie_adapter_test_reset();
		$result = bricks_ie_adapter_test_adapter()->import_package( 'bytes', array( 'types' => array( 'classes' ), 'items' => array( 'classes' => array( 'not-in-manifest' ) ) ) );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'bricks_ie_unknown_transfer_item_id', $result->get_error_code() );
	} );

	// ==================================================================
	// Abilities-preferred path
	// ==================================================================

	bricks_ie_test(
		'adapter: prefers a registered ability and passes policy flags through',
		function () {
			bricks_ie_adapter_test_reset();

			$import_ability                                     = new Bricks_IE_Test_Stub_Ability(
				'bricks/import-transfer-package',
				array( 'success' => true, 'results' => array( 'classes' => array() ), 'zipHash' => 'abc' )
			);
			$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
				'bricks/import-transfer-package' => $import_ability,
			);

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->import_package(
				'package-bytes',
				bricks_ie_adapter_test_full_selection(),
				array(
					'conflict_mode'   => 'replace',
					'allow_overwrite' => true,
					'import_images'   => true,
				)
			);

			bricks_ie_assert( ! is_wp_error( $result ), 'ability import should succeed' );
			bricks_ie_assert_same( 'ability', $result['via'] );
			bricks_ie_assert_same( true, $result['success'] );

			$input = $import_ability->last_input;
			bricks_ie_assert_same( hash( 'sha256', 'package-bytes' ), $input['expectedZipHash'] );
			bricks_ie_assert_same( true, $input['allowOverwrite'] );
			bricks_ie_assert_same( true, $input['importImages'] );
			bricks_ie_assert_same( 'replace', $input['conflictMode'] );
			bricks_ie_assert_same( base64_encode( 'package-bytes' ), $input['zipBase64'] );
		}
	);

	bricks_ie_test(
		'adapter: ability path still enforces the plugin-side sensitive policy',
		function () {
			bricks_ie_adapter_test_reset();

			$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
				'bricks/export-transfer-package' => new Bricks_IE_Test_Stub_Ability( 'bricks/export-transfer-package', array() ),
			);

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->export_package(
				array(
					'types' => array( 'settings' ),
					'items' => array( 'settings' => array( 'api-keys' ) ),
				)
			);

			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_sensitive_settings_requires_authorization', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: a WP_Error from the native engine is passed through',
		function () {
			bricks_ie_adapter_test_reset();
			\Bricks\Unified_Global_Transfer::$import_result = new WP_Error( 'invalid_zip', 'Unable to open ZIP file.' );

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->import_package( 'bad-bytes', bricks_ie_adapter_test_full_selection() );

			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'invalid_zip', $result->get_error_code() );
		}
	);

	// ==================================================================
	// Ability execute() invocability
	// ==================================================================

	bricks_ie_test(
		'adapter: an ability with a private execute() is never used and falls back to native',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
				'bricks/list-transfer-items' => new Bricks_IE_Test_Private_Execute_Ability( 'bricks/list-transfer-items' ),
			);

			$adapter = bricks_ie_adapter_test_adapter();

			// Detection must not report the unusable ability as available.
			$report = $adapter->detect_capabilities();
			bricks_ie_assert_same( false, $report['abilities']['bricks/list-transfer-items'], 'private execute() must not count as available' );
			bricks_ie_assert_same( false, $report['use_abilities'] );

			// Operations must fall back to the native path without faulting.
			\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array() );
			$result                                       = $adapter->list_items( array( 'classes' ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'list_items should fall back to the native path' );
			bricks_ie_assert_same( 'native', $result['via'] );
		}
	);

	bricks_ie_test(
		'adapter: an ability object without execute() is ignored in favor of the native fallback',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
				'bricks/export-transfer-package' => new Bricks_IE_Test_No_Execute_Ability( 'bricks/export-transfer-package' ),
			);

			$adapter = bricks_ie_adapter_test_adapter();

			$report = $adapter->detect_capabilities();
			bricks_ie_assert_same( false, $report['abilities']['bricks/export-transfer-package'] );

			$result = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert( ! is_wp_error( $result ), 'export_package should fall back to the native path' );
			bricks_ie_assert_same( 'native', $result['via'] );
		}
	);

	// ==================================================================
	// Strict export result validation
	// ==================================================================

	bricks_ie_test(
		'adapter: empty or non-array export results fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			\Bricks\Unified_Global_Transfer::$export_result = array();
			$result                                          = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_invalid', $result->get_error_code() );
			bricks_ie_assert_same( 'zip_base64_missing', $result->get_error_data()['reason'] );

			\Bricks\Unified_Global_Transfer::$export_result = 'not-an-array';
			$result                                          = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_result_invalid', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: export results with invalid or empty base64 fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$invalid                                          = \Bricks\Unified_Global_Transfer::valid_export_result();
			$invalid['zipBase64']                             = '!!!not-base64!!!';
			\Bricks\Unified_Global_Transfer::$export_result = $invalid;
			$result                                          = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_invalid', $result->get_error_code() );
			bricks_ie_assert_same( 'zip_base64_invalid', $result->get_error_data()['reason'] );

			$empty                                          = \Bricks\Unified_Global_Transfer::valid_export_result();
			$empty['zipBase64']                             = '';
			\Bricks\Unified_Global_Transfer::$export_result = $empty;
			$result                                          = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_invalid', $result->get_error_code() );
			bricks_ie_assert_same( 'zip_base64_missing', $result->get_error_data()['reason'] );
		}
	);

	bricks_ie_test(
		'adapter: export results without the ZIP signature fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			// Internally consistent hash/count, but the bytes are not a ZIP.
			$not_zip = 'definitely-not-a-zip-archive';
			\Bricks\Unified_Global_Transfer::$export_result = array(
				'filename'  => 'bricks-global-data.zip',
				'zipBase64' => base64_encode( $not_zip ),
				'zipHash'   => hash( 'sha256', $not_zip ),
				'zipBytes'  => strlen( $not_zip ),
				'manifest'  => array(
					'schema'  => 'bricks/unified-global-transfer',
					'version' => 1,
				),
			);

			$result = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_invalid', $result->get_error_code() );
			bricks_ie_assert_same( 'zip_signature_invalid', $result->get_error_data()['reason'] );
		}
	);

	bricks_ie_test(
		'adapter: export results with a missing or mismatched sha256 fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$mismatched                                          = \Bricks\Unified_Global_Transfer::valid_export_result();
			$mismatched['zipHash']                               = str_repeat( '0', 64 );
			\Bricks\Unified_Global_Transfer::$export_result    = $mismatched;
			$result                                              = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_hash_mismatch', $result->get_error_code() );
			bricks_ie_assert_same( 'zip_hash_mismatch', $result->get_error_data()['reason'] );

			$missing_hash                                       = \Bricks\Unified_Global_Transfer::valid_export_result();
			unset( $missing_hash['zipHash'] );
			\Bricks\Unified_Global_Transfer::$export_result    = $missing_hash;
			$result                                              = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_hash_mismatch', $result->get_error_code() );
			bricks_ie_assert_same( 'zip_hash_missing', $result->get_error_data()['reason'] );
		}
	);

	bricks_ie_test(
		'adapter: export results with a mismatched byte count fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$wrong_count                                       = \Bricks\Unified_Global_Transfer::valid_export_result();
			$wrong_count['zipBytes']                           = $wrong_count['zipBytes'] + 1;
			\Bricks\Unified_Global_Transfer::$export_result   = $wrong_count;
			$result                                             = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_bytes_mismatch', $result->get_error_code() );

			$non_int_count                                     = \Bricks\Unified_Global_Transfer::valid_export_result();
			$non_int_count['zipBytes']                         = (string) $non_int_count['zipBytes'];
			\Bricks\Unified_Global_Transfer::$export_result   = $non_int_count;
			$result                                             = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_package_bytes_mismatch', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: export results with an unsafe or invalid filename fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			foreach ( array( '../../evil.zip', 'sub/dir/data.zip', 'back\\slash.zip', 'archive.tar', '.zip', '' ) as $filename ) {
				$invalid                                       = \Bricks\Unified_Global_Transfer::valid_export_result();
				$invalid['filename']                           = $filename;
				\Bricks\Unified_Global_Transfer::$export_result = $invalid;

				$result = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
				bricks_ie_assert_instance_of( 'WP_Error', $result, 'filename "' . $filename . '" must be rejected' );
				bricks_ie_assert_same( 'bricks_ie_native_package_filename_invalid', $result->get_error_code(), 'filename "' . $filename . '"' );
			}
		}
	);

	bricks_ie_test(
		'adapter: export results without the audited manifest schema and version fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$variants = array();

			$no_manifest = \Bricks\Unified_Global_Transfer::valid_export_result();
			unset( $no_manifest['manifest'] );
			$variants['missing manifest'] = $no_manifest;

			$drifted_schema                 = \Bricks\Unified_Global_Transfer::valid_export_result();
			$drifted_schema['manifest']     = array( 'schema' => 'bricks/something-else', 'version' => 1 );
			$variants['drifted schema']     = $drifted_schema;

			$drifted_version                = \Bricks\Unified_Global_Transfer::valid_export_result();
			$drifted_version['manifest']    = array( 'schema' => 'bricks/unified-global-transfer', 'version' => 2 );
			$variants['drifted version']    = $drifted_version;

			$missing_version                = \Bricks\Unified_Global_Transfer::valid_export_result();
			$missing_version['manifest']    = array( 'schema' => 'bricks/unified-global-transfer' );
			$variants['missing version']    = $missing_version;

			foreach ( $variants as $label => $payload ) {
				\Bricks\Unified_Global_Transfer::$export_result = $payload;
				$result                                         = $adapter->export_package( bricks_ie_adapter_test_full_selection() );
				bricks_ie_assert_instance_of( 'WP_Error', $result, $label . ' must be rejected' );
				bricks_ie_assert_same( 'bricks_ie_native_package_manifest_invalid', $result->get_error_code(), $label );
			}
		}
	);

	bricks_ie_test(
		'adapter: a valid export result via the ability path passes strict validation',
		function () {
			bricks_ie_adapter_test_reset();

			$GLOBALS['bricks_ie_adapter_test']['abilities'] = array(
				'bricks/export-transfer-package' => new Bricks_IE_Test_Stub_Ability(
					'bricks/export-transfer-package',
					\Bricks\Unified_Global_Transfer::valid_export_result()
				),
			);

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->export_package( bricks_ie_adapter_test_full_selection() );

			bricks_ie_assert( ! is_wp_error( $result ), 'ability export should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
			bricks_ie_assert_same( 'ability', $result['via'] );
			bricks_ie_assert_same( hash( 'sha256', \Bricks\Unified_Global_Transfer::default_package_bytes() ), $result['zip_hash'] );
		}
	);

	// ==================================================================
	// List result shape validation
	// ==================================================================

	bricks_ie_test(
		'adapter: malformed list results fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$variants = array(
				'missing types key'        => array(),
				'types is a string'        => array( 'types' => 'classes' ),
				'types entry is scalar'    => array( 'types' => array( 'classes' ) ),
				'descriptor without id'    => array( 'types' => array( array( 'items' => array() ) ) ),
				'descriptor with empty id' => array( 'types' => array( array( 'id' => '' ) ) ),
				'keyed map scalar value'   => array( 'types' => array( 'classes' => 'not-an-array' ) ),
			);

			foreach ( $variants as $label => $payload ) {
				\Bricks\Unified_Global_Transfer::$list_result = $payload;
				$result                                       = $adapter->list_items( array( 'classes' ) );
				bricks_ie_assert_instance_of( 'WP_Error', $result, $label . ' must be rejected' );
				bricks_ie_assert_same( 'bricks_ie_native_result_invalid', $result->get_error_code(), $label );
				bricks_ie_assert_same( 'list', $result->get_error_data()['operation'], $label );
			}
		}
	);

	bricks_ie_test(
		'adapter: valid list shapes (descriptor list, keyed map, empty) are accepted',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			// Audited Bricks 2.4 descriptor list shape.
			\Bricks\Unified_Global_Transfer::$list_result = array(
				'types' => array(
					array(
						'id'    => 'classes',
						'label' => 'Classes',
						'items' => array( array( 'id' => 'clsOne' ) ),
					),
				),
			);
			$result = $adapter->list_items( array( 'classes' ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'descriptor list shape must be accepted' );
			bricks_ie_assert_same( 1, count( $result['types'] ) );

			// Keyed map shape: type ID => items.
			\Bricks\Unified_Global_Transfer::$list_result = array(
				'types' => array(
					'classes' => array( array( 'id' => 'clsOne' ) ),
				),
			);
			$result = $adapter->list_items( array( 'classes' ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'keyed map shape must be accepted' );

			// Empty types list is valid (nothing exportable for the user).
			\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array() );
			$result = $adapter->list_items( array( 'classes' ) );
			bricks_ie_assert( ! is_wp_error( $result ), 'empty types list must be accepted' );
			bricks_ie_assert_same( array(), $result['types'] );
		}
	);

	// ==================================================================
	// Inspect result shape validation
	// ==================================================================

	bricks_ie_test(
		'adapter: malformed inspect results fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$adapter = bricks_ie_adapter_test_adapter();

			$variants = array(
				'missing manifest'          => array( 'zipHash' => 'x', 'zipBytes' => 0 ),
				'manifest is not an array'  => array( 'manifest' => 'nope', 'zipHash' => 'x', 'zipBytes' => 0 ),
				'manifest without schema'   => array( 'manifest' => array( 'version' => 1 ), 'zipHash' => 'x', 'zipBytes' => 0 ),
				'manifest with empty schema' => array( 'manifest' => array( 'schema' => '' ), 'zipHash' => 'x', 'zipBytes' => 0 ),
				'missing zipHash'           => array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer' ), 'zipBytes' => 0 ),
				'zipHash is not a string'   => array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer' ), 'zipHash' => 42, 'zipBytes' => 0 ),
				'missing zipBytes'          => array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer' ), 'zipHash' => 'x' ),
				'negative zipBytes'         => array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer' ), 'zipHash' => 'x', 'zipBytes' => -1 ),
				'non-int maxZipBytes'       => array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer' ), 'zipHash' => 'x', 'zipBytes' => 0, 'maxZipBytes' => 'lots' ),
			);

			foreach ( $variants as $label => $payload ) {
				\Bricks\Unified_Global_Transfer::$inspect_result = $payload;
				$result                                          = $adapter->inspect_package( \Bricks\Unified_Global_Transfer::default_package_bytes() );
				bricks_ie_assert_instance_of( 'WP_Error', $result, $label . ' must be rejected' );
				bricks_ie_assert_same( 'bricks_ie_native_result_invalid', $result->get_error_code(), $label );
				bricks_ie_assert_same( 'inspect', $result->get_error_data()['operation'], $label );
			}
		}
	);

	bricks_ie_test( 'adapter: inspect hash, byte count, schema, and version mismatches fail closed', function () {
		bricks_ie_adapter_test_reset();
		$bytes = \Bricks\Unified_Global_Transfer::default_package_bytes();
		$valid = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array() ), 'zipHash' => hash( 'sha256', $bytes ), 'zipBytes' => strlen( $bytes ) );
		$cases = array(
			'hash' => array( 'bricks_ie_native_package_hash_mismatch', array_merge( $valid, array( 'zipHash' => str_repeat( '0', 64 ) ) ) ),
			'bytes' => array( 'bricks_ie_native_package_bytes_mismatch', array_merge( $valid, array( 'zipBytes' => 99 ) ) ),
			'schema' => array( 'bricks_ie_native_schema_mismatch', array_merge( $valid, array( 'manifest' => array( 'schema' => 'wrong', 'version' => 1, 'types' => array() ) ) ) ),
			'version' => array( 'bricks_ie_native_version_mismatch', array_merge( $valid, array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 2, 'types' => array() ) ) ) ),
		);
		foreach ( $cases as $case ) {
			\Bricks\Unified_Global_Transfer::$inspect_result = $case[1];
			$result = bricks_ie_adapter_test_adapter()->inspect_package( $bytes );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			$expected = in_array( $case[0], array( 'bricks_ie_native_package_bytes_mismatch', 'bricks_ie_native_schema_mismatch', 'bricks_ie_native_version_mismatch' ), true ) ? 'bricks_ie_native_result_invalid' : $case[0];
			bricks_ie_assert_same( $expected, $result->get_error_code() );
		}
	} );

	bricks_ie_test(
		'adapter: inspect fails closed on a drifted manifest schema',
		function () {
			bricks_ie_adapter_test_reset();
			\Bricks\Unified_Global_Transfer::$inspect_result = array(
				'manifest' => array( 'schema' => 'bricks/something-else', 'version' => 1, 'types' => array() ),
				'zipHash'  => hash( 'sha256', 'package-bytes' ),
				'zipBytes' => strlen( 'package-bytes' ),
			);

			$adapter = bricks_ie_adapter_test_adapter();
			$result  = $adapter->inspect_package( \Bricks\Unified_Global_Transfer::default_package_bytes() );

			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_native_result_invalid', $result->get_error_code() );
		}
	);

	// ==================================================================
	// CSS regeneration contract
	// ==================================================================

	bricks_ie_test(
		'adapter: CSS regeneration prefers the ability and normalizes success',
		function () {
			bricks_ie_adapter_test_reset();
			$ability = new Bricks_IE_Test_Stub_Ability(
				'bricks/regenerate-css-files',
				array(
					'success'           => true,
					'generatedFileCount' => 2,
					'generatedFiles'    => array( 'a.css', 'b.css' ),
					'cssLoading'        => 'file',
				)
			);
			$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/regenerate-css-files'] = $ability;

			$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
			bricks_ie_assert( ! is_wp_error( $result ) );
			bricks_ie_assert_same( array(), $ability->last_input );
			bricks_ie_assert_same( 'ability', $result['via'] );
			bricks_ie_assert_same( 2, $result['generated_file_count'] );
			bricks_ie_assert_same( array( 'a.css', 'b.css' ), $result['generated_files'] );
		}
	);

	bricks_ie_test(
		'adapter: CSS regeneration passes through ability failure',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/regenerate-css-files'] = new Bricks_IE_Test_Stub_Ability(
				'bricks/regenerate-css-files',
				new WP_Error( 'css_failed', 'CSS failed.' )
			);

			$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'css_failed', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: malformed CSS regeneration ability results fail closed',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['abilities']['bricks/regenerate-css-files'] = new Bricks_IE_Test_Stub_Ability(
				'bricks/regenerate-css-files',
				array( 'success' => true )
			);

			$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
			bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'bricks_ie_css_regeneration_result_invalid', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: CSS regeneration requires manage_options',
		function () {
			bricks_ie_adapter_test_reset();
			$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;

			$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_missing_permission', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: CSS regeneration fails closed when the fallback is unavailable',
		function () {
			bricks_ie_adapter_test_reset();
			if ( class_exists( 'Bricks\\Assets_Files' ) ) {
				return;
			}

			$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'bricks_ie_css_regeneration_unavailable', $result->get_error_code() );
		}
	);

	bricks_ie_test(
		'adapter: CSS regeneration falls back to the public Assets_Files method',
		function () {
			bricks_ie_adapter_test_reset();
			if ( ! class_exists( 'Bricks\\Assets_Files' ) ) {
				eval( 'namespace Bricks; class Assets_Files { public static function regenerate_css_files() { return array( "fallback.css" ); } }' );
			}

			$result = bricks_ie_adapter_test_adapter()->regenerate_css_files();
			bricks_ie_assert( ! is_wp_error( $result ) );
			bricks_ie_assert_same( 'fallback', $result['via'] );
			bricks_ie_assert_same( array( 'fallback.css' ), $result['generated_files'] );
		bricks_ie_assert_same( 1, $result['generated_file_count'] );
		}
	);

}
