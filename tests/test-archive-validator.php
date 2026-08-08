<?php
/**
 * Tests for Bricks_IE_Archive_Validator.
 *
 * Runs under the plugin-local harness (tests/bootstrap.php + tests/run.php)
 * or standalone via `php tests/test-archive-validator.php`. Local fallback
 * guards below keep this file self-contained without editing harness files.
 *
 * All fixtures are built in memory or below the system temporary directory.
 * No database, option, or post writes happen anywhere in these tests.
 */

// -------------------------------------------------------------------------
// Bootstrap: prefer the shared harness, fall back to local guards.
// -------------------------------------------------------------------------

if ( ! function_exists( 'bricks_ie_test' ) ) {
	$bricks_ie_av_bootstrap = __DIR__ . '/bootstrap.php';

	if ( file_exists( $bricks_ie_av_bootstrap ) ) {
		require_once $bricks_ie_av_bootstrap;
	} else {
		// Minimal local fallback in case the harness is not available yet.
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

		register_shutdown_function(
			function () {
				foreach ( $GLOBALS['bricks_ie_test_temp_dirs'] as $directory ) {
					bricks_ie_remove_test_temp_path( $directory );
				}
			}
		);

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
	}
}

// The validator uses __() for messages; the harness intentionally has no i18n.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Optional filter shim so filter behavior can be tested without WordPress.
// Only used when no real apply_filters() implementation is present.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( isset( $GLOBALS['bricks_ie_av_filter_overrides'][ $tag ] ) ) {
			return $GLOBALS['bricks_ie_av_filter_overrides'][ $tag ];
		}
		return $value;
	}

	define( 'BRICKS_IE_AV_LOCAL_FILTERS', true );
}

if ( ! class_exists( 'Bricks_IE_Archive_Validator' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-archive-validator.php';
}

// -------------------------------------------------------------------------
// Fixture helpers.
// -------------------------------------------------------------------------

/**
 * Apply overrides to a fixture file map. A null value removes the entry.
 */
function bricks_ie_av_apply_overrides( array $files, array $overrides ) {
	foreach ( $overrides as $name => $content ) {
		if ( null === $content ) {
			unset( $files[ $name ] );
		} else {
			$files[ $name ] = $content;
		}
	}

	return $files;
}

/**
 * Build a zip archive from a name => content map below a temp directory.
 */
function bricks_ie_av_make_zip( array $files, $name = 'archive.zip' ) {
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

/**
 * Build raw zip bytes from a list of entry tuples.
 *
 * Unlike ZipArchive this allows duplicate names, null bytes, and other
 * hostile entry names needed by the security fixtures.
 *
 * Each entry is [ name, content ] for a stored entry, or
 * [ name, compressed content, method, declared uncompressed size ] for an
 * entry whose data is written verbatim as the declared compression method
 * (used to build unreadable-stream fixtures).
 */
function bricks_ie_av_raw_zip( array $entries ) {
	$local   = '';
	$central = '';
	$offset  = 0;
	$count   = 0;

	foreach ( $entries as $entry ) {
		$name    = $entry[0];
		$content = $entry[1];
		$method  = isset( $entry[2] ) ? (int) $entry[2] : 0;
		$size    = ( 0 === $method || ! isset( $entry[3] ) ) ? strlen( $content ) : (int) $entry[3];

		$crc       = crc32( $content );
		$comp_size = strlen( $content );

		$local_header = pack( 'V', 0x04034b50 )
			. pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', $method )
			. pack( 'v', 0 ) . pack( 'v', 0x21 )
			. pack( 'V', $crc ) . pack( 'V', $comp_size ) . pack( 'V', $size )
			. pack( 'v', strlen( $name ) ) . pack( 'v', 0 )
			. $name;

		$central .= pack( 'V', 0x02014b50 )
			. pack( 'v', 20 ) . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', $method )
			. pack( 'v', 0 ) . pack( 'v', 0x21 )
			. pack( 'V', $crc ) . pack( 'V', $comp_size ) . pack( 'V', $size )
			. pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . pack( 'v', 0 )
			. pack( 'v', 0 ) . pack( 'v', 0 )
			. pack( 'V', 0 ) . pack( 'V', $offset )
			. $name;

		$local  .= $local_header . $content;
		$offset += strlen( $local_header ) + $comp_size;
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
 * Write a raw zip (list of [ name, content ] pairs) below a temp directory.
 */
function bricks_ie_av_write_raw_zip( array $entries, $name = 'raw.zip' ) {
	$directory = bricks_ie_test_temp_dir();
	$path      = $directory . DIRECTORY_SEPARATOR . $name;

	bricks_ie_assert( false !== file_put_contents( $path, bricks_ie_av_raw_zip( $entries ) ), 'Could not write raw zip fixture.' );

	return $path;
}

/**
 * Default schema version 1 fixture file map (matches plugin 1.0.x output).
 */
function bricks_ie_av_v1_files( array $overrides = array() ) {
	$files = array(
		'manifest.json'                       => json_encode(
			array(
				'version'        => 1,
				'plugin_version' => '1.0.1',
				'generated_at'   => '2026-08-07T00:00:00+00:00',
				'site_url'       => 'https://source.example',
				'bricks_version' => '1.3.14',
				'counts'         => array(
					'options' => 1,
					'posts'   => 1,
				),
			)
		),
		'options/bricks_global_settings.json' => json_encode( array( 'postTypes' => array() ) ),
		'posts/index.json'                    => json_encode(
			array(
				array(
					'slug' => 'home',
					'type' => 'page',
					'file' => 'page__home.json',
				),
			)
		),
		'posts/page__home.json'               => json_encode(
			array(
				'id'     => 10,
				'slug'   => 'home',
				'type'   => 'page',
				'status' => 'publish',
				'title'  => 'Home',
				'meta'   => array(
					'_bricks_page_content_2' => base64_encode( serialize( array( 'element' => 'x' ) ) ),
				),
			)
		),
	);

	return bricks_ie_av_apply_overrides( $files, $overrides );
}

/**
 * Opaque fake native Bricks package bytes (a real minimal zip).
 */
function bricks_ie_av_native_package_bytes() {
	return bricks_ie_av_raw_zip(
		array(
			array( 'manifest.json', '{"schema":"bricks/unified-global-transfer","version":1}' ),
		)
	);
}

/**
 * Build deflate-compressed zip bytes from a name => content map.
 *
 * Unlike the stored raw zip helper, ZipArchive applies deflate compression,
 * which the nested compression-ratio fixtures need.
 */
function bricks_ie_av_compressed_zip_bytes( array $files ) {
	$directory = bricks_ie_test_temp_dir();
	$path      = $directory . DIRECTORY_SEPARATOR . 'compressed-' . bin2hex( random_bytes( 4 ) ) . '.zip';

	$zip = new ZipArchive();
	bricks_ie_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Could not create compressed fixture zip.' );

	foreach ( $files as $entry_name => $content ) {
		bricks_ie_assert( true === $zip->addFromString( $entry_name, $content ), 'Could not add fixture entry: ' . $entry_name );
	}

	bricks_ie_assert( true === $zip->close(), 'Could not close fixture zip.' );

	$bytes = file_get_contents( $path );
	bricks_ie_assert( false !== $bytes && strlen( $bytes ) > 4, 'Could not read compressed fixture zip.' );

	return $bytes;
}

/**
 * Build the bytes of a valid empty ZIP archive.
 */
function bricks_ie_av_empty_zip_bytes() {
	// A valid empty archive is just the EOCD record. ZipArchive may remove an
	// empty output file on close, so keep this fixture independent of that
	// implementation detail.
	return pack( 'V', 0x06054b50 ) . str_repeat( "\0", 18 );
}

/**
 * Build a schema version 2 file map around explicit native package bytes.
 *
 * Recomputes bricks/package.sha256 and the manifest hash so the archive
 * passes the integrity checks and reaches the nested validation.
 */
function bricks_ie_av_v2_files_with_native_bytes( $package_bytes, array $manifest_overrides = array() ) {
	$sha = hash( 'sha256', $package_bytes );

	return bricks_ie_av_v2_files(
		array(
			'bricks/package.zip'    => $package_bytes,
			'bricks/package.sha256' => $sha . "  package.zip\n",
		),
		array_replace_recursive(
			array( 'bricks' => array( 'package_sha256' => $sha ) ),
			$manifest_overrides
		)
	);
}

/**
 * Default schema version 2 fixture file map.
 */
function bricks_ie_av_v2_files( array $file_overrides = array(), array $manifest_overrides = array() ) {
	$package = bricks_ie_av_native_package_bytes();
	$sha     = hash( 'sha256', $package );

	$manifest = array(
		'format'            => 'katsarov/bricks-import-export',
		'version'           => 2,
		'plugin_version'    => '1.1.0',
		'generated_at'      => '2026-08-07T00:00:00Z',
		'site_url'          => 'https://source.example',
		'wordpress_version' => '6.7',
		'php_version'       => '7.4',
		'bricks'            => array(
			'version'        => '2.4-beta2',
			'native_schema'  => 'bricks/unified-global-transfer',
			'native_version' => 1,
			'package_sha256' => $sha,
		),
		'domains'           => array(
			'native_bricks'       => true,
			'posts'               => true,
			'template_conditions' => false,
			'media_files'         => false,
		),
		'counts'            => array(
			'native_types' => 2,
			'native_items' => 5,
			'posts'        => 1,
		),
		'warnings'          => array(),
	);

	if ( ! empty( $manifest_overrides ) ) {
		$manifest = array_replace_recursive( $manifest, $manifest_overrides );
	}

	$files = array(
		'manifest.json'                  => json_encode( $manifest ),
		'katsarov/export-warnings.json'  => json_encode( array( 'schema_version' => 2, 'warnings' => array(), 'omissions' => array() ) ),
		'bricks/package.zip'             => $package,
		'bricks/package.sha256'          => $sha . "  package.zip\n",
		'katsarov/posts/index.json'      => json_encode(
			array(
				array(
					'slug' => 'home',
					'type' => 'page',
					'file' => 'page__home.json',
				),
			)
		),
		'katsarov/posts/page__home.json' => json_encode(
			array(
				'id'     => 10,
				'slug'   => 'home',
				'type'   => 'page',
				'status' => 'publish',
				'title'  => 'Home',
				'meta'   => array(
					'_bricks_page_content_2' => array( 'element' => 'x' ),
				),
			)
		),
	);

	return bricks_ie_av_apply_overrides( $files, $file_overrides );
}

// -------------------------------------------------------------------------
// Assertion helpers.
// -------------------------------------------------------------------------

function bricks_ie_av_assert_error( $expected_code, $result, $message = '' ) {
	if ( ! is_wp_error( $result ) ) {
		throw new RuntimeException(
			$message . ' Expected WP_Error "' . $expected_code . '", got: '
			. ( is_array( $result ) ? 'valid report for schema ' . var_export( isset( $result['schema_version'] ) ? $result['schema_version'] : null, true ) : var_export( $result, true ) )
		);
	}

	bricks_ie_assert_same( $expected_code, $result->get_error_code(), $message );
}

function bricks_ie_av_assert_any_error( array $expected_codes, $result, $message = '' ) {
	if ( ! is_wp_error( $result ) ) {
		throw new RuntimeException( $message . ' Expected a WP_Error, got a non-error result.' );
	}

	bricks_ie_assert(
		in_array( $result->get_error_code(), $expected_codes, true ),
		$message . ' Expected one of ' . implode( ', ', $expected_codes ) . ', got ' . $result->get_error_code() . '.'
	);
}

function bricks_ie_av_assert_valid( $expected_schema, $result, $message = '' ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException(
			$message . ' Expected a valid report, got WP_Error "' . $result->get_error_code() . '": ' . $result->get_error_message()
		);
	}

	bricks_ie_assert( is_array( $result ), $message . ' Expected a report array.' );
	bricks_ie_assert_same( $expected_schema, $result['schema_version'], $message );
}

// -------------------------------------------------------------------------
// Tests: happy paths.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: valid schema version 1 archive passes',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files() ) );

		bricks_ie_av_assert_valid( 1, $result );
		bricks_ie_assert_same( 1, $result['manifest']['version'] );
		bricks_ie_assert_same( array( 'options/bricks_global_settings.json' ), $result['option_files'] );
		bricks_ie_assert_same( array( 'posts/page__home.json' ), $result['post_files'] );
		bricks_ie_assert_same( 1, count( $result['posts_index'] ) );
		bricks_ie_assert_same( 4, $result['entry_count'] );
		bricks_ie_assert( $result['compressed_size'] > 0, 'Compressed size should be reported.' );
		bricks_ie_assert( $result['uncompressed_size'] > 0, 'Uncompressed size should be reported.' );
		bricks_ie_assert( null === $result['native_package'], 'Schema 1 has no native package.' );
		bricks_ie_assert( ! empty( $result['warnings'] ), 'Schema 1 should report a legacy warning.' );
	}
);

bricks_ie_test(
	'archive validator: valid schema version 2 archive passes',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files() ) );

		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert_same( 'katsarov/bricks-import-export', $result['manifest']['format'] );
		bricks_ie_assert( is_array( $result['native_package'] ), 'Native package should be reported.' );
		bricks_ie_assert_same( hash( 'sha256', bricks_ie_av_native_package_bytes() ), $result['native_package']['sha256'] );
		bricks_ie_assert_same( strlen( bricks_ie_av_native_package_bytes() ), $result['native_package']['size'] );
		bricks_ie_assert_same( array( 'katsarov/posts/page__home.json' ), $result['post_files'] );
		bricks_ie_assert_same( array(), $result['option_files'] );
	}
);

bricks_ie_test(
	'archive validator: schema version 2 archive without native package or posts passes',
	function () {
		$files = bricks_ie_av_v2_files(
			array(
				'bricks/package.zip'             => null,
				'bricks/package.sha256'          => null,
				'katsarov/posts/index.json'      => null,
				'katsarov/posts/page__home.json' => null,
			),
			array(
				'bricks'  => array( 'package_sha256' => '' ),
				'domains' => array(
					'native_bricks' => false,
					'posts'         => false,
				),
				'counts'  => array(
					'native_types' => 0,
					'native_items' => 0,
					'posts'        => 0,
				),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert( null === $result['native_package'], 'No native package expected.' );
		bricks_ie_assert_same( array(), $result['post_files'] );
	}
);

bricks_ie_test(
	'archive validator: schema 2 warnings sidecar is required and propagated',
	function () {
		$files = bricks_ie_av_v2_files(
			array( 'katsarov/export-warnings.json' => json_encode( array( 'schema_version' => 2, 'warnings' => array( 'one warning' ), 'omissions' => array( array( 'id' => 'media_files', 'message' => 'not transported' ) ) ) ) )
		);
		$result = ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) );
		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert_same( array( 'one warning' ), $result['warnings'] );
		bricks_ie_assert_same( array( array( 'id' => 'media_files', 'message' => 'not transported' ) ), $result['omissions'] );

		$files = bricks_ie_av_v2_files( array( 'katsarov/export-warnings.json' => null ) );
		bricks_ie_av_assert_error( 'missing_export_warnings', ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: malformed warnings sidecar is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array( 'katsarov/export-warnings.json' => json_encode( array( 'schema_version' => 2, 'warnings' => array( 7 ), 'omissions' => array() ) ) ) );
		bricks_ie_av_assert_error( 'invalid_export_warnings', ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema version 1 accepts explicit directory entries',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'options/' => '',
				'posts/'   => '',
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_valid( 1, $result );
	}
);

bricks_ie_test(
	'archive validator: uppercase .ZIP extension is accepted',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files(), 'archive.ZIP' ) );

		bricks_ie_av_assert_valid( 1, $result );
	}
);

bricks_ie_test(
	'archive validator: schema version 2 template conditions sidecar passes when declared',
	function () {
		$files = bricks_ie_av_v2_files(
			array(
				'katsarov/template-conditions.json' => json_encode( array() ),
			),
			array(
				'domains' => array( 'template_conditions' => true ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert( ! empty( $result['warnings'] ), 'Template conditions should add a review warning.' );
	}
);

bricks_ie_test(
	'archive validator: missing external-attribute capability fails closed on PHP 7.4-compatible fake archive',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$method = new ReflectionMethod( $validator, 'validate_external_attributes_support' );
		$method->setAccessible( true );
		$fake = new class {
			public function numFiles() { return 0; }
		};
		$result = $method->invoke( $validator, $fake );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'zip_external_attributes_unavailable', $result->get_error_code() );
	}
);

// -------------------------------------------------------------------------
// Tests: file-level checks.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: missing file is rejected',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'file_not_found', $validator->validate( '/nonexistent/bricks-ie/archive.zip' ) );
	}
);

bricks_ie_test(
	'archive validator: non-zip extension is rejected',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files(), 'archive.txt' ) );

		bricks_ie_av_assert_error( 'invalid_extension', $result );
	}
);

bricks_ie_test(
	'archive validator: file without zip signature is rejected',
	function () {
		$directory = bricks_ie_test_temp_dir();
		$path      = $directory . '/not-a-zip.zip';
		bricks_ie_assert( false !== file_put_contents( $path, 'This is definitely not a zip archive.' ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'invalid_zip_signature', $validator->validate( $path ) );
	}
);

bricks_ie_test(
	'archive validator: empty file with zip extension is rejected',
	function () {
		$directory = bricks_ie_test_temp_dir();
		$path      = $directory . '/empty.zip';
		bricks_ie_assert( false !== file_put_contents( $path, '' ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'invalid_zip_signature', $validator->validate( $path ) );
	}
);

// -------------------------------------------------------------------------
// Tests: limits.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: compressed size limit is enforced',
	function () {
		$validator = new Bricks_IE_Archive_Validator( array( 'max_compressed_size' => 10 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files() ) );

		bricks_ie_av_assert_error( 'archive_too_large', $result );
	}
);

bricks_ie_test(
	'archive validator: entry count limit is enforced',
	function () {
		$validator = new Bricks_IE_Archive_Validator( array( 'max_entries' => 3 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files() ) );

		bricks_ie_av_assert_error( 'too_many_entries', $result );
	}
);

bricks_ie_test(
	'archive validator: per-entry size limit is enforced',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'options/bricks_global_settings.json' => json_encode( array( 'padding' => str_repeat( 'x', 400 ) ) ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator( array( 'max_json_member_size' => 64 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_error( 'entry_too_large', $result );
	}
);

bricks_ie_test(
	'archive validator: native package limit is enforced independently',
	function () {
		$validator = new Bricks_IE_Archive_Validator( array( 'max_native_package_size' => 32 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files() ) );

		bricks_ie_av_assert_error( 'native_package_too_large', $result );
	}
);

bricks_ie_test(
	'archive validator: aggregate uncompressed limit is enforced',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'options/bricks_global_settings.json' => json_encode( array( 'padding' => str_repeat( 'x', 400 ) ) ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator( array( 'max_uncompressed_size' => 100 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_error( 'total_uncompressed_too_large', $result );
	}
);

bricks_ie_test(
	'archive validator: compression ratio limit is enforced',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				// Highly compressible payload: ratio far above the default 100:1.
				'options/bricks_global_settings.json' => str_repeat( 'a', 200000 ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_error( 'compression_ratio_exceeded', $result );
	}
);

bricks_ie_test(
	'archive validator: JSON depth limit is enforced',
	function () {
		$deep = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$deep = array( 'level' => $deep );
		}

		$files = bricks_ie_av_v1_files(
			array(
				'options/bricks_global_settings.json' => json_encode( $deep ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator( array( 'max_json_depth' => 4 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( $files ) );

		bricks_ie_av_assert_error( 'json_too_deep', $result );
	}
);

bricks_ie_test(
	'archive validator: get_limits reports defaults and overrides',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$limits    = $validator->get_limits();

		foreach ( $limits as $key => $value ) {
			bricks_ie_assert( is_int( $value ) && $value > 0, "Limit {$key} should be a positive integer." );
		}

		bricks_ie_assert_same( 33554432, $limits['max_native_package_size'], 'Native package limit should default to 32 MiB.' );

		$custom = new Bricks_IE_Archive_Validator( array( 'max_entries' => 5 ) );
		bricks_ie_assert_same( 5, $custom->get_limits()['max_entries'] );
	}
);

if ( defined( 'BRICKS_IE_AV_LOCAL_FILTERS' ) ) {
	bricks_ie_test(
		'archive validator: limits are filterable when apply_filters is available',
		function () {
			$GLOBALS['bricks_ie_av_filter_overrides']['bricks_ie_archive_max_entries'] = 3;

			$validator = new Bricks_IE_Archive_Validator();
			$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files() ) );

			unset( $GLOBALS['bricks_ie_av_filter_overrides']['bricks_ie_archive_max_entries'] );

			bricks_ie_av_assert_error( 'too_many_entries', $result );
		}
	);
}

// -------------------------------------------------------------------------
// Tests: unsafe entry paths, duplicates, symlinks.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: absolute paths are rejected',
	function () {
		$manifest = json_encode( array( 'version' => 1, 'bricks_version' => '1.3.14', 'counts' => array( 'options' => 0, 'posts' => 0 ) ) );
		$path     = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( '/etc/passwd', 'x' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unsafe_entry_path', $validator->validate( $path ) );
	}
);

bricks_ie_test(
	'archive validator: backslash paths are rejected',
	function () {
		$manifest = json_encode( array( 'version' => 1, 'bricks_version' => '1.3.14', 'counts' => array( 'options' => 0, 'posts' => 0 ) ) );
		$path     = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( 'foo\\bar.json', 'x' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unsafe_entry_path', $validator->validate( $path ) );
	}
);

bricks_ie_test(
	'archive validator: traversal segments are rejected',
	function () {
		$manifest  = json_encode( array( 'version' => 1, 'bricks_version' => '1.3.14', 'counts' => array( 'options' => 0, 'posts' => 0 ) ) );
		$validator = new Bricks_IE_Archive_Validator();

		$path = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( '../evil.json', 'x' ),
			)
		);
		bricks_ie_av_assert_error( 'unsafe_entry_path', $validator->validate( $path ), 'Parent traversal.' );

		$path = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( 'options/../../evil.json', 'x' ),
			)
		);
		bricks_ie_av_assert_error( 'unsafe_entry_path', $validator->validate( $path ), 'Nested traversal.' );

		$path = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( 'options/./a.json', 'x' ),
			)
		);
		bricks_ie_av_assert_error( 'unsafe_entry_path', $validator->validate( $path ), 'Dot segment.' );
	}
);

bricks_ie_test(
	'archive validator: null bytes in entry names are rejected',
	function () {
		$manifest = json_encode( array( 'version' => 1, 'bricks_version' => '1.3.14', 'counts' => array( 'options' => 0, 'posts' => 0 ) ) );
		$path     = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( "options/bad\0name.json", 'x' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();

		// When the underlying libzip preserves the null byte, the explicit
		// null-byte check rejects the entry. Some libzip versions sanitize the
		// byte before PHP sees it; the strict layout allowlist then rejects the
		// entry instead. Either way the entry is rejected before any read.
		bricks_ie_av_assert_any_error(
			array( 'unsafe_entry_path', 'unexpected_entry' ),
			$validator->validate( $path )
		);
	}
);

bricks_ie_test(
	'archive validator: duplicate entries are rejected',
	function () {
		$manifest = json_encode( array( 'version' => 1, 'bricks_version' => '1.3.14', 'counts' => array( 'options' => 0, 'posts' => 0 ) ) );
		$path     = bricks_ie_av_write_raw_zip(
			array(
				array( 'manifest.json', $manifest ),
				array( 'options/a.json', '{}' ),
				array( 'options/a.json', '{}' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'duplicate_entry', $validator->validate( $path ) );
	}
);

if ( method_exists( 'ZipArchive', 'setExternalAttributesName' ) ) {
	bricks_ie_test(
		'archive validator: symlink-like entries are rejected',
		function () {
			$directory = bricks_ie_test_temp_dir();
			$path      = $directory . '/symlink.zip';

			$zip = new ZipArchive();
			bricks_ie_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
			bricks_ie_assert( true === $zip->addFromString( 'options/evil.json', '{}' ) );
			bricks_ie_assert( true === $zip->addFromString( 'manifest.json', '{}' ) );
			bricks_ie_assert(
				true === $zip->setExternalAttributesName( 'options/evil.json', ZipArchive::OPSYS_UNIX, ( 0xA000 | 0777 ) << 16 ),
				'Could not mark fixture entry as symlink.'
			);
			bricks_ie_assert( true === $zip->close() );

			$validator = new Bricks_IE_Archive_Validator();
			bricks_ie_av_assert_error( 'symlink_entry', $validator->validate( $path ) );
		}
	);
}

// -------------------------------------------------------------------------
// Tests: manifest handling.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: missing manifest is rejected',
	function () {
		$path = bricks_ie_av_write_raw_zip(
			array(
				array( 'options/a.json', '{}' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'missing_manifest', $validator->validate( $path ) );
	}
);

bricks_ie_test(
	'archive validator: malformed manifest JSON is rejected',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files( array( 'manifest.json' => '{broken' ) ) ) );

		bricks_ie_av_assert_error( 'invalid_json', $result );
	}
);

bricks_ie_test(
	'archive validator: manifest without version is rejected',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files( array( 'manifest.json' => '{}' ) ) ) );

		bricks_ie_av_assert_error( 'invalid_manifest', $result );
	}
);

bricks_ie_test(
	'archive validator: unsupported schema version is rejected',
	function () {
		$files = bricks_ie_av_v1_files();

		$manifest                 = json_decode( $files['manifest.json'], true );
		$manifest['version']      = 3;
		$files['manifest.json']   = json_encode( $manifest );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unsupported_schema_version', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: unexpected top-level entries are rejected',
	function () {
		$validator = new Bricks_IE_Archive_Validator();

		$result = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files( array( 'readme.txt' => 'hi' ) ) ) );
		bricks_ie_av_assert_error( 'unexpected_entry', $result, 'Schema 1 stray file.' );

		$result = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files( array( 'katsarov/extra.json' => '{}' ) ) ) );
		bricks_ie_av_assert_error( 'unexpected_entry', $result, 'Schema 2 stray file.' );

		$result = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v1_files( array( 'bricks/package.zip' => 'PK' ) ) ) );
		bricks_ie_av_assert_error( 'unexpected_entry', $result, 'Schema 1 must not contain schema 2 members.' );
	}
);

// -------------------------------------------------------------------------
// Tests: schema version 1 consistency.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: schema 1 requires a Bricks version',
	function () {
		$files = bricks_ie_av_v1_files();

		$manifest = json_decode( $files['manifest.json'], true );
		unset( $manifest['bricks_version'] );
		$files['manifest.json'] = json_encode( $manifest );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'no_bricks_version', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 option count mismatch is rejected',
	function () {
		$files = bricks_ie_av_v1_files();

		$manifest                        = json_decode( $files['manifest.json'], true );
		$manifest['counts']['options']   = 5;
		$files['manifest.json']          = json_encode( $manifest );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'manifest_count_mismatch', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 post count mismatch is rejected',
	function () {
		$files = bricks_ie_av_v1_files();

		$manifest                      = json_decode( $files['manifest.json'], true );
		$manifest['counts']['posts']   = 2;
		$files['manifest.json']        = json_encode( $manifest );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'manifest_count_mismatch', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 index listing a missing post file is rejected',
	function () {
		$files = bricks_ie_av_v1_files();

		$index    = json_decode( $files['posts/index.json'], true );
		$index[]  = array( 'slug' => 'about', 'type' => 'page', 'file' => 'page__about.json' );
		$files['posts/index.json'] = json_encode( $index );

		$manifest                      = json_decode( $files['manifest.json'], true );
		$manifest['counts']['posts']   = 2;
		$files['manifest.json']        = json_encode( $manifest );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'missing_post_file', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 post file missing from the index is rejected',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'posts/orphan.json' => json_encode(
					array(
						'id'     => 11,
						'slug'   => 'orphan',
						'type'   => 'page',
						'status' => 'publish',
						'title'  => 'Orphan',
					)
				),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unlisted_post_file', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 post files without an index are rejected',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'posts/index.json' => null,
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'missing_index', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 payload not matching the index is rejected',
	function () {
		$files = bricks_ie_av_v1_files();

		$payload                 = json_decode( $files['posts/page__home.json'], true );
		$payload['slug']         = 'other';
		$files['posts/page__home.json'] = json_encode( $payload );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'index_payload_mismatch', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 1 malformed option JSON is rejected',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'options/bricks_global_settings.json' => 'not json at all',
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'invalid_json', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: post source IDs must be positive JSON integers and unique',
	function () {
		$files = bricks_ie_av_v1_files();
		$payload = json_decode( $files['posts/page__home.json'], true );
		$payload['id'] = '10';
		$files['posts/page__home.json'] = json_encode( $payload );
		bricks_ie_av_assert_error( 'invalid_post', ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ), 'Numeric strings are not source IDs.' );

		$files = bricks_ie_av_v1_files(
			array(
				'posts/index.json' => json_encode( array( array( 'slug' => 'home', 'type' => 'page', 'file' => 'page__home.json' ), array( 'slug' => 'about', 'type' => 'page', 'file' => 'page__about.json' ) ) ),
				'posts/page__about.json' => json_encode( array( 'id' => 10, 'slug' => 'about', 'type' => 'page', 'status' => 'publish', 'title' => 'About' ) ),
			)
		);
		$manifest = json_decode( $files['manifest.json'], true );
		$manifest['counts']['posts'] = 2;
		$files['manifest.json'] = json_encode( $manifest );
		bricks_ie_av_assert_error( 'invalid_post', ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ), 'Duplicate source IDs are rejected.' );
	}
);

bricks_ie_test(
	'archive validator: post identities are unique per type and slug, while slug zero is valid',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'posts/index.json' => json_encode( array( array( 'slug' => '0', 'type' => 'page', 'file' => 'page__zero.json' ), array( 'slug' => '0', 'type' => 'post', 'file' => 'post__zero.json' ) ) ),
				'posts/page__home.json' => null,
				'posts/page__zero.json' => json_encode( array( 'id' => 11, 'slug' => '0', 'type' => 'page', 'status' => 'publish', 'title' => 'Zero page' ) ),
				'posts/post__zero.json' => json_encode( array( 'id' => 12, 'slug' => '0', 'type' => 'post', 'status' => 'publish', 'title' => 'Zero post' ) ),
			)
		);
		$manifest = json_decode( $files['manifest.json'], true );
		$manifest['counts']['posts'] = 2;
		$files['manifest.json'] = json_encode( $manifest );
		bricks_ie_av_assert_valid( 1, ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ) );

		$files['posts/index.json'] = json_encode( array( array( 'slug' => 'same', 'type' => 'page', 'file' => 'page__zero.json' ), array( 'slug' => 'same', 'type' => 'page', 'file' => 'post__zero.json' ) ) );
		bricks_ie_av_assert_error( 'invalid_index', ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: legacy empty slug is allowed only for draft-like statuses',
	function () {
		$files = bricks_ie_av_v1_files(
			array(
				'posts/index.json' => json_encode( array( array( 'slug' => '', 'type' => 'page', 'file' => 'page__draft.json' ) ) ),
				'posts/page__home.json' => null,
				'posts/page__draft.json' => json_encode( array( 'id' => 13, 'slug' => '', 'type' => 'page', 'status' => 'draft', 'title' => 'Draft' ) ),
			)
		);
		bricks_ie_av_assert_valid( 1, ( new Bricks_IE_Archive_Validator() )->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

// -------------------------------------------------------------------------
// Tests: schema version 2 consistency.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: schema 2 requires the manifest format identifier',
	function () {
		$files = bricks_ie_av_v2_files( array(), array( 'format' => 'something/else' ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'invalid_manifest', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 media domain is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array(), array( 'domains' => array( 'media_files' => true ) ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unsupported_domain', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 unknown native schema is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array(), array( 'bricks' => array( 'native_schema' => 'bricks/something-else' ) ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unsupported_native_schema', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 missing native package is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array( 'bricks/package.zip' => null ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'missing_native_package', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 missing checksum file is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array( 'bricks/package.sha256' => null ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'missing_native_checksum', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 malformed checksum file is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array( 'bricks/package.sha256' => 'not-a-hash' ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'invalid_native_checksum', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 native package hash mismatch is rejected',
	function () {
		$files = bricks_ie_av_v2_files(
			array(
				'bricks/package.sha256' => str_repeat( '0', 64 ) . "  package.zip\n",
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'native_package_hash_mismatch', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 manifest hash mismatch is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array(), array( 'bricks' => array( 'package_sha256' => str_repeat( 'f', 64 ) ) ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'manifest_hash_mismatch', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 native package that is not a zip is rejected',
	function () {
		$garbage = 'garbage-bytes-not-a-zip';
		$sha     = hash( 'sha256', $garbage );

		$files = bricks_ie_av_v2_files(
			array(
				'bricks/package.zip'    => $garbage,
				'bricks/package.sha256' => $sha . "  package.zip\n",
			),
			array(
				'bricks' => array( 'package_sha256' => $sha ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'invalid_native_package', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 posts domain without an index is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array( 'katsarov/posts/index.json' => null ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'missing_index', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 post count mismatch is rejected',
	function () {
		$files = bricks_ie_av_v2_files( array(), array( 'counts' => array( 'posts' => 5 ) ) );

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'manifest_count_mismatch', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 rejects bricks_template in the Katsarov payload',
	function () {
		$files = bricks_ie_av_v2_files(
			array(
				'katsarov/posts/index.json'      => json_encode(
					array(
						array(
							'slug' => 'site-header',
							'type' => 'bricks_template',
							'file' => 'bricks_template__site-header.json',
						),
					)
				),
				'katsarov/posts/page__home.json' => null,
				'katsarov/posts/bricks_template__site-header.json' => json_encode(
					array(
						'id'     => 55,
						'slug'   => 'site-header',
						'type'   => 'bricks_template',
						'status' => 'publish',
						'title'  => 'Site Header',
					)
				),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'forbidden_post_type', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 post files while domains.posts is false are rejected',
	function () {
		$files = bricks_ie_av_v2_files(
			array(),
			array(
				'domains' => array( 'posts' => false ),
				'counts'  => array( 'posts' => 0 ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		bricks_ie_av_assert_error( 'unexpected_entry', $validator->validate( bricks_ie_av_make_zip( $files ) ) );
	}
);

bricks_ie_test(
	'archive validator: schema 2 template conditions flag and file must agree',
	function () {
		$validator = new Bricks_IE_Archive_Validator();

		// Declared but missing.
		$files  = bricks_ie_av_v2_files( array(), array( 'domains' => array( 'template_conditions' => true ) ) );
		bricks_ie_av_assert_error( 'invalid_manifest', $validator->validate( bricks_ie_av_make_zip( $files ) ), 'Declared but missing.' );

		// Present but not declared.
		$files = bricks_ie_av_v2_files(
			array(
				'katsarov/template-conditions.json' => json_encode( array() ),
			)
		);
		bricks_ie_av_assert_error( 'unexpected_entry', $validator->validate( bricks_ie_av_make_zip( $files ) ), 'Present but not declared.' );
	}
);

// -------------------------------------------------------------------------
// Tests: nested native package validation (no-extraction deep inspection).
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: native package limits have explicit realistic defaults',
	function () {
		$limits = ( new Bricks_IE_Archive_Validator() )->get_limits();

		bricks_ie_assert_same( 5000, $limits['max_native_entries'], 'Native entry limit should default to 5,000.' );
		bricks_ie_assert_same( 268435456, $limits['max_native_uncompressed_size'], 'Native aggregate limit should default to 256 MiB.' );
		bricks_ie_assert_same( 16777216, $limits['max_native_member_size'], 'Native member limit should default to 16 MiB.' );
		bricks_ie_assert_same( 100, $limits['max_native_compression_ratio'], 'Native ratio limit should default to 100:1.' );
	}
);

bricks_ie_test(
	'archive validator: nested native package structure is reported',
	function () {
		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files() ) );

		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert_same( 1, $result['native_package']['entries'], 'Native entry count should be reported.' );
		bricks_ie_assert_same(
			strlen( '{"schema":"bricks/unified-global-transfer","version":1}' ),
			$result['native_package']['uncompressed_size'],
			'Native uncompressed size should be reported.'
		);
	}
);

bricks_ie_test(
	'archive validator: compressed native package with realistic entries passes',
	function () {
		$package = bricks_ie_av_compressed_zip_bytes(
			array(
				'manifest.json'         => json_encode(
					array(
						'schema'  => 'bricks/unified-global-transfer',
						'version' => 1,
					)
				),
				'templates/header.json' => json_encode(
					array(
						'id'       => 1,
						'elements' => array(),
					)
				),
				'global-elements.json'  => json_encode( array() ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert_same( 3, $result['native_package']['entries'], 'All native entries should be inspected.' );
		bricks_ie_assert( $result['native_package']['uncompressed_size'] > 0, 'Native uncompressed size should be accumulated.' );
	}
);

bricks_ie_test(
	'archive validator: ordinary JSON font and icon members pass native validation',
	function () {
		$package = bricks_ie_av_compressed_zip_bytes(
			array(
				'manifest.json'  => json_encode( array( 'schema' => 'bricks/unified-global-transfer', 'version' => 1 ) ),
				'assets/site.woff2' => "wOFF\x02\x00font-data",
				'assets/icon.svg' => '<svg viewBox="0 0 1 1"></svg>',
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_valid( 2, $result );
		bricks_ie_assert_same( 3, $result['native_package']['entries'] );
	}
);

bricks_ie_test(
	'archive validator: compressed nested native archive member is rejected before import inspection',
	function () {
		$package = bricks_ie_av_compressed_zip_bytes(
			array(
				'manifest.json' => '{}',
				'assets/nested.zip' => bricks_ie_av_compressed_zip_bytes( array( 'inner.json' => '{}' ) ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_error( 'nested_archive_not_allowed', $result );
	}
);

bricks_ie_test(
	'archive validator: empty nested native archive member is rejected',
	function () {
		$package = bricks_ie_av_compressed_zip_bytes(
			array(
				'manifest.json' => '{}',
				'assets/empty.zip' => bricks_ie_av_empty_zip_bytes(),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_error( 'nested_archive_not_allowed', $result );
	}
);

bricks_ie_test(
	'archive validator: highly compressed nested native data is rejected',
	function () {
		// Deflates to a tiny fraction of its size: ratio far above 100:1.
		$package = bricks_ie_av_compressed_zip_bytes(
			array(
				'manifest.json' => str_repeat( 'a', 200000 ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_error( 'compression_ratio_exceeded', $result );
	}
);

bricks_ie_test(
	'archive validator: nested compression ratio limit honors overrides',
	function () {
		$package = bricks_ie_av_compressed_zip_bytes(
			array(
				'manifest.json' => str_repeat( 'ab', 5000 ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator( array( 'max_native_compression_ratio' => 2 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_error( 'compression_ratio_exceeded', $result );
	}
);

bricks_ie_test(
	'archive validator: unsafe paths inside the native package are rejected',
	function () {
		$validator = new Bricks_IE_Archive_Validator();

		$package = bricks_ie_av_raw_zip(
			array(
				array( 'manifest.json', '{}' ),
				array( '../evil.json', 'x' ),
			)
		);
		$result  = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );
		bricks_ie_av_assert_error( 'unsafe_entry_path', $result, 'Traversal entry.' );

		$package = bricks_ie_av_raw_zip(
			array(
				array( 'manifest.json', '{}' ),
				array( '/etc/passwd', 'x' ),
			)
		);
		$result  = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );
		bricks_ie_av_assert_error( 'unsafe_entry_path', $result, 'Absolute entry.' );
	}
);

bricks_ie_test(
	'archive validator: duplicate entries inside the native package are rejected',
	function () {
		$package = bricks_ie_av_raw_zip(
			array(
				array( 'manifest.json', '{}' ),
				array( 'templates/a.json', '{}' ),
				array( 'templates/a.json', '{}' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_error( 'duplicate_entry', $result );
	}
);

bricks_ie_test(
	'archive validator: native package entry count limit is enforced',
	function () {
		$package = bricks_ie_av_raw_zip(
			array(
				array( 'manifest.json', '{}' ),
				array( 'templates/a.json', '{}' ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator( array( 'max_native_entries' => 1 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

		bricks_ie_av_assert_error( 'too_many_entries', $result );
	}
);

bricks_ie_test(
	'archive validator: native package per-member size limit is enforced',
	function () {
		// Default native fixture holds a 58-byte manifest.json member.
		$validator = new Bricks_IE_Archive_Validator( array( 'max_native_member_size' => 16 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files() ) );

		bricks_ie_av_assert_error( 'entry_too_large', $result );
	}
);

bricks_ie_test(
	'archive validator: native package aggregate uncompressed limit is enforced',
	function () {
		$validator = new Bricks_IE_Archive_Validator( array( 'max_native_uncompressed_size' => 16 ) );
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files() ) );

		bricks_ie_av_assert_error( 'total_uncompressed_too_large', $result );
	}
);

if ( method_exists( 'ZipArchive', 'setExternalAttributesName' ) && method_exists( 'ZipArchive', 'getExternalAttributesIndex' ) ) {
	bricks_ie_test(
		'archive validator: symlink entries inside the native package are rejected',
		function () {
			$directory = bricks_ie_test_temp_dir();
			$path      = $directory . '/native-symlink.zip';

			$zip = new ZipArchive();
			bricks_ie_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
			bricks_ie_assert( true === $zip->addFromString( 'manifest.json', '{}' ) );
			bricks_ie_assert( true === $zip->addFromString( 'evil', '/etc/passwd' ) );
			bricks_ie_assert(
				true === $zip->setExternalAttributesName( 'evil', ZipArchive::OPSYS_UNIX, ( 0xA000 | 0777 ) << 16 ),
				'Could not mark native fixture entry as symlink.'
			);
			bricks_ie_assert( true === $zip->close() );

			$package = file_get_contents( $path );
			bricks_ie_assert( false !== $package && strlen( $package ) > 4, 'Could not read native symlink fixture.' );

			$validator = new Bricks_IE_Archive_Validator();
			$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );

			bricks_ie_av_assert_error( 'symlink_entry', $result );
		}
	);
}

bricks_ie_test(
	'archive validator: truncated native package structure is rejected',
	function () {
		$valid  = bricks_ie_av_native_package_bytes();
		$broken = substr( $valid, 0, 20 ); // Keeps PK\x03\x04, destroys the central directory.

		$validator = new Bricks_IE_Archive_Validator();
		$result    = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $broken ) ) );

		bricks_ie_av_assert_error( 'invalid_native_package', $result );
	}
);

bricks_ie_test(
	'archive validator: unreadable native package members are rejected',
	function () {
		// Entry claims deflate but holds bytes that cannot be inflated, so
		// the declared uncompressed size can never be produced. The declared
		// size stays below the per-member ratio threshold (4 * 100) so the
		// readability check, not the ratio check, is what fires.
		$package = bricks_ie_av_raw_zip(
			array(
				array( 'data.json', "\xff\xff\xff\xff", 8, 300 ),
			)
		);

		$validator = new Bricks_IE_Archive_Validator();
		$warnings = array();
		set_error_handler(
			function ( $severity, $message ) use ( &$warnings ) {
				if ( E_WARNING === $severity ) {
					$warnings[] = $message;
					return true;
				}

				return false;
			}
		);

		try {
			$result = $validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $package ) ) );
		} finally {
			restore_error_handler();
		}

		bricks_ie_av_assert_error( 'zip_read_failed', $result );
		bricks_ie_assert_same( array(), $warnings, 'Corrupt native members must not emit PHP warnings.' );
	}
);

bricks_ie_test(
	'archive validator: native package inspection leaves no temporary files behind',
	function () {
		$pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'brkse*';
		$before  = glob( $pattern );

		$validator = new Bricks_IE_Archive_Validator();

		// Success path.
		$validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files() ) );

		// Failure path: structurally broken package.
		$broken = substr( bricks_ie_av_native_package_bytes(), 0, 20 );
		$validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $broken ) ) );

		// Failure path: unsafe nested entry.
		$unsafe = bricks_ie_av_raw_zip( array( array( '../evil.json', 'x' ) ) );
		$validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $unsafe ) ) );

		// Failure path: compression ratio exceeded.
		$bomb = bricks_ie_av_compressed_zip_bytes( array( 'manifest.json' => str_repeat( 'a', 200000 ) ) );
		$validator->validate( bricks_ie_av_make_zip( bricks_ie_av_v2_files_with_native_bytes( $bomb ) ) );

		$after = glob( $pattern );

		bricks_ie_assert_same( $before, $after, 'Native package inspection must clean up its temporary files on every path.' );
	}
);

// -------------------------------------------------------------------------
// Tests: reusable public helpers.
// -------------------------------------------------------------------------

bricks_ie_test(
	'archive validator: decode_json_member decodes safely',
	function () {
		bricks_ie_assert_same( array( 'a' => 1 ), Bricks_IE_Archive_Validator::decode_json_member( '{"a":1}', 'member.json' ) );
		bricks_ie_assert_same( null, Bricks_IE_Archive_Validator::decode_json_member( 'null', 'member.json' ) );

		$error = Bricks_IE_Archive_Validator::decode_json_member( '{broken', 'member.json' );
		bricks_ie_assert( is_wp_error( $error ), 'Broken JSON should fail.' );
		bricks_ie_assert_same( 'invalid_json', $error->get_error_code() );

		$error = Bricks_IE_Archive_Validator::decode_json_member( 123, 'member.json' );
		bricks_ie_assert( is_wp_error( $error ), 'Non-string input should fail.' );
		bricks_ie_assert_same( 'invalid_json', $error->get_error_code() );

		$deep  = str_repeat( '[', 200 ) . str_repeat( ']', 200 );
		$error = Bricks_IE_Archive_Validator::decode_json_member( $deep, 'member.json', 8 );
		bricks_ie_assert( is_wp_error( $error ), 'Deep JSON should fail.' );
		bricks_ie_assert_same( 'json_too_deep', $error->get_error_code() );
	}
);

bricks_ie_test(
	'archive validator: validate_entry_name classifies paths',
	function () {
		foreach ( array( 'manifest.json', 'options/a.json', 'katsarov/posts/', 'bricks/package.zip' ) as $safe ) {
			bricks_ie_assert_same( true, Bricks_IE_Archive_Validator::validate_entry_name( $safe ), "Safe: {$safe}" );
		}

		foreach ( array( '/etc/passwd', 'a\\b.json', '../x', 'a/../b', 'a/./b', "a\0b", '', 'a//b', 'C:/windows' ) as $unsafe ) {
			$result = Bricks_IE_Archive_Validator::validate_entry_name( $unsafe );
			bricks_ie_assert( is_wp_error( $result ), 'Unsafe name should fail: ' . addcslashes( (string) $unsafe, "\0..\37" ) );
			bricks_ie_assert_same( 'unsafe_entry_path', $result->get_error_code() );
		}
	}
);

// -------------------------------------------------------------------------
// Standalone execution (when not included by tests/run.php).
// -------------------------------------------------------------------------

$bricks_ie_av_self = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( $_SERVER['SCRIPT_FILENAME'] ) : false;

if ( $bricks_ie_av_self && realpath( __FILE__ ) === $bricks_ie_av_self ) {
	$bricks_ie_av_passed = 0;
	$bricks_ie_av_failed = 0;

	foreach ( $GLOBALS['bricks_ie_tests'] as $bricks_ie_av_name => $bricks_ie_av_test ) {
		try {
			$bricks_ie_av_test();
			$bricks_ie_av_passed++;
			echo "PASS: {$bricks_ie_av_name}\n";
		} catch ( Throwable $bricks_ie_av_exception ) {
			$bricks_ie_av_failed++;
			echo "FAIL: {$bricks_ie_av_name}\n       " . $bricks_ie_av_exception->getMessage() . "\n";
		}
	}

	$bricks_ie_av_total = $bricks_ie_av_passed + $bricks_ie_av_failed;
	echo "\n{$bricks_ie_av_total} tests, {$bricks_ie_av_passed} passed, {$bricks_ie_av_failed} failed.\n";

	foreach ( $GLOBALS['bricks_ie_test_temp_dirs'] as $bricks_ie_av_dir ) {
		bricks_ie_remove_test_temp_path( $bricks_ie_av_dir );
	}

	exit( $bricks_ie_av_failed > 0 ? 1 : 0 );
}
