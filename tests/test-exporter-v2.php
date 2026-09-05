<?php
/**
 * Tests for the schema version 2 exporter (WP3).
 *
 * Runs under the plugin-local harness (tests/bootstrap.php + tests/run.php)
 * or standalone via `php tests/test-exporter-v2.php`. Local fallback guards
 * keep this file self-contained without editing harness files.
 *
 * All fixtures live in memory or below the system temporary directory. The
 * Bricks native transfer engine, WordPress primitives, and the archive
 * validator are stubbed; no database, option, post, or site writes happen.
 */

namespace {

	// ---------------------------------------------------------------------
	// Bootstrap: prefer the shared harness, fall back to local guards.
	// ---------------------------------------------------------------------

if ( ! function_exists( 'bricks_ie_test' ) ) {
	$bricks_ie_ex_bootstrap = __DIR__ . '/bootstrap.php';

	if ( file_exists( $bricks_ie_ex_bootstrap ) ) {
		require_once $bricks_ie_ex_bootstrap;
	} else {
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
}

// The exporter runs against a verified Bricks 2.4 native contract; the
// version constant mirrors an active Bricks theme in these tests.
if ( ! defined( 'BRICKS_VERSION' ) ) {
	define( 'BRICKS_VERSION', '2.4-beta2' );
}

// -------------------------------------------------------------------------
// WordPress primitive stubs (process-wide, configurable per test).
// -------------------------------------------------------------------------

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		$filename = strtolower( (string) $filename );
		$filename = preg_replace( '/[^a-z0-9._\-]/', '-', $filename );
		$filename = preg_replace( '/-{2,}/', '-', $filename );
		return trim( $filename, '-' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		$options = isset( $GLOBALS['bricks_ie_exporter_test']['options'] ) ? $GLOBALS['bricks_ie_exporter_test']['options'] : array();
		return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$posts     = isset( $GLOBALS['bricks_ie_exporter_test']['posts'] ) ? $GLOBALS['bricks_ie_exporter_test']['posts'] : array();
		$requested = isset( $args['post_type'] ) ? (array) $args['post_type'] : array();

		$out = array();
		foreach ( $posts as $post ) {
			if ( empty( $requested ) || in_array( $post->post_type, $requested, true ) ) {
				$out[] = $post;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$meta = isset( $GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ] ) ? $GLOBALS['bricks_ie_exporter_test']['post_meta'][ $post_id ] : array();
		if ( '' === $key ) {
			return $meta;
		}
		return array_key_exists( $key, $meta ) ? $meta[ $key ] : '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://source.example' . $path;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '6.7' : '';
	}
}

if ( ! class_exists( 'Bricks_IE_Ex_Test_Theme' ) ) {
	class Bricks_IE_Ex_Test_Theme {
		private $stylesheet;

		public function __construct( $stylesheet ) {
			$this->stylesheet = (string) $stylesheet;
		}

		public function exists() {
			return 'bricks' === $this->stylesheet;
		}

		public function get( $header ) {
			return 'Version' === $header ? '1.3.14' : '';
		}
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = '' ) {
		return new Bricks_IE_Ex_Test_Theme( $stylesheet );
	}
}

if ( ! function_exists( 'bricks_ie_get_option_names' ) ) {
	function bricks_ie_get_option_names() {
		return isset( $GLOBALS['bricks_ie_exporter_test']['option_names'] )
			? $GLOBALS['bricks_ie_exporter_test']['option_names']
			: array( 'bricks_global_settings' );
	}
}

if ( ! function_exists( 'bricks_ie_get_legacy_sensitive_settings_keys' ) ) {
	function bricks_ie_get_legacy_sensitive_settings_keys() {
		return array(
			'apiKeyGoogleMaps', 'apiKeyGoogleRecaptcha', 'apiSecretKeyGoogleRecaptcha',
			'executeCodeEnabled', 'customCode', 'customCss', 'customScriptsHeader',
			'customScriptsBodyHeader', 'customScriptsBodyFooter', 'myTemplatesPassword',
			'remoteTemplatesPassword', 'password', 'pass', 'apiKey', 'apiKeys', 'apiSecretKey',
		);
	}
}

if ( ! function_exists( 'bricks_ie_get_post_types' ) ) {
	function bricks_ie_get_post_types() {
		return isset( $GLOBALS['bricks_ie_exporter_test']['post_types'] )
			? $GLOBALS['bricks_ie_exporter_test']['post_types']
			: array( 'page', 'bricks_template' );
	}
}

} // End of global namespace block.

// -------------------------------------------------------------------------
// Bricks native transfer stubs (guarded: the adapter test file may have
// defined identical stubs earlier in the same process).
// -------------------------------------------------------------------------

namespace Bricks {

	if ( ! class_exists( 'Bricks\Unified_Global_Transfer' ) ) {
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

			public static function list_export_items( $types = array() ) {
				self::$calls[] = array( 'list_export_items', array( $types ) );
				if ( null !== self::$list_result ) {
					return self::$list_result;
				}
				return array( 'types' => array() );
			}

			public static function export_package( $types, $items = array(), $payloads = array() ) {
				self::$calls[] = array( 'export_package', array( $types, $items, $payloads ) );
				if ( null !== self::$export_result ) {
					return self::$export_result;
				}
				$bytes = 'native-zip-bytes';
				return array(
					'filename'  => 'bricks-global-data.zip',
					'zipBase64' => base64_encode( $bytes ),
					'zipHash'   => hash( 'sha256', $bytes ),
					'zipBytes'  => strlen( $bytes ),
					'manifest'  => array( 'schema' => self::MANIFEST_SCHEMA ),
				);
			}

			public static function inspect_package_bytes( $bytes ) {
				self::$calls[] = array( 'inspect_package_bytes', array( $bytes ) );
				if ( null !== self::$inspect_result ) {
					return self::$inspect_result;
				}
				$types = array();
				for ( $i = count( self::$calls ) - 1; $i >= 0; $i-- ) {
					if ( 'export_package' === self::$calls[ $i ][0] ) {
						$export_types = isset( self::$calls[ $i ][1][0] ) ? self::$calls[ $i ][1][0] : array();
						$export_items = isset( self::$calls[ $i ][1][1] ) ? self::$calls[ $i ][1][1] : array();
						foreach ( $export_types as $type ) {
							$types[ $type ] = isset( $export_items[ $type ] ) ? $export_items[ $type ] : array();
						}
						break;
					}
				}
				return array(
					'manifest' => array( 'schema' => self::MANIFEST_SCHEMA, 'types' => $types ),
					'zipHash'  => hash( 'sha256', $bytes ),
					'zipBytes' => strlen( $bytes ),
				);
			}

			public static function import_package_bytes( $bytes, $types, $items, $conflict_mode = 'skip', $conflict_decisions = array(), $import_images = false ) {
				self::$calls[] = array( 'import_package_bytes', array( $bytes, $types, $items ) );
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

namespace {

	if ( ! class_exists( 'Bricks_IE_Bricks_Transfer_Adapter' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-bricks-transfer-adapter.php';
	}

	if ( ! class_exists( 'Bricks_IE_Archive_Validator' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-archive-validator.php';
	}

	if ( ! class_exists( 'Bricks_IE_Exporter' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-bricks-exporter.php';
	}

	// ---------------------------------------------------------------------
	// Fixture helpers.
	// ---------------------------------------------------------------------

	/**
	 * Build raw zip bytes from [ name, content ] pairs (stored uncompressed).
	 */
	function bricks_ie_ex_raw_zip( array $entries ) {
		$local   = '';
		$central = '';
		$offset  = 0;
		$count   = 0;

		foreach ( $entries as $entry ) {
			list( $name, $content ) = $entry;

			$crc  = crc32( $content );
			$size = strlen( $content );

			$local_header = pack( 'V', 0x04034b50 )
				. pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0x21 )
				. pack( 'V', $crc ) . pack( 'V', $size ) . pack( 'V', $size )
				. pack( 'v', strlen( $name ) ) . pack( 'v', 0 )
				. $name;

			$central .= pack( 'V', 0x02014b50 )
				. pack( 'v', 20 ) . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0x21 )
				. pack( 'V', $crc ) . pack( 'V', $size ) . pack( 'V', $size )
				. pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'v', 0 ) . pack( 'v', 0 )
				. pack( 'V', 0 ) . pack( 'V', $offset )
				. $name;

			$local  .= $local_header . $content;
			$offset += strlen( $local_header ) + $size;
			$count++;
		}

		$eocd = pack( 'V', 0x06054b50 )
			. pack( 'v', 0 ) . pack( 'v', 0 )
			. pack( 'v', $count ) . pack( 'v', $count )
			. pack( 'V', strlen( $central ) ) . pack( 'V', strlen( $local ) )
			. pack( 'v', 0 );

		return $local . $central . $eocd;
	}

	/**
	 * Opaque fake native Bricks package bytes (a real minimal zip).
	 */
	function bricks_ie_ex_native_package_bytes() {
		return bricks_ie_ex_raw_zip(
			array(
				array( 'manifest.json', '{"schema":"bricks/unified-global-transfer","version":1}' ),
			)
		);
	}

	/**
	 * Build a stdClass post record for the get_posts() stub.
	 */
	function bricks_ie_ex_make_post( $id, $type, $slug, $title = '', $status = 'publish' ) {
		$post              = new stdClass();
		$post->ID          = (int) $id;
		$post->post_type   = $type;
		$post->post_name   = $slug;
		$post->post_title  = '' !== $title ? $title : ucfirst( $slug );
		$post->post_status = $status;
		return $post;
	}

	/**
	 * Reset all process-wide stub state for an exporter test.
	 */
	function bricks_ie_ex_reset() {
		$GLOBALS['bricks_ie_adapter_test'] = array(
			'caps'      => array(
				'manage_options' => true,
				'upload_files'   => true,
			),
			'abilities' => array(),
		);

		$GLOBALS['bricks_ie_exporter_test'] = array(
			'option_names' => array( 'bricks_global_settings' ),
			'options'      => array(
				'bricks_global_settings' => array( 'postTypes' => array() ),
			),
			'post_types'   => array( 'page', 'bricks_template' ),
			'posts'        => array(),
			'post_meta'    => array(),
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

	/**
	 * Prime the native stubs with a standard list/export scenario.
	 *
	 * @return string The native package bytes used for the export result.
	 */
	function bricks_ie_ex_prime_native() {
		$package = bricks_ie_ex_native_package_bytes();

		\Bricks\Unified_Global_Transfer::$list_result = array(
			'types' => array(
				'classes'   => array(
					array( 'id' => 'clsOne', 'name' => 'Button' ),
					array( 'id' => 'clsTwo', 'name' => 'Card' ),
				),
				'templates' => array(
					array( 'id' => 'tplHeader', 'name' => 'Site Header' ),
				),
				'settings'  => array(
					array( 'id' => 'general' ),
					array( 'id' => 'api-keys' ),
					array( 'id' => 'custom-code' ),
					array( 'id' => 'templates' ),
				),
			),
		);

		\Bricks\Unified_Global_Transfer::$export_result = array(
			'filename'  => 'bricks-global-data.zip',
			'zipBase64' => base64_encode( $package ),
			'zipHash'   => hash( 'sha256', $package ),
			'zipBytes'  => strlen( $package ),
			'manifest'  => array(
				'schema'  => 'bricks/unified-global-transfer',
				'version' => 1,
			),
		);

		// Pin inspection to this fixture even when another test file declared
		// the guarded native stub first.
		\Bricks\Unified_Global_Transfer::$inspect_result = array(
			'manifest' => array(
				'schema' => 'bricks/unified-global-transfer',
				'version' => 1,
				'types' => array(
					'classes' => array( array( 'id' => 'clsOne' ), array( 'id' => 'clsTwo' ) ),
					'templates' => array( array( 'id' => 'tplHeader' ) ),
					'settings' => array( array( 'id' => 'general' ) ),
				),
			),
			'zipHash' => hash( 'sha256', $package ),
			'zipBytes' => strlen( $package ),
		);

		return $package;
	}

	/**
	 * Seed one page and one template that both carry Bricks meta.
	 */
	function bricks_ie_ex_seed_posts() {
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array(
			bricks_ie_ex_make_post( 10, 'page', 'home', 'Home' ),
			bricks_ie_ex_make_post( 11, 'bricks_template', 'site-header', 'Site Header' ),
		);

		$GLOBALS['bricks_ie_exporter_test']['post_meta'] = array(
			10 => array(
				'_bricks_page_content_2' => array(
					array(
						'id'       => 'abcd',
						'name'     => 'div',
						'settings' => array( 'tag' => 'div' ),
					),
				),
			),
			11 => array(
				'_bricks_page_content_2'  => array( 'x' ),
				'_bricks_template_type'   => 'header',
			),
		);
	}

	/**
	 * Read every member of a zip file into a name => content map.
	 */
	function bricks_ie_ex_zip_members( $path ) {
		$zip = new ZipArchive();
		bricks_ie_assert( true === $zip->open( $path ), 'Could not reopen the export zip.' );

		$members = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name             = $zip->getNameIndex( $i );
			$members[ $name ] = $zip->getFromIndex( $i );
		}
		$zip->close();

		return $members;
	}

	/**
	 * Find the last recorded native stub call for a method.
	 */
	function bricks_ie_ex_last_native_call( $method ) {
		$calls = \Bricks\Unified_Global_Transfer::$calls;
		for ( $i = count( $calls ) - 1; $i >= 0; $i-- ) {
			if ( $calls[ $i ][0] === $method ) {
				return $calls[ $i ][1];
			}
		}
		return null;
	}

	/**
	 * Whether a native stub method was ever called.
	 */
	function bricks_ie_ex_native_called( $method ) {
		foreach ( \Bricks\Unified_Global_Transfer::$calls as $call ) {
			if ( $call[0] === $method ) {
				return true;
			}
		}
		return false;
	}

	function bricks_ie_ex_assert_error( $expected_code, $result, $message = '' ) {
		if ( ! is_wp_error( $result ) ) {
			throw new RuntimeException(
				$message . ' Expected WP_Error "' . $expected_code . '", got: '
				. ( is_array( $result ) ? 'a success report' : var_export( $result, true ) )
			);
		}
		bricks_ie_assert_same( $expected_code, $result->get_error_code(), $message );
	}

	function bricks_ie_ex_omission_ids( array $omissions ) {
		$ids = array();
		foreach ( $omissions as $omission ) {
			if ( isset( $omission['id'] ) ) {
				$ids[] = $omission['id'];
			}
		}
		return $ids;
	}

	// ---------------------------------------------------------------------
	// Tests: schema version 2 happy path.
	// ---------------------------------------------------------------------

	bricks_ie_test(
		'exporter v2: verified native contract produces a valid outer schema 2 archive',
		function () {
			bricks_ie_ex_reset();
			$package = bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'build_zip should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );

			// Existing caller keys are preserved, new keys are added.
			bricks_ie_assert_same( $out, $result['file'] );
			bricks_ie_assert_same( 0, $result['options_count'], 'v2 never emits options/*.json' );
			bricks_ie_assert_same( 1, $result['posts_count'], 'only the page is Katsarov-owned' );
			bricks_ie_assert_same( filesize( $out ), $result['size'] );
			bricks_ie_assert_same( 2, $result['schema_version'] );
			bricks_ie_assert( is_array( $result['warnings'] ), 'warnings must be reported' );
			bricks_ie_assert( is_array( $result['omissions'] ), 'omissions must be reported' );
			bricks_ie_assert_same( true, $result['validated'] );

			$members = bricks_ie_ex_zip_members( $out );
			$names   = array_keys( $members );
			sort( $names );
			bricks_ie_assert_same(
				array(
					'bricks/package.sha256',
					'bricks/package.zip',
					'katsarov/export-warnings.json',
					'katsarov/posts/index.json',
					'katsarov/posts/page__home.json',
					'manifest.json',
				),
				$names,
				'exact v2 layout expected'
			);

			// Opaque native package is embedded byte-for-byte with its checksum.
			bricks_ie_assert_same( $package, $members['bricks/package.zip'] );
			bricks_ie_assert_same( hash( 'sha256', $package ) . "  package.zip\n", $members['bricks/package.sha256'] );

			// Outer manifest contract.
			$manifest = json_decode( $members['manifest.json'], true );
			bricks_ie_assert_same( 'katsarov/bricks-import-export', $manifest['format'] );
			bricks_ie_assert_same( 2, $manifest['version'] );
			bricks_ie_assert_same( 'https://source.example', $manifest['site_url'] );
			bricks_ie_assert_same( '2.4-beta2', $manifest['bricks']['version'] );
			bricks_ie_assert_same( 'bricks/unified-global-transfer', $manifest['bricks']['native_schema'] );
			bricks_ie_assert_same( 1, $manifest['bricks']['native_version'] );
			bricks_ie_assert_same( hash( 'sha256', $package ), $manifest['bricks']['package_sha256'] );
			bricks_ie_assert_same(
				array(
					'native_bricks'       => true,
					'posts'               => true,
					'template_conditions' => false,
					'media_files'         => false,
				),
				$manifest['domains']
			);
			// 3 types (classes, templates, settings), 2 + 1 + 1 items: the
			// sensitive settings tabs are excluded by default.
			bricks_ie_assert_same( 3, $manifest['counts']['native_types'] );
			bricks_ie_assert_same( 4, $manifest['counts']['native_items'] );
			bricks_ie_assert_same( 1, $manifest['counts']['posts'] );

			// Templates never appear in Katsarov posts for v2.
			$index = json_decode( $members['katsarov/posts/index.json'], true );
			bricks_ie_assert_same(
				array(
					array( 'slug' => 'home', 'type' => 'page', 'file' => 'page__home.json' ),
				),
				$index,
				'templates must not be indexed as Katsarov posts'
			);

			// Post meta is plain JSON, never PHP serialization.
			$payload = json_decode( $members['katsarov/posts/page__home.json'], true );
			bricks_ie_assert_same( 10, $payload['id'] );
			bricks_ie_assert_same( 'home', $payload['slug'] );
			bricks_ie_assert_same( 'page', $payload['type'] );
			bricks_ie_assert_same( 'publish', $payload['status'] );
			bricks_ie_assert_same( 'Home', $payload['title'] );
			bricks_ie_assert_same(
				array(
					'_bricks_page_content_2' => array(
						array(
							'id'       => 'abcd',
							'name'     => 'div',
							'settings' => array( 'tag' => 'div' ),
						),
					),
				),
				$payload['meta'],
				'meta must be JSON-safe values, not base64 serialization'
			);

			// Export warnings sidecar records warnings and omissions.
			$sidecar = json_decode( $members['katsarov/export-warnings.json'], true );
			bricks_ie_assert_same( 2, $sidecar['schema_version'] );
			bricks_ie_assert( is_array( $sidecar['warnings'] ) );
			$omission_ids = bricks_ie_ex_omission_ids( $sidecar['omissions'] );
			foreach ( array( 'media_files', 'template_conditions', 'style_manager', 'pseudo_classes', 'ui_workflow_state', 'sensitive_settings' ) as $required ) {
				bricks_ie_assert( in_array( $required, $omission_ids, true ), 'omission "' . $required . '" must be recorded' );
			}

			// Explicit adapter flow: list -> export (explicit items) -> inspect.
			bricks_ie_assert( bricks_ie_ex_native_called( 'list_export_items' ), 'list flow expected' );
			$export_args = bricks_ie_ex_last_native_call( 'export_package' );
			bricks_ie_assert( null !== $export_args, 'export flow expected' );
			bricks_ie_assert_same( array( 'classes', 'templates', 'settings' ), $export_args[0] );
			bricks_ie_assert_same( array( 'clsOne', 'clsTwo' ), $export_args[1]['classes'] );
			bricks_ie_assert_same( array( 'tplHeader' ), $export_args[1]['templates'] );
			bricks_ie_assert_same( array( 'general' ), $export_args[1]['settings'], 'sensitive settings excluded by default' );
			$inspect_args = bricks_ie_ex_last_native_call( 'inspect_package_bytes' );
			bricks_ie_assert( null !== $inspect_args, 'inspect flow expected' );
			bricks_ie_assert_same( $package, $inspect_args[0] );

			// The archive passes the real validator independently.
			$validation = ( new Bricks_IE_Archive_Validator() )->validate( $out );
			bricks_ie_assert( ! is_wp_error( $validation ), 'validator should accept the export: ' . ( is_wp_error( $validation ) ? $validation->get_error_message() : '' ) );
			bricks_ie_assert_same( 2, $validation['schema_version'] );
		}
	);

	bricks_ie_test(
		'exporter v2: sensitive settings are included only when explicitly authorized',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();
			$package = bricks_ie_ex_native_package_bytes();
			$package = bricks_ie_ex_native_package_bytes();
			\Bricks\Unified_Global_Transfer::$inspect_result = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ), array( 'id' => 'clsTwo' ) ), 'templates' => array( array( 'id' => 'tplHeader' ) ), 'settings' => array( array( 'id' => 'general' ), array( 'id' => 'api-keys' ), array( 'id' => 'custom-code' ), array( 'id' => 'templates' ) ) ) ), 'zipHash' => hash( 'sha256', $package ), 'zipBytes' => strlen( $package ) );

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out, array( 'allow_sensitive_settings' => true ) );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'build_zip should succeed' );

			$export_args = bricks_ie_ex_last_native_call( 'export_package' );
			bricks_ie_assert_same(
				array( 'general', 'api-keys', 'custom-code', 'templates' ),
				$export_args[1]['settings'],
				'authorized export keeps sensitive settings tabs'
			);

			bricks_ie_assert(
				! in_array( 'sensitive_settings', bricks_ie_ex_omission_ids( $result['omissions'] ), true ),
				'no sensitive-settings omission when authorized'
			);
		}
	);

	bricks_ie_test(
		'exporter: duplicate post identities are rejected while cross-type slugs remain valid',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			$GLOBALS['bricks_ie_exporter_test']['posts'] = array(
				bricks_ie_ex_make_post( 30, 'page', 'section' ),
				bricks_ie_ex_make_post( 31, 'page', 'section' ),
				bricks_ie_ex_make_post( 40, 'page', 'id-41' ),
				bricks_ie_ex_make_post( 41, 'page', 'id-41' ),
			);
			foreach ( array( 30, 31, 40, 41 ) as $id ) {
				$GLOBALS['bricks_ie_exporter_test']['post_meta'][ $id ] = array( '_bricks_page_content_2' => array( 'id' => $id ) );
			}

			foreach ( array( 2, 1 ) as $schema ) {
				$dir = bricks_ie_test_temp_dir();
				$out = $dir . DIRECTORY_SEPARATOR . 'collision-' . $schema . '.zip';
				$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => $schema ) );
				bricks_ie_ex_assert_error( 'bricks_ie_duplicate_post_identity', $result );
				bricks_ie_assert( ! file_exists( $out ) );
			}

			// The same slug is a distinct importer identity when the type differs.
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( bricks_ie_ex_make_post( 50, 'page', 'same' ), bricks_ie_ex_make_post( 51, 'post', 'same' ) );
		foreach ( array( 50, 51 ) as $id ) { $GLOBALS['bricks_ie_exporter_test']['post_meta'][ $id ] = array( '_bricks_page_content_2' => array( 'id' => $id ) ); }
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'cross-type.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 2 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		}
	);

	bricks_ie_test(
		'exporter: download placeholder paths become zip paths and are cleaned by the caller',
		function () {
			$dir = bricks_ie_test_temp_dir();
			$placeholder = tempnam( $dir, 'bricks-ie-' ) . '.tmp';
			file_put_contents( $placeholder, 'placeholder' );
			$method = new ReflectionMethod( 'Bricks_IE_Exporter', 'prepare_download_archive_path' );
			$method->setAccessible( true );
			$archive = $method->invoke( new Bricks_IE_Exporter(), $placeholder );

			bricks_ie_assert( is_string( $archive ) && '.zip' === substr( $archive, -4 ), 'archive path must end in .zip' );
			bricks_ie_assert( file_exists( $archive ), 'renamed archive placeholder must exist' );
			bricks_ie_assert( ! file_exists( $placeholder ), 'the .tmp placeholder must be removed' );
			@unlink( $archive );
		}
	);

	bricks_ie_test( 'exporter: symlink destinations are rejected without changing the target', function () {
		if ( ! function_exists( 'symlink' ) ) { return; }
		bricks_ie_ex_reset();
		$dir = bricks_ie_test_temp_dir();
		$target = $dir . DIRECTORY_SEPARATOR . 'target.zip';
		$link = $dir . DIRECTORY_SEPARATOR . 'destination.zip';
		file_put_contents( $target, 'existing-target' );
		bricks_ie_assert( symlink( $target, $link ), 'could not create symlink fixture' );
		$result = ( new Bricks_IE_Exporter() )->build_zip( $link, array( 'schema' => 1 ) );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert( is_link( $link ), 'destination symlink must remain intact' );
		bricks_ie_assert_same( 'existing-target', file_get_contents( $target ), 'symlink target must remain unchanged' );
	} );

	bricks_ie_test(
		'exporter v2: request types restrict the native selection',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();

			\Bricks\Unified_Global_Transfer::$list_result = array(
				'types' => array(
					'classes' => array(
						array( 'id' => 'clsOne' ),
					),
				),
			);
			\Bricks\Unified_Global_Transfer::$inspect_result = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ) ) ) ), 'zipHash' => hash( 'sha256', bricks_ie_ex_native_package_bytes() ), 'zipBytes' => strlen( bricks_ie_ex_native_package_bytes() ) );

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out, array( 'types' => array( 'classes' ) ) );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'build_zip should succeed' );

			$list_args = bricks_ie_ex_last_native_call( 'list_export_items' );
			bricks_ie_assert_same( array( 'classes' ), $list_args[0], 'requested types must be passed to the list flow' );

			$members  = bricks_ie_ex_zip_members( $out );
			$manifest = json_decode( $members['manifest.json'], true );
			bricks_ie_assert_same( 1, $manifest['counts']['native_types'] );
			bricks_ie_assert_same( 1, $manifest['counts']['native_items'] );
			bricks_ie_assert_same( false, $manifest['domains']['posts'], 'no seeded posts in this scenario' );
			bricks_ie_assert( ! isset( $members['katsarov/posts/index.json'] ), 'no posts index without posts' );
		}
	);

	bricks_ie_test(
		'exporter v2: audited Bricks 2.4 descriptor list shape is handled',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			// Real `list_export_items` layout: a numeric list of type
			// descriptors, each carrying its own `id` and `items`.
			\Bricks\Unified_Global_Transfer::$list_result = array(
				'types' => array(
					array(
						'id'         => 'classes',
						'group'      => 'style',
						'label'      => 'Classes',
						'count'      => 2,
						'singleton'  => false,
						'items'      => array(
							array( 'id' => 'sminuo', 'label' => 'hover-opacity', 'category' => '', 'meta' => '' ),
							array( 'id' => 'frsrge', 'label' => 'nav-underline', 'category' => '', 'meta' => '' ),
						),
						'categories' => array(),
					),
					array(
						'id'         => 'templates',
						'group'      => 'template',
						'label'      => 'Templates',
						'count'      => 1,
						'singleton'  => false,
						'items'      => array(
							array( 'id' => '1775', 'label' => 'Site Header', 'category' => '', 'meta' => '' ),
						),
						'categories' => array(),
					),
					array(
						'id'         => 'settings',
						'group'      => 'settings',
						'label'      => 'Settings',
						'count'      => 8,
						'singleton'  => false,
						'items'      => array(
							array( 'id' => 'general' ),
							array( 'id' => 'templates' ),
							array( 'id' => 'builder' ),
							array( 'id' => 'performance' ),
							array( 'id' => 'maintenance' ),
							array( 'id' => 'api-keys' ),
							array( 'id' => 'custom-code' ),
							array( 'id' => 'woocommerce' ),
						),
						'categories' => array(),
					),
					array(
						'id'         => 'custom-capabilities',
						'group'      => 'settings',
						'label'      => 'Custom capabilities',
						'count'      => 0,
						'singleton'  => false,
						'items'      => array(),
						'categories' => array(),
					),
				),
			);
			\Bricks\Unified_Global_Transfer::$inspect_result = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( 'classes' => array( array( 'id' => 'sminuo' ), array( 'id' => 'frsrge' ) ), 'templates' => array( array( 'id' => '1775' ) ), 'settings' => array( array( 'id' => 'general' ), array( 'id' => 'builder' ), array( 'id' => 'performance' ), array( 'id' => 'maintenance' ), array( 'id' => 'woocommerce' ) ) ) ), 'zipHash' => hash( 'sha256', bricks_ie_ex_native_package_bytes() ), 'zipBytes' => strlen( bricks_ie_ex_native_package_bytes() ) );

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'build_zip should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );

			$export_args = bricks_ie_ex_last_native_call( 'export_package' );
			bricks_ie_assert_same( array( 'classes', 'templates', 'settings' ), $export_args[0], 'descriptor types with zero items must be dropped' );
			bricks_ie_assert_same( array( 'sminuo', 'frsrge' ), $export_args[1]['classes'] );
			bricks_ie_assert_same( array( '1775' ), $export_args[1]['templates'] );
			bricks_ie_assert_same(
				array( 'general', 'builder', 'performance', 'maintenance', 'woocommerce' ),
				$export_args[1]['settings'],
				'sensitive settings tabs must be excluded by default'
			);

			$members  = bricks_ie_ex_zip_members( $out );
			$manifest = json_decode( $members['manifest.json'], true );
			bricks_ie_assert_same( 3, $manifest['counts']['native_types'] );
			bricks_ie_assert_same( 8, $manifest['counts']['native_items'] );

			$validation = ( new Bricks_IE_Archive_Validator() )->validate( $out );
			bricks_ie_assert( ! is_wp_error( $validation ), 'validator should accept the export' );
		}
	);

	bricks_ie_test(
		'exporter v2: empty native item list omits the native package but stays schema 2',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_seed_posts();

			\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array() );

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'build_zip should succeed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			bricks_ie_assert_same( 2, $result['schema_version'] );
			bricks_ie_assert( ! bricks_ie_ex_native_called( 'export_package' ), 'nothing may be exported when the selection is empty' );

			$members  = bricks_ie_ex_zip_members( $out );
			$manifest = json_decode( $members['manifest.json'], true );
			bricks_ie_assert_same( false, $manifest['domains']['native_bricks'] );
			bricks_ie_assert_same( 0, $manifest['counts']['native_types'] );
			bricks_ie_assert_same( 0, $manifest['counts']['native_items'] );
			bricks_ie_assert( ! isset( $manifest['bricks']['package_sha256'] ), 'no package hash without a package' );
			bricks_ie_assert( ! isset( $members['bricks/package.zip'] ), 'no native package expected' );
			bricks_ie_assert( ! isset( $members['bricks/package.sha256'] ), 'no native checksum expected' );

			$validation = ( new Bricks_IE_Archive_Validator() )->validate( $out );
			bricks_ie_assert( ! is_wp_error( $validation ), 'validator should accept the export: ' . ( is_wp_error( $validation ) ? $validation->get_error_message() : '' ) );
		}
	);

	bricks_ie_test(
		'exporter v2: JSON-unsafe post meta is rejected and recorded, safe meta survives',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();

			$resource = fopen( 'php://memory', 'r' );

			$GLOBALS['bricks_ie_exporter_test']['posts'] = array(
				bricks_ie_ex_make_post( 20, 'page', 'safe', 'Safe' ),
				bricks_ie_ex_make_post( 21, 'page', 'mixed', 'Mixed' ),
				bricks_ie_ex_make_post( 22, 'page', 'only-unsafe', 'Only Unsafe' ),
			);
			$GLOBALS['bricks_ie_exporter_test']['post_meta'] = array(
				20 => array(
					'_bricks_page_content_2' => array( 'nested' => array( 'ok' => true, 'n' => 42 ) ),
				),
				21 => array(
					'_bricks_page_content_2' => array( 'fine' ),
					'_bricks_page_settings'  => array( 'bad' => new stdClass() ),
					'_bricks_page_header_2'  => $resource,
				),
				22 => array(
					'_bricks_page_content_2' => new stdClass(),
				),
			);

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			fclose( $resource );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'build_zip should succeed' );
			bricks_ie_assert_same( 2, $result['posts_count'], 'post 22 loses all meta and is dropped' );

			$members = bricks_ie_ex_zip_members( $out );
			bricks_ie_assert( isset( $members['katsarov/posts/page__safe.json'] ), 'safe post expected' );
			bricks_ie_assert( isset( $members['katsarov/posts/page__mixed.json'] ), 'mixed post expected' );
			bricks_ie_assert( ! isset( $members['katsarov/posts/page__only-unsafe.json'] ), 'post without safe meta must be dropped' );

			$mixed = json_decode( $members['katsarov/posts/page__mixed.json'], true );
			bricks_ie_assert_same( array( '_bricks_page_content_2' => array( 'fine' ) ), $mixed['meta'], 'unsafe meta keys must be rejected, safe keys kept' );

			$warning_text = implode( "\n", $result['warnings'] );
			bricks_ie_assert( false !== strpos( $warning_text, '_bricks_page_settings' ), 'object meta rejection must be reported' );
			bricks_ie_assert( false !== strpos( $warning_text, '_bricks_page_header_2' ), 'resource meta rejection must be reported' );
			bricks_ie_assert( false !== strpos( $warning_text, '_bricks_page_content_2' ), 'object-only meta rejection must be reported' );

			$validation = ( new Bricks_IE_Archive_Validator() )->validate( $out );
			bricks_ie_assert( ! is_wp_error( $validation ), 'validator should accept the export' );
		}
	);

	bricks_ie_test(
		'exporter v2: non-array request argument does not break existing calls',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out, 'not-an-array' );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'a legacy single-argument style call must keep working' );
			bricks_ie_assert_same( 2, $result['schema_version'] );
		}
	);

	// ---------------------------------------------------------------------
	// Tests: schema selection and fallback.
	// ---------------------------------------------------------------------

	bricks_ie_test(
		'exporter: missing native contract falls back to schema 1 with preserved result keys',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_seed_posts();

			$adapter  = new Bricks_IE_Bricks_Transfer_Adapter( array( 'native_class' => 'Bricks\Does_Not_Exist' ) );
			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter( array( 'adapter' => $adapter ) );
			$result   = $exporter->build_zip( $out );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'fallback build_zip should succeed' );
			bricks_ie_assert_same( $out, $result['file'] );
			bricks_ie_assert_same( 1, $result['options_count'] );
			bricks_ie_assert_same( 2, $result['posts_count'], 'legacy layout keeps templates in posts' );
			bricks_ie_assert_same( filesize( $out ), $result['size'] );
			bricks_ie_assert_same( 1, $result['schema_version'] );
			bricks_ie_assert( ! empty( $result['warnings'] ), 'fallback must be reported' );
			bricks_ie_assert( false !== strpos( implode( "\n", $result['warnings'] ), 'native_class_missing' ) );

			$members = bricks_ie_ex_zip_members( $out );
			$names   = array_keys( $members );
			sort( $names );
			bricks_ie_assert_same(
				array(
					'manifest.json',
					'options/bricks_global_settings.json',
					'posts/bricks_template__site-header.json',
					'posts/index.json',
					'posts/page__home.json',
				),
				$names,
				'legacy v1 layout expected'
			);

			$manifest = json_decode( $members['manifest.json'], true );
			bricks_ie_assert_same( 1, $manifest['version'] );
			bricks_ie_assert_same( '1.3.14', $manifest['bricks_version'] );

			// Legacy meta encoding (base64 of serialize) is preserved.
			$page = json_decode( $members['posts/page__home.json'], true );
			bricks_ie_assert_same(
				base64_encode( serialize( array( array( 'id' => 'abcd', 'name' => 'div', 'settings' => array( 'tag' => 'div' ) ) ) ) ),
				$page['meta']['_bricks_page_content_2']
			);

			bricks_ie_assert( ! bricks_ie_ex_native_called( 'export_package' ), 'fallback must not use the native flow' );
		}
	);

	bricks_ie_test(
		'exporter: schema 1 can be forced even when the native contract is verified',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out, array( 'schema' => 1 ) );

			bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ), 'forced schema 1 should succeed' );
			bricks_ie_assert_same( 1, $result['schema_version'] );
			bricks_ie_assert_same( 1, $result['options_count'] );

			$members = bricks_ie_ex_zip_members( $out );
			bricks_ie_assert( isset( $members['options/bricks_global_settings.json'] ), 'legacy options expected' );
			bricks_ie_assert( ! isset( $members['bricks/package.zip'] ), 'no native package in schema 1' );
			bricks_ie_assert( ! bricks_ie_ex_native_called( 'export_package' ), 'forced schema 1 must not use the native flow' );
		}
	);

	bricks_ie_test( 'exporter schema 1: automatic fallback strips Bricks 2.4 sensitive settings and retains ordinary values', function () {
		bricks_ie_ex_reset();
		bricks_ie_ex_seed_posts();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array( 'cssLoading' => 'file', 'ordinary' => 'keep', 'apiKeyGoogleMaps' => 'map-secret', 'customCode' => 'code-secret', 'executeCodeEnabled' => true, 'myTemplatesPassword' => 'template-secret' );
		$dir = bricks_ie_test_temp_dir(); $out = $dir . DIRECTORY_SEPARATOR . 'fallback-sensitive.zip';
		$result = ( new Bricks_IE_Exporter( array( 'adapter' => new Bricks_IE_Bricks_Transfer_Adapter( array( 'native_class' => 'Bricks\\Does_Not_Exist' ) ) ) ) )->build_zip( $out );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		$members = bricks_ie_ex_zip_members( $out );
		$settings = json_decode( $members['options/bricks_global_settings.json'], true );
		bricks_ie_assert_same( 'keep', $settings['ordinary'], 'ordinary setting missing' );
		foreach ( array( 'apiKeyGoogleMaps', 'customCode', 'executeCodeEnabled', 'myTemplatesPassword' ) as $key ) bricks_ie_assert( ! array_key_exists( $key, $settings ), $key . ' must be stripped from fallback export' );
		bricks_ie_assert( false !== strpos( implode( "\n", $result['warnings'] ), 'fallback' ), 'fallback warning missing: ' . var_export( $result['warnings'], true ) );
		bricks_ie_assert( in_array( 'sensitive_settings', bricks_ie_ex_omission_ids( $result['omissions'] ), true ), 'sensitive omission missing: ' . var_export( $result['omissions'], true ) );
	} );

	bricks_ie_test( 'exporter schema 1: explicit sensitive authorization retains Bricks 2.4 keys', function () {
		bricks_ie_ex_reset();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array( 'ordinary' => 'keep', 'apiKeyGoogleMaps' => 'map-secret', 'customCode' => 'code-secret', 'myTemplatesPassword' => 'template-secret' );
		$dir = bricks_ie_test_temp_dir(); $out = $dir . DIRECTORY_SEPARATOR . 'authorized-sensitive.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1, 'allow_sensitive_settings' => true ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		$settings = json_decode( bricks_ie_ex_zip_members( $out )['options/bricks_global_settings.json'], true );
		bricks_ie_assert_same( 'map-secret', $settings['apiKeyGoogleMaps'] );
		bricks_ie_assert_same( 'code-secret', $settings['customCode'] );
		bricks_ie_assert_same( 'template-secret', $settings['myTemplatesPassword'] );
	} );

	bricks_ie_test( 'exporter schema 1: sensitive aliases are recursively redacted with paths', function () {
		bricks_ie_ex_reset();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array(
			'ordinary' => 'keep',
			'nested' => array(
				'apiKeyGoogleMaps' => 'map-secret',
				'customCode' => 'code-secret',
				'children' => array(
					'password' => 'password-secret',
					'pass' => 'pass-secret',
					'ordinary' => 'nested-keep',
				),
			),
			'rows' => array( array( 'apiKey' => 'api-secret', 'ordinary' => 'row-keep' ) ),
		);
		$dir = bricks_ie_test_temp_dir(); $out = $dir . DIRECTORY_SEPARATOR . 'recursive-sensitive.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		$members = bricks_ie_ex_zip_members( $out );
		$settings = json_decode( $members['options/bricks_global_settings.json'], true );
		bricks_ie_assert_same( 'keep', $settings['ordinary'] );
		bricks_ie_assert_same( 'nested-keep', $settings['nested']['children']['ordinary'] );
		bricks_ie_assert_same( 'row-keep', $settings['rows'][0]['ordinary'] );
		foreach ( array( 'apiKeyGoogleMaps', 'customCode', 'password', 'pass', 'apiKey' ) as $key ) {
			bricks_ie_assert( false === strpos( $members['options/bricks_global_settings.json'], $key . '"' ), $key . ' must not be exported' );
		}
		$omission = null;
		foreach ( $result['omissions'] as $item ) { if ( 'sensitive_settings' === $item['id'] ) { $omission = $item; break; } }
		bricks_ie_assert( is_array( $omission ) );
		foreach ( array( 'nested.apiKeyGoogleMaps', 'nested.customCode', 'nested.children.password', 'nested.children.pass', 'rows.0.apiKey' ) as $path ) {
			bricks_ie_assert( in_array( $path, $omission['keys'], true ), $path . ' omission path missing' );
		}
		bricks_ie_assert( false === strpos( wp_json_encode( $result['omissions'] ), 'map-secret' ), 'omission metadata must not leak values' );
	} );

	bricks_ie_test( 'exporter schema 1: authorized recursive sensitive aliases are retained', function () {
		bricks_ie_ex_reset();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array( 'nested' => array( 'password' => 'secret', 'customCode' => 'code' ) );
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'recursive-authorized.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1, 'allow_sensitive_settings' => true ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		$settings = json_decode( bricks_ie_ex_zip_members( $out )['options/bricks_global_settings.json'], true );
		bricks_ie_assert_same( 'secret', $settings['nested']['password'] );
		bricks_ie_assert_same( 'code', $settings['nested']['customCode'] );
	} );

	bricks_ie_test( 'exporter schema 1: malformed non-array global settings fails and removes partial archive', function () {
		bricks_ie_ex_reset();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = 'not-an-array';
		$dir = bricks_ie_test_temp_dir(); $out = $dir . DIRECTORY_SEPARATOR . 'malformed-settings.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1 ) );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert( ! file_exists( $out ) );
	} );

	bricks_ie_test(
		'exporter: forcing schema 2 without a native contract fails closed',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_seed_posts();

			$adapter  = new Bricks_IE_Bricks_Transfer_Adapter( array( 'native_class' => 'Bricks\Does_Not_Exist' ) );
			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter( array( 'adapter' => $adapter ) );
			$result   = $exporter->build_zip( $out, array( 'schema' => 2 ) );

			bricks_ie_ex_assert_error( 'bricks_ie_native_transfer_unavailable', $result );
			bricks_ie_assert( ! file_exists( $out ), 'no archive may be left behind on failure' );
		}
	);

	bricks_ie_test(
		'exporter: an unsupported schema request is rejected',
		function () {
			bricks_ie_ex_reset();

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out, array( 'schema' => 3 ) );

			bricks_ie_ex_assert_error( 'bricks_ie_unsupported_schema', $result );
			bricks_ie_assert( ! file_exists( $out ) );
		}
	);

	// ---------------------------------------------------------------------
	// Tests: native flow failures.
	// ---------------------------------------------------------------------

	bricks_ie_test(
		'exporter v2: native export errors propagate and leave no output',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			\Bricks\Unified_Global_Transfer::$export_result = new WP_Error( 'bricks_ie_missing_permission', 'denied' );

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_ex_assert_error( 'bricks_ie_missing_permission', $result );
			bricks_ie_assert( ! file_exists( $out ), 'no archive may be left behind on failure' );
		}
	);

	bricks_ie_test(
		'exporter v2: invalid base64 native package is rejected',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			\Bricks\Unified_Global_Transfer::$export_result = array(
				'filename'  => 'bricks-global-data.zip',
				'zipBase64' => '!!!not-base64!!!',
				'zipHash'   => '',
				'zipBytes'  => 0,
				'manifest'  => array(),
			);

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_ex_assert_error( 'bricks_ie_native_package_invalid', $result );
			bricks_ie_assert( ! file_exists( $out ) );
		}
	);

	bricks_ie_test(
		'exporter v2: native package hash mismatch is rejected',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			$package = bricks_ie_ex_native_package_bytes();
			\Bricks\Unified_Global_Transfer::$export_result = array(
				'filename'  => 'bricks-global-data.zip',
				'zipBase64' => base64_encode( $package ),
				'zipHash'   => str_repeat( '0', 64 ),
				'zipBytes'  => strlen( $package ),
				'manifest'  => array(),
			);

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_ex_assert_error( 'bricks_ie_native_package_hash_mismatch', $result );
			bricks_ie_assert( ! file_exists( $out ) );
		}
	);

	bricks_ie_test(
		'exporter v2: inspect failures propagate before embedding',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			\Bricks\Unified_Global_Transfer::$inspect_result = new WP_Error( 'bricks_ie_package_empty', 'inspect failed' );

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_ex_assert_error( 'bricks_ie_package_empty', $result );
			bricks_ie_assert( ! file_exists( $out ) );
		}
	);

	bricks_ie_test(
		'exporter v2: a drifted inspect schema is rejected',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();
			$package = bricks_ie_ex_native_package_bytes();

			\Bricks\Unified_Global_Transfer::$inspect_result = array(
				'manifest' => array( 'schema' => 'bricks/something-else', 'version' => 1, 'types' => array() ),
				'zipHash'  => hash( 'sha256', $package ),
				'zipBytes' => strlen( $package ),
			);

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			$exporter = new Bricks_IE_Exporter();
			$result   = $exporter->build_zip( $out );

			bricks_ie_ex_assert_error( 'bricks_ie_native_result_invalid', $result );
			bricks_ie_assert( ! file_exists( $out ) );
		}
	);

	bricks_ie_test(
		'exporter v2: validation failure removes the temporary archive and preserves the destination',
		function () {
			bricks_ie_ex_reset();
			bricks_ie_ex_prime_native();
			bricks_ie_ex_seed_posts();

			if ( ! class_exists( 'Bricks_IE_Ex_Failing_Validator' ) ) {
				class Bricks_IE_Ex_Failing_Validator {
					public function validate( $path ) {
						return new WP_Error( 'invalid_zip_structure', 'simulated validation failure' );
					}
				}
			}

			$dir      = bricks_ie_test_temp_dir();
			$out      = $dir . DIRECTORY_SEPARATOR . 'export.zip';
			file_put_contents( $out, 'keep-existing-during-validation' );
			$exporter = new Bricks_IE_Exporter( array( 'validator' => new Bricks_IE_Ex_Failing_Validator() ) );
			$result   = $exporter->build_zip( $out );

			bricks_ie_ex_assert_error( 'bricks_ie_export_validation_failed', $result );
			bricks_ie_assert_same( 'invalid_zip_structure', $result->get_error_data()['validation_code'] );
			bricks_ie_assert_same( 'keep-existing-during-validation', file_get_contents( $out ), 'pre-publication validation failure must preserve the destination' );
			bricks_ie_assert_same( array(), glob( $dir . DIRECTORY_SEPARATOR . '.export.zip.*.zip' ), 'failed validation must remove only the temporary archive' );
		}
	);

	bricks_ie_test( 'exporter schema 1: nested remote-template passwords are redacted', function () {
		bricks_ie_ex_reset();
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array(
			'ordinary' => 'keep',
			'remoteTemplates' => array( array( 'url' => 'https://remote.example', 'password' => 'secret', 'name' => 'x' ) ),
		);
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'nested-sensitive.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		$settings = json_decode( bricks_ie_ex_zip_members( $out )['options/bricks_global_settings.json'], true );
		bricks_ie_assert( ! isset( $settings['remoteTemplates'][0]['password'] ) );
		bricks_ie_assert_same( 'x', $settings['remoteTemplates'][0]['name'] );
	} );

	bricks_ie_test( 'exporter v2: non-finite floats are omitted as unsafe meta', function () {
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( bricks_ie_ex_make_post( 70, 'page', 'finite' ), bricks_ie_ex_make_post( 71, 'page', 'infinite' ) );
		$GLOBALS['bricks_ie_exporter_test']['post_meta'] = array( 70 => array( '_bricks_page_content_2' => array( 'n' => 1.5 ) ), 71 => array( '_bricks_page_content_2' => INF ) );
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'nonfinite.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 2 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		bricks_ie_assert_same( 1, $result['posts_count'] );
	} );

	bricks_ie_test( 'exporter: failed replacement preserves an existing destination', function () {
		bricks_ie_ex_reset();
		$dir = bricks_ie_test_temp_dir(); $out = $dir . DIRECTORY_SEPARATOR . 'existing.zip';
		file_put_contents( $out, 'keep-me' );
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( bricks_ie_ex_make_post( 80, 'page', 'same' ), bricks_ie_ex_make_post( 81, 'page', 'same' ) );
		$GLOBALS['bricks_ie_exporter_test']['post_meta'] = array( 80 => array( '_bricks_page_content_2' => array( 1 ) ), 81 => array( '_bricks_page_content_2' => array( 2 ) ) );
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1 ) );
		bricks_ie_ex_assert_error( 'bricks_ie_duplicate_post_identity', $result );
		bricks_ie_assert_same( 'keep-me', file_get_contents( $out ) );
	} );

	bricks_ie_test( 'exporter: successful schema 1 export atomically replaces an existing destination', function () {
		bricks_ie_ex_reset();
		$dir = bricks_ie_test_temp_dir();
		$out = $dir . DIRECTORY_SEPARATOR . 'replace-v1.zip';
		file_put_contents( $out, 'old-schema-1-bytes' );

		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		bricks_ie_assert_same( 1, $result['schema_version'] );
		bricks_ie_assert( 'old-schema-1-bytes' !== file_get_contents( $out ), 'successful export must replace the destination' );
		bricks_ie_assert_same( filesize( $out ), $result['size'] );
		bricks_ie_assert_same( array(), glob( $dir . DIRECTORY_SEPARATOR . '.replace-v1.zip.*.zip' ), 'temporary archive must be published or removed' );
	} );

	bricks_ie_test( 'exporter: successful schema 2 export atomically replaces an existing destination', function () {
		bricks_ie_ex_reset();
		bricks_ie_ex_prime_native();
		$dir = bricks_ie_test_temp_dir();
		$out = $dir . DIRECTORY_SEPARATOR . 'replace-v2.zip';
		file_put_contents( $out, 'old-schema-2-bytes' );

		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 2 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		bricks_ie_assert_same( 2, $result['schema_version'] );
		bricks_ie_assert( 'old-schema-2-bytes' !== file_get_contents( $out ), 'successful export must replace the destination' );
		bricks_ie_assert_same( filesize( $out ), $result['size'] );
		bricks_ie_assert_same( array(), glob( $dir . DIRECTORY_SEPARATOR . '.replace-v2.zip.*.zip' ), 'temporary archive must be published or removed' );
	} );

	bricks_ie_test( 'exporter v2: default listing omissions are disclosed', function () {
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ) ) ) );
		$bytes = bricks_ie_ex_native_package_bytes();
		\Bricks\Unified_Global_Transfer::$inspect_result = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ) ) ) ), 'zipHash' => hash( 'sha256', $bytes ), 'zipBytes' => strlen( $bytes ) );
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'omissions.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		bricks_ie_assert( in_array( 'native_type_omitted', bricks_ie_ex_omission_ids( $result['omissions'] ), true ) );
		$sidecar = json_decode( bricks_ie_ex_zip_members( $out )['katsarov/export-warnings.json'], true );
		bricks_ie_assert( false !== strpos( implode( "\n", $sidecar['warnings'] ), 'omitted' ) );
	} );

	bricks_ie_test( 'exporter v2: explicitly requested omitted type fails closed', function () {
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array() );
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'requested-omission.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'types' => array( 'classes' ) ) );
		bricks_ie_ex_assert_error( 'bricks_ie_requested_native_type_omitted', $result );
	} );

	bricks_ie_test( 'exporter v2: inspected dependency types drive counts', function () {
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ) ) ) );
		$bytes = bricks_ie_ex_native_package_bytes();
		\Bricks\Unified_Global_Transfer::$inspect_result = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ) ), 'variables' => array( array( 'id' => 'depVar' ) ), 'components' => array( array( 'id' => 'depComponent' ) ) ) ), 'zipHash' => hash( 'sha256', $bytes ), 'zipBytes' => strlen( $bytes ) );
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'dependencies.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out );
		bricks_ie_assert_same( 3, $result['native']['types'] );
		bricks_ie_assert_same( 3, $result['native']['items'] );
	} );

	bricks_ie_test( 'exporter v2: inspected package dropping a selected item is rejected', function () {
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		\Bricks\Unified_Global_Transfer::$list_result = array( 'types' => array( 'classes' => array( array( 'id' => 'clsOne' ) ) ) );
		$bytes = bricks_ie_ex_native_package_bytes();
		\Bricks\Unified_Global_Transfer::$inspect_result = array( 'manifest' => array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1, 'types' => array( 'classes' => array( array( 'id' => 'other' ) ) ) ), 'zipHash' => hash( 'sha256', $bytes ), 'zipBytes' => strlen( $bytes ) );
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'dropped.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out );
		bricks_ie_ex_assert_error( 'bricks_ie_native_selected_item_dropped', $result );
	} );

	bricks_ie_test( 'exporter schema 1: manifest carries disclosure fields', function () {
		bricks_ie_ex_reset(); bricks_ie_ex_prime_native();
		$out = bricks_ie_test_temp_dir() . DIRECTORY_SEPARATOR . 'v1-disclosure.zip';
		$result = ( new Bricks_IE_Exporter() )->build_zip( $out, array( 'schema' => 1 ) );
		bricks_ie_assert( is_array( $result ) && ! is_wp_error( $result ) );
		$manifest = json_decode( bricks_ie_ex_zip_members( $out )['manifest.json'], true );
		bricks_ie_assert( array_key_exists( 'warnings', $manifest ) );
		bricks_ie_assert( array_key_exists( 'omissions', $manifest ) );
		bricks_ie_assert( ! empty( $manifest['warnings'] ) );
		// Do not leak the exporter-specific inspection fixture into subsequent
		// preflight tests; retain their permission fixture state.
		\Bricks\Unified_Global_Transfer::$list_result = null;
		\Bricks\Unified_Global_Transfer::$export_result = null;
		\Bricks\Unified_Global_Transfer::$inspect_result = null;
	} );
}
