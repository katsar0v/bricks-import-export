<?php
/**
 * Archive validator for Bricks Import & Export archives.
 *
 * Performs complete no-write validation of schema version 1 (plugin 1.0.x)
 * and schema version 2 (plugin 1.1.0) export archives before any import
 * begins. The validator only reads archive metadata and member bytes into
 * memory: it never extracts archive members to disk and never touches the
 * database, options, or posts. The single exception is a secure unique
 * temporary file used to open the embedded native package for inspection,
 * which stays below the system temporary directory and is removed on every
 * code path.
 *
 * Limits are class constants by default, adjustable through the constructor
 * (used by tests) and through WordPress filters when a WP runtime is present:
 *
 *   bricks_ie_archive_max_compressed_size            Outer compressed archive bytes.
 *   bricks_ie_archive_max_native_package_size        Embedded native package bytes.
 *   bricks_ie_archive_max_entries                    Maximum number of ZIP entries.
 *   bricks_ie_archive_max_uncompressed_size          Aggregate uncompressed bytes.
 *   bricks_ie_archive_max_member_size                Individual member bytes.
 *   bricks_ie_archive_max_compression_ratio          Maximum compression ratio.
 *   bricks_ie_archive_max_json_depth                 Maximum JSON nesting depth.
 *   bricks_ie_archive_max_native_entries             Native package ZIP entries.
 *   bricks_ie_archive_max_native_uncompressed_size   Native aggregate uncompressed bytes.
 *   bricks_ie_archive_max_native_member_size         Native individual member bytes.
 *   bricks_ie_archive_max_native_compression_ratio   Native maximum compression ratio.
 *
 * The native package limits are enforced independently from the outer ZIP
 * limits: a larger outer limit never implies a larger native package limit.
 *
 * Schema version 2 archives embed the native Bricks package as
 * bricks/package.zip. After the signature and SHA-256 checks pass, the
 * embedded package itself is validated without extraction: entry count,
 * duplicate and unsafe paths, symlink entries, per-member size, aggregate
 * uncompressed size, compression ratio, and readability of every member.
 * Because ZipArchive can only open paths, the package bytes are staged in a
 * secure unique temporary file below the system temporary directory which is
 * removed again on every code path; no member is ever extracted to disk.
 *
 * Stable WP_Error codes returned by validate():
 *
 *   no_ziparchive, file_not_found, file_not_readable, invalid_extension,
 *   invalid_zip_signature, archive_too_large, zip_open_failed,
 *   invalid_zip_structure, too_many_entries, unsafe_entry_path,
 *   duplicate_entry, symlink_entry, entry_too_large, native_package_too_large,
 *   total_uncompressed_too_large, compression_ratio_exceeded,
 *   unexpected_entry, missing_manifest, invalid_manifest,
 *   unsupported_schema_version, invalid_json, json_too_deep,
 *   no_bricks_version, manifest_count_mismatch, invalid_index, missing_index,
 *   missing_post_file, unlisted_post_file, invalid_post,
 *   index_payload_mismatch, forbidden_post_type, missing_native_package,
 *   missing_native_checksum, invalid_native_checksum, invalid_native_package,
 *   native_package_hash_mismatch, manifest_hash_mismatch,
 *   unsupported_native_schema, unsupported_domain, nested_archive_not_allowed,
 *   zip_read_failed, missing_export_warnings, invalid_export_warnings.
 *
 * @package BricksIE
 * @since   1.1.0
 */

/**
 * No-write validator for schema version 1 and schema version 2 archives.
 */
class Bricks_IE_Archive_Validator {

	const SCHEMA_VERSION_1 = 1;
	const SCHEMA_VERSION_2 = 2;

	/** Outer manifest format identifier used by schema version 2. */
	const MANIFEST_FORMAT = 'katsarov/bricks-import-export';

	/** Native Bricks unified global transfer schema expected in schema version 2. */
	const NATIVE_SCHEMA         = 'bricks/unified-global-transfer';
	const NATIVE_SCHEMA_VERSION = 1;

	/** Default limit: compressed outer archive (64 MiB). */
	const DEFAULT_MAX_COMPRESSED_SIZE = 67108864;

	/** Default limit: embedded native Bricks package (32 MiB), enforced independently. */
	const DEFAULT_MAX_NATIVE_PACKAGE_SIZE = 33554432;

	/**
	 * Default limit: entries inside the embedded native package (5,000).
	 *
	 * Realistic for the audited Bricks unified-global-transfer package, which
	 * holds a bounded set of JSON documents plus font/icon assets, while still
	 * bounding entry-flood bombs inside a 32 MiB package.
	 */
	const DEFAULT_MAX_NATIVE_ENTRIES = 5000;

	/**
	 * Default limit: aggregate uncompressed bytes inside the native package (256 MiB).
	 *
	 * Covers realistic deflate ratios for Bricks JSON content inside a package
	 * capped at 32 MiB compressed while capping decompression bombs.
	 */
	const DEFAULT_MAX_NATIVE_UNCOMPRESSED_SIZE = 268435456;

	/** Default limit: individual member bytes inside the native package (16 MiB). */
	const DEFAULT_MAX_NATIVE_MEMBER_SIZE = 16777216;

	/** Default limit: maximum compression ratio inside the native package (100:1). */
	const DEFAULT_MAX_NATIVE_COMPRESSION_RATIO = 100;

	/** Default limit: number of ZIP entries. */
	const DEFAULT_MAX_ENTRIES = 2000;

	/** Default limit: aggregate uncompressed bytes (256 MiB). */
	const DEFAULT_MAX_UNCOMPRESSED_SIZE = 268435456;

	/** Default limit: individual JSON/member bytes (16 MiB). */
	const DEFAULT_MAX_JSON_MEMBER_SIZE = 16777216;

	/** Default limit: maximum compression ratio (100:1). */
	const DEFAULT_MAX_COMPRESSION_RATIO = 100;

	/** Default limit: maximum JSON nesting depth. */
	const DEFAULT_MAX_JSON_DEPTH = 128;

	const ZIP_SIGNATURE_LOCAL = "PK\x03\x04";
	const ZIP_SIGNATURE_EMPTY = "PK\x05\x06";
	const ZIP_SIGNATURE_CENTRAL = "PK\x01\x02";
	const ZIP_SIGNATURE_DIGITAL = "PK\x05\x05";
	const ZIP_SIGNATURE_ZIP64 = "PK\x06\x06";
	const ZIP_SIGNATURE_ZIP64_LOCATOR = "PK\x06\x07";
	const ZIP_SIGNATURE_SPANNED = "PK\x07\x08";

	/**
	 * Explicit limit overrides, keyed like get_limits().
	 *
	 * @var array
	 */
	private $limit_overrides = array();

	/**
	 * Constructor.
	 *
	 * @param array $limit_overrides Optional. Limit overrides keyed by the
	 *                               get_limits() keys. Positive integers only.
	 */
	public function __construct( $limit_overrides = array() ) {
		if ( is_array( $limit_overrides ) ) {
			foreach ( $limit_overrides as $key => $value ) {
				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$this->limit_overrides[ $key ] = (int) $value;
				}
			}
		}
	}

	/**
	 * Validate an export archive without writing anything.
	 *
	 * @param string $zip_path Absolute path to the uploaded archive.
	 * @return array|WP_Error Normalized report on success, WP_Error otherwise.
	 */
	public function validate( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'ZipArchive is not available on this server.', 'bricks-ie' ) );
		}

		if ( ! is_string( $zip_path ) || '' === $zip_path || ! file_exists( $zip_path ) || ! is_file( $zip_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Zip file not found.', 'bricks-ie' ) );
		}

		if ( ! is_readable( $zip_path ) ) {
			return new WP_Error( 'file_not_readable', __( 'The zip archive cannot be read.', 'bricks-ie' ) );
		}

		$extension = strtolower( (string) pathinfo( $zip_path, PATHINFO_EXTENSION ) );
		if ( 'zip' !== $extension ) {
			return new WP_Error( 'invalid_extension', __( 'Uploaded file must be a .zip archive.', 'bricks-ie' ) );
		}

		$limits = $this->get_limits();

		$compressed_size = filesize( $zip_path );
		if ( false === $compressed_size ) {
			return new WP_Error( 'file_not_readable', __( 'The zip archive cannot be read.', 'bricks-ie' ) );
		}
		$compressed_size = (int) $compressed_size;

		if ( $compressed_size > $limits['max_compressed_size'] ) {
			return new WP_Error(
				'archive_too_large',
				sprintf(
					/* translators: 1: archive size in bytes, 2: maximum compressed size in bytes */
					__( 'The archive is %1$d bytes, which exceeds the maximum compressed size of %2$d bytes.', 'bricks-ie' ),
					$compressed_size,
					$limits['max_compressed_size']
				)
			);
		}

		$handle = fopen( $zip_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'file_not_readable', __( 'The zip archive cannot be read.', 'bricks-ie' ) );
		}
		$magic = fread( $handle, 4 );
		fclose( $handle );

		if ( false === $magic || strlen( $magic ) < 4 || ( self::ZIP_SIGNATURE_LOCAL !== $magic && self::ZIP_SIGNATURE_EMPTY !== $magic ) ) {
			return new WP_Error( 'invalid_zip_signature', __( 'The file does not look like a zip archive.', 'bricks-ie' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open the zip archive.', 'bricks-ie' ) );
		}

		$attributes_support = $this->validate_external_attributes_support( $zip );
		if ( is_wp_error( $attributes_support ) ) {
			$zip->close();
			return $attributes_support;
		}

		$structure = $this->validate_structure( $zip, $limits, $compressed_size );
		if ( is_wp_error( $structure ) ) {
			$zip->close();
			return $structure;
		}

		if ( self::SCHEMA_VERSION_1 === $structure['schema_version'] ) {
			$result = $this->validate_schema_v1( $zip, $structure );
		} else {
			$result = $this->validate_schema_v2( $zip, $structure );
		}

		$zip->close();

		return $result;
	}

	/**
	 * Get the effective validation limits.
	 *
	 * Defaults come from the class constants, are optionally narrowed by
	 * wp_max_upload_size() for the outer archive, can be adjusted through
	 * filters when WordPress is loaded, and are finally overridden by any
	 * constructor overrides.
	 *
	 * @return array {
	 *     @type int $max_compressed_size     Maximum compressed outer archive bytes.
	 *     @type int $max_native_package_size Maximum embedded native package bytes.
	 *     @type int $max_entries             Maximum number of ZIP entries.
	 *     @type int $max_uncompressed_size   Maximum aggregate uncompressed bytes.
	 *     @type int $max_json_member_size    Maximum individual member bytes.
	 *     @type int $max_compression_ratio   Maximum compression ratio.
	 *     @type int $max_json_depth          Maximum JSON nesting depth.
	 *     @type int $max_native_entries      Maximum native package ZIP entries.
	 *     @type int $max_native_uncompressed_size Maximum native aggregate uncompressed bytes.
	 *     @type int $max_native_member_size  Maximum native individual member bytes.
	 *     @type int $max_native_compression_ratio Maximum native compression ratio.
	 * }
	 */
	public function get_limits() {
		$max_compressed = self::DEFAULT_MAX_COMPRESSED_SIZE;

		if ( function_exists( 'wp_max_upload_size' ) ) {
			$upload = (int) wp_max_upload_size();
			if ( $upload > 0 ) {
				$max_compressed = min( $max_compressed, $upload );
			}
		}

		$limits = array(
			'max_compressed_size'     => $this->filter_limit( 'bricks_ie_archive_max_compressed_size', $max_compressed ),
			'max_native_package_size' => $this->filter_limit( 'bricks_ie_archive_max_native_package_size', self::DEFAULT_MAX_NATIVE_PACKAGE_SIZE ),
			'max_entries'             => $this->filter_limit( 'bricks_ie_archive_max_entries', self::DEFAULT_MAX_ENTRIES ),
			'max_uncompressed_size'   => $this->filter_limit( 'bricks_ie_archive_max_uncompressed_size', self::DEFAULT_MAX_UNCOMPRESSED_SIZE ),
			'max_json_member_size'    => $this->filter_limit( 'bricks_ie_archive_max_member_size', self::DEFAULT_MAX_JSON_MEMBER_SIZE ),
			'max_compression_ratio'   => $this->filter_limit( 'bricks_ie_archive_max_compression_ratio', self::DEFAULT_MAX_COMPRESSION_RATIO ),
			'max_json_depth'          => $this->filter_limit( 'bricks_ie_archive_max_json_depth', self::DEFAULT_MAX_JSON_DEPTH ),
			'max_native_entries'      => $this->filter_limit( 'bricks_ie_archive_max_native_entries', self::DEFAULT_MAX_NATIVE_ENTRIES ),
			'max_native_uncompressed_size' => $this->filter_limit( 'bricks_ie_archive_max_native_uncompressed_size', self::DEFAULT_MAX_NATIVE_UNCOMPRESSED_SIZE ),
			'max_native_member_size'  => $this->filter_limit( 'bricks_ie_archive_max_native_member_size', self::DEFAULT_MAX_NATIVE_MEMBER_SIZE ),
			'max_native_compression_ratio' => $this->filter_limit( 'bricks_ie_archive_max_native_compression_ratio', self::DEFAULT_MAX_NATIVE_COMPRESSION_RATIO ),
		);

		foreach ( $limits as $key => $value ) {
			if ( isset( $this->limit_overrides[ $key ] ) ) {
				$limits[ $key ] = $this->limit_overrides[ $key ];
			}
		}

		return $limits;
	}

	/**
	 * Safely decode a JSON archive member.
	 *
	 * Reusable decoder for any JSON member: rejects non-string input, JSON
	 * parse errors, and nesting deeper than the configured depth.
	 *
	 * @param mixed       $raw         Raw member bytes.
	 * @param string      $member_name Member name used in error messages.
	 * @param int|null    $max_depth   Optional. Maximum depth. Defaults to the class limit.
	 * @return mixed|WP_Error Decoded value (associative arrays for objects) or WP_Error.
	 */
	public static function decode_json_member( $raw, $member_name = '', $max_depth = null ) {
		if ( ! is_string( $raw ) ) {
			return new WP_Error(
				'invalid_json',
				sprintf(
					/* translators: %s: archive member name */
					__( '%s does not contain valid JSON.', 'bricks-ie' ),
					'' !== $member_name ? $member_name : __( 'Archive member', 'bricks-ie' )
				)
			);
		}

		if ( null === $max_depth || (int) $max_depth < 1 ) {
			$max_depth = self::DEFAULT_MAX_JSON_DEPTH;
		}
		$max_depth = (int) $max_depth;

		$decoded = json_decode( $raw, true, $max_depth );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			if ( JSON_ERROR_DEPTH === json_last_error() ) {
				return new WP_Error(
					'json_too_deep',
					sprintf(
						/* translators: 1: archive member name, 2: maximum JSON depth */
						__( '%1$s exceeds the maximum JSON depth of %2$d.', 'bricks-ie' ),
						$member_name,
						$max_depth
					)
				);
			}

			return new WP_Error(
				'invalid_json',
				sprintf(
					/* translators: 1: archive member name, 2: JSON error message */
					__( '%1$s does not contain valid JSON: %2$s', 'bricks-ie' ),
					'' !== $member_name ? $member_name : __( 'Archive member', 'bricks-ie' ),
					json_last_error_msg()
				)
			);
		}

		return $decoded;
	}

	/**
	 * Validate a ZIP entry name for path safety.
	 *
	 * Rejects empty names, null bytes, backslashes (Windows traversal),
	 * absolute paths, drive letters, double slashes, and "." or ".." segments.
	 *
	 * @param mixed $name Entry name from the archive.
	 * @return true|WP_Error True when safe, WP_Error with code unsafe_entry_path otherwise.
	 */
	public static function validate_entry_name( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return new WP_Error( 'unsafe_entry_path', __( 'Archive entry has an empty or invalid path.', 'bricks-ie' ) );
		}

		if ( false !== strpos( $name, "\0" ) ) {
			return new WP_Error(
				'unsafe_entry_path',
				sprintf( __( 'Archive entry contains a null byte: %s', 'bricks-ie' ), addcslashes( $name, "\0..\37" ) )
			);
		}

		if ( false !== strpos( $name, '\\' ) ) {
			return new WP_Error(
				'unsafe_entry_path',
				sprintf( __( 'Archive entry contains a backslash: %s', 'bricks-ie' ), $name )
			);
		}

		if ( '/' === $name[0] ) {
			return new WP_Error(
				'unsafe_entry_path',
				sprintf( __( 'Archive entry is an absolute path: %s', 'bricks-ie' ), $name )
			);
		}

		if ( preg_match( '/^[A-Za-z]:/', $name ) ) {
			return new WP_Error(
				'unsafe_entry_path',
				sprintf( __( 'Archive entry uses a drive letter path: %s', 'bricks-ie' ), $name )
			);
		}

		$segments = explode( '/', $name );
		$last     = count( $segments ) - 1;

		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment && $index !== $last ) {
				return new WP_Error(
					'unsafe_entry_path',
					sprintf( __( 'Archive entry contains an empty path segment: %s', 'bricks-ie' ), $name )
				);
			}

			if ( '..' === $segment || '.' === $segment ) {
				return new WP_Error(
					'unsafe_entry_path',
					sprintf( __( 'Archive entry contains a traversal segment: %s', 'bricks-ie' ), $name )
				);
			}
		}

		return true;
	}

	/**
	 * Apply a limit filter when WordPress is available.
	 *
	 * @param string $filter  Filter name.
	 * @param int    $default Default limit.
	 * @return int
	 */
	private function filter_limit( $filter, $default ) {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( $filter, $default );

			if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
				return (int) $filtered;
			}
		}

		return (int) $default;
	}

	/**
	 * Validate ZIP structure: entry safety, quotas, manifest, and layout.
	 *
	 * @param ZipArchive $zip             Open archive.
	 * @param array      $limits          Effective limits.
	 * @param int        $compressed_size Outer archive size in bytes.
	 * @return array|WP_Error Structure context for the schema validators.
	 */
	private function validate_structure( $zip, $limits, $compressed_size ) {
		$entry_count = (int) $zip->numFiles;

		if ( $entry_count > $limits['max_entries'] ) {
			return new WP_Error(
				'too_many_entries',
				sprintf(
					/* translators: 1: number of entries, 2: maximum entries */
					__( 'The archive contains %1$d entries, exceeding the limit of %2$d.', 'bricks-ie' ),
					$entry_count,
					$limits['max_entries']
				)
			);
		}

		$entries            = array();
		$total_uncompressed = 0;

		for ( $i = 0; $i < $entry_count; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( false === $stat || ! isset( $stat['name'] ) ) {
				return new WP_Error( 'invalid_zip_structure', __( 'The zip archive has a corrupt entry list.', 'bricks-ie' ) );
			}

			$name = (string) $stat['name'];

			$name_check = self::validate_entry_name( $name );
			if ( is_wp_error( $name_check ) ) {
				return $name_check;
			}

			if ( isset( $entries[ $name ] ) ) {
				return new WP_Error(
					'duplicate_entry',
					sprintf( __( 'Duplicate archive entry: %s', 'bricks-ie' ), $name )
				);
			}

			$is_directory = ( '/' === substr( $name, -1 ) );

			if ( ! $is_directory && $this->is_symlink_entry( $zip, $i ) ) {
				return new WP_Error(
					'symlink_entry',
					sprintf( __( 'Archive entry is a symlink, which is not allowed: %s', 'bricks-ie' ), $name )
				);
			}

			$size      = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$comp_size = isset( $stat['comp_size'] ) ? (int) $stat['comp_size'] : 0;

			if ( ! $is_directory ) {
				$size_check = $this->check_entry_size( $name, $size, $limits );
				if ( is_wp_error( $size_check ) ) {
					return $size_check;
				}

				$total_uncompressed += $size;

				if ( $total_uncompressed > $limits['max_uncompressed_size'] ) {
					return new WP_Error(
						'total_uncompressed_too_large',
						sprintf(
							/* translators: 1: aggregate uncompressed bytes, 2: limit in bytes */
							__( 'The archive unpacks to %1$d bytes, exceeding the aggregate limit of %2$d bytes.', 'bricks-ie' ),
							$total_uncompressed,
							$limits['max_uncompressed_size']
						)
					);
				}

				if ( $comp_size > 0 && $size > $comp_size * $limits['max_compression_ratio'] ) {
					return new WP_Error(
						'compression_ratio_exceeded',
						sprintf(
							/* translators: %s: archive entry name */
							__( 'Archive entry %s exceeds the maximum compression ratio.', 'bricks-ie' ),
							$name
						)
					);
				}
			}

			$entries[ $name ] = array(
				'index'     => $i,
				'size'      => $size,
				'comp_size' => $comp_size,
				'directory' => $is_directory,
			);
		}

		if ( $compressed_size > 0 && $total_uncompressed > $compressed_size * $limits['max_compression_ratio'] ) {
			return new WP_Error(
				'compression_ratio_exceeded',
				__( 'The archive exceeds the maximum overall compression ratio.', 'bricks-ie' )
			);
		}

		if ( ! isset( $entries['manifest.json'] ) ) {
			return new WP_Error( 'missing_manifest', __( 'Archive is missing manifest.json.', 'bricks-ie' ) );
		}

		$manifest_raw = $this->read_member( $zip, 'manifest.json', $entries['manifest.json'], $limits['max_json_member_size'] );
		if ( is_wp_error( $manifest_raw ) ) {
			return $manifest_raw;
		}

		$manifest = self::decode_json_member( $manifest_raw, 'manifest.json', $limits['max_json_depth'] );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'invalid_manifest', __( 'manifest.json must be a JSON object.', 'bricks-ie' ) );
		}

		if ( ! array_key_exists( 'version', $manifest ) ) {
			return new WP_Error( 'invalid_manifest', __( 'manifest.json is missing the schema version.', 'bricks-ie' ) );
		}

		$version = $manifest['version'];

		if ( ! is_int( $version ) || ( self::SCHEMA_VERSION_1 !== $version && self::SCHEMA_VERSION_2 !== $version ) ) {
			return new WP_Error(
				'unsupported_schema_version',
				__( 'Unsupported archive schema version. Expected 1 or 2.', 'bricks-ie' )
			);
		}

		$layout = $this->check_layout( $entries, $version );
		if ( is_wp_error( $layout ) ) {
			return $layout;
		}

		return array(
			'entries'            => $entries,
			'entry_count'        => $entry_count,
			'total_uncompressed' => $total_uncompressed,
			'compressed_size'    => $compressed_size,
			'manifest'           => $manifest,
			'schema_version'     => $version,
			'limits'             => $limits,
		);
	}

	/**
	 * Validate the embedded native Bricks package without extracting it.
	 *
	 * ZipArchive can only open paths, never in-memory bytes, so the package
	 * bytes are staged in a secure unique temporary file below the system
	 * temporary directory (created atomically with 0600 permissions by
	 * tempnam). The file is removed again on every code path; no member of
	 * the package is ever extracted to disk.
	 *
	 * @param string $package_raw Raw bytes of bricks/package.zip.
	 * @param array  $limits      Effective limits.
	 * @return array|WP_Error { entries, uncompressed_size } on success.
	 */
	private function validate_native_package( $package_raw, $limits ) {
		$temp_path = tempnam( sys_get_temp_dir(), 'brkse' );

		if ( false === $temp_path ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not create a temporary file to inspect the native package.', 'bricks-ie' ) );
		}

		try {
			if ( false === file_put_contents( $temp_path, $package_raw ) ) {
				return new WP_Error( 'zip_open_failed', __( 'The native package could not be staged for inspection.', 'bricks-ie' ) );
			}

			$zip = new ZipArchive();

			if ( true !== $zip->open( $temp_path ) ) {
				return new WP_Error( 'invalid_native_package', __( 'The embedded native package could not be opened as a zip archive.', 'bricks-ie' ) );
			}

			try {
				$attributes_support = $this->validate_external_attributes_support( $zip );
				if ( is_wp_error( $attributes_support ) ) {
					return $attributes_support;
				}

				return $this->validate_native_package_structure( $zip, $limits, strlen( $package_raw ) );
			} finally {
				$zip->close();
			}
		} finally {
			if ( file_exists( $temp_path ) ) {
				unlink( $temp_path );
			}
		}
	}

	/**
	 * Validate the structure of an opened native package.
	 *
	 * Enforces the explicit native-package limits: entry count, duplicate and
	 * unsafe paths, symlink entries where detectable, per-member size,
	 * aggregate uncompressed size, compression ratio, and readability of
	 * every member. Members are decompressed into memory one at a time,
	 * bounded by the native member limit, and never written to disk.
	 *
	 * @param ZipArchive $zip          Open native package.
	 * @param array      $limits       Effective limits.
	 * @param int        $package_size Native package file size in bytes.
	 * @return array|WP_Error { entries, uncompressed_size } on success.
	 */
	private function validate_native_package_structure( $zip, $limits, $package_size ) {
		$entry_count = (int) $zip->numFiles;

		if ( $entry_count > $limits['max_native_entries'] ) {
			return new WP_Error(
				'too_many_entries',
				sprintf(
					/* translators: 1: number of entries, 2: maximum entries */
					__( 'The native package contains %1$d entries, exceeding the limit of %2$d.', 'bricks-ie' ),
					$entry_count,
					$limits['max_native_entries']
				)
			);
		}

		$entries            = array();
		$total_uncompressed = 0;

		for ( $i = 0; $i < $entry_count; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( false === $stat || ! isset( $stat['name'] ) ) {
				return new WP_Error( 'invalid_zip_structure', __( 'The native package has a corrupt entry list.', 'bricks-ie' ) );
			}

			$name = (string) $stat['name'];

			$name_check = self::validate_entry_name( $name );
			if ( is_wp_error( $name_check ) ) {
				return $name_check;
			}

			if ( isset( $entries[ $name ] ) ) {
				return new WP_Error(
					'duplicate_entry',
					sprintf( __( 'Duplicate native package entry: %s', 'bricks-ie' ), $name )
				);
			}

			$is_directory = ( '/' === substr( $name, -1 ) );

			if ( ! $is_directory && $this->is_symlink_entry( $zip, $i ) ) {
				return new WP_Error(
					'symlink_entry',
					sprintf( __( 'Native package entry is a symlink, which is not allowed: %s', 'bricks-ie' ), $name )
				);
			}

			$size      = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$comp_size = isset( $stat['comp_size'] ) ? (int) $stat['comp_size'] : 0;

			if ( ! $is_directory ) {
				if ( $size > $limits['max_native_member_size'] ) {
					return new WP_Error(
						'entry_too_large',
						sprintf(
							/* translators: 1: native package entry name, 2: entry size in bytes, 3: limit in bytes */
							__( 'Native package entry %1$s is %2$d bytes uncompressed, exceeding the %3$d byte limit.', 'bricks-ie' ),
							$name,
							$size,
							$limits['max_native_member_size']
						)
					);
				}

				$total_uncompressed += $size;

				if ( $total_uncompressed > $limits['max_native_uncompressed_size'] ) {
					return new WP_Error(
						'total_uncompressed_too_large',
						sprintf(
							/* translators: 1: aggregate uncompressed bytes, 2: limit in bytes */
							__( 'The native package unpacks to %1$d bytes, exceeding the aggregate limit of %2$d bytes.', 'bricks-ie' ),
							$total_uncompressed,
							$limits['max_native_uncompressed_size']
						)
					);
				}

				if ( $comp_size > 0 && $size > $comp_size * $limits['max_native_compression_ratio'] ) {
					return new WP_Error(
						'compression_ratio_exceeded',
						sprintf(
							/* translators: %s: native package entry name */
							__( 'Native package entry %s exceeds the maximum compression ratio.', 'bricks-ie' ),
							$name
						)
					);
				}

				// Native unified packages contain JSON plus font/icon assets, not
				// another archive. Read only the four-byte signature here; the
				// existing bounded getFromIndex() below remains the readability check.
				$signature_check = $this->check_native_member_signature( $zip, $name );
				if ( is_wp_error( $signature_check ) ) {
					return $signature_check;
				}

				// Decompress the member once to prove it is readable end to
				// end; broken streams and truncated data surface here before
				// any import touches the package. Memory is bounded by the
				// native member limit checked above.
				$raw = $zip->getFromIndex( $i );

				if ( false === $raw || strlen( $raw ) !== $size ) {
					return new WP_Error(
						'zip_read_failed',
						sprintf( __( 'Could not read native package entry: %s', 'bricks-ie' ), $name )
					);
				}

				unset( $raw );
			}

			$entries[ $name ] = true;
		}

		if ( $package_size > 0 && $total_uncompressed > $package_size * $limits['max_native_compression_ratio'] ) {
			return new WP_Error(
				'compression_ratio_exceeded',
				__( 'The native package exceeds the maximum overall compression ratio.', 'bricks-ie' )
			);
		}

		return array(
			'entries'           => $entry_count,
			'uncompressed_size' => $total_uncompressed,
		);
	}

	/**
	 * Reject nested ZIP members without loading their complete contents.
	 *
	 * @param ZipArchive $zip  Open native package.
	 * @param string     $name Member name.
	 * @return true|WP_Error
	 */
	private function check_native_member_signature( $zip, $name ) {
		$stream = $zip->getStream( $name );

		if ( false === $stream ) {
			return new WP_Error(
				'zip_read_failed',
				sprintf( __( 'Could not read native package entry: %s', 'bricks-ie' ), $name )
			);
		}

		// libzip can emit a warning from fread() when an intentionally corrupt
		// deflate stream is read. Capture only that expected fread warning; other
		// warnings must continue through the normal PHP error handler.
		$read_warning = null;
		set_error_handler(
			function ( $severity, $message ) use ( &$read_warning ) {
				if ( E_WARNING === $severity && 0 === strpos( $message, 'fread():' ) ) {
					$read_warning = $message;
					return true;
				}

				return false;
			}
		);

		try {
			$signature = fread( $stream, 4 );
		} finally {
			restore_error_handler();
		}
		fclose( $stream );

		if ( null !== $read_warning ) {
			return new WP_Error(
				'zip_read_failed',
				sprintf( __( 'Could not read native package entry: %s', 'bricks-ie' ), $name )
			);
		}

		if ( self::ZIP_SIGNATURE_LOCAL === $signature
			|| self::ZIP_SIGNATURE_EMPTY === $signature
			|| self::ZIP_SIGNATURE_CENTRAL === $signature
			|| self::ZIP_SIGNATURE_DIGITAL === $signature
			|| self::ZIP_SIGNATURE_ZIP64 === $signature
			|| self::ZIP_SIGNATURE_ZIP64_LOCATOR === $signature
			|| self::ZIP_SIGNATURE_SPANNED === $signature
		) {
			return new WP_Error(
				'nested_archive_not_allowed',
				sprintf( __( 'Native package entry is a nested archive, which is not allowed: %s', 'bricks-ie' ), $name )
			);
		}

		return true;
	}

	/**
	 * Detect symlink-like entries through Unix external attributes.
	 *
	 * The method is available since PHP 5.6 / PECL zip 1.12.4, including PHP
	 * 7.4, but the extension capability must still be verified at runtime.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param int        $index Entry index.
	 * @return bool
	 */
	private function is_symlink_entry( $zip, $index ) {
		$opsys = 0;
		$attrs = 0;

		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attrs ) || ZipArchive::OPSYS_UNIX !== $opsys ) {
			return false;
		}

		$mode = ( $attrs >> 16 ) & 0xFFFF;

		return 0xA000 === ( $mode & 0xF000 );
	}

	/**
	 * Require the ZipArchive capability used to detect Unix symlinks.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return true|WP_Error
	 */
	private function validate_external_attributes_support( $zip ) {
		if ( ! is_callable( array( $zip, 'getExternalAttributesIndex' ) ) || ! defined( 'ZipArchive::OPSYS_UNIX' ) ) {
			return new WP_Error(
				'zip_external_attributes_unavailable',
				__( 'The ZIP extension cannot expose Unix external attributes; refusing to validate the archive.', 'bricks-ie' )
			);
		}

		return true;
	}

	/**
	 * Enforce the per-entry size limits.
	 *
	 * @param string $name   Entry name.
	 * @param int    $size   Uncompressed size in bytes.
	 * @param array  $limits Effective limits.
	 * @return true|WP_Error
	 */
	private function check_entry_size( $name, $size, $limits ) {
		if ( 'bricks/package.zip' === $name ) {
			if ( $size > $limits['max_native_package_size'] ) {
				return new WP_Error(
					'native_package_too_large',
					sprintf(
						/* translators: 1: native package size in bytes, 2: limit in bytes */
						__( 'The embedded native package is %1$d bytes, exceeding the independent limit of %2$d bytes.', 'bricks-ie' ),
						$size,
						$limits['max_native_package_size']
					)
				);
			}

			return true;
		}

		if ( $size > $limits['max_json_member_size'] ) {
			return new WP_Error(
				'entry_too_large',
				sprintf(
					/* translators: 1: archive entry name, 2: entry size in bytes, 3: limit in bytes */
					__( 'Archive entry %1$s is %2$d bytes uncompressed, exceeding the %3$d byte limit.', 'bricks-ie' ),
					$name,
					$size,
					$limits['max_json_member_size']
				)
			);
		}

		return true;
	}

	/**
	 * Enforce the exact allowed layout for the detected schema version.
	 *
	 * @param array $entries        Entry map from validate_structure().
	 * @param int   $schema_version Detected schema version.
	 * @return true|WP_Error
	 */
	private function check_layout( $entries, $schema_version ) {
		$allowed_dirs = self::SCHEMA_VERSION_1 === $schema_version
			? array(
				'options/' => true,
				'posts/'   => true,
			)
			: array(
				'bricks/'          => true,
				'katsarov/'        => true,
				'katsarov/posts/'  => true,
			);

		foreach ( $entries as $name => $entry ) {
			if ( 'manifest.json' === $name ) {
				continue;
			}

			if ( $entry['directory'] ) {
				if ( ! isset( $allowed_dirs[ $name ] ) ) {
					return new WP_Error(
						'unexpected_entry',
						sprintf( __( 'Unexpected archive entry: %s', 'bricks-ie' ), $name )
					);
				}

				continue;
			}

			if ( ! $this->matches_layout( $name, $schema_version ) ) {
				return new WP_Error(
					'unexpected_entry',
					sprintf( __( 'Unexpected archive entry: %s', 'bricks-ie' ), $name )
				);
			}
		}

		return true;
	}

	/**
	 * Check a file entry name against the schema layout.
	 *
	 * @param string $name           Entry name.
	 * @param int    $schema_version Schema version.
	 * @return bool
	 */
	private function matches_layout( $name, $schema_version ) {
		if ( self::SCHEMA_VERSION_1 === $schema_version ) {
			if ( preg_match( '/^options\/[A-Za-z0-9_\-]+\.json$/', $name ) ) {
				return true;
			}

			if ( 'posts/index.json' === $name ) {
				return true;
			}

			return (bool) preg_match( '/^posts\/[A-Za-z0-9_\-]+\.json$/', $name );
		}

		if ( 'bricks/package.zip' === $name || 'bricks/package.sha256' === $name ) {
			return true;
		}

		if ( 'katsarov/posts/index.json' === $name ) {
			return true;
		}

		if ( preg_match( '/^katsarov\/posts\/[A-Za-z0-9_\-]+\.json$/', $name ) ) {
			return true;
		}

		return 'katsarov/template-conditions.json' === $name || 'katsarov/export-warnings.json' === $name;
	}

	/**
	 * Validate a schema version 1 archive (legacy compatibility format).
	 *
	 * @param ZipArchive $zip       Open archive.
	 * @param array      $structure Structure context.
	 * @return array|WP_Error
	 */
	private function validate_schema_v1( $zip, $structure ) {
		$manifest = $structure['manifest'];
		$entries  = $structure['entries'];
		$limits   = $structure['limits'];
		$warnings = array(
			__( 'Schema version 1 is a legacy compatibility format and is imported through a hardened compatibility path.', 'bricks-ie' ),
		);

		if ( empty( $manifest['bricks_version'] ) || ! is_string( $manifest['bricks_version'] ) ) {
			return new WP_Error(
				'no_bricks_version',
				__( 'Archive does not contain a Bricks version. Please re-export from a site running this version of the export tool.', 'bricks-ie' )
			);
		}

		$counts_check = $this->validate_manifest_counts( $manifest, array( 'options', 'posts' ) );
		if ( is_wp_error( $counts_check ) ) {
			return $counts_check;
		}

		$option_files = array();

		foreach ( $entries as $name => $entry ) {
			if ( $entry['directory'] || 0 !== strpos( $name, 'options/' ) ) {
				continue;
			}

			$option_files[] = $name;

			$value = $this->read_json_member( $zip, $name, $entries, $limits );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		if ( count( $option_files ) !== (int) $manifest['counts']['options'] ) {
			return new WP_Error(
				'manifest_count_mismatch',
				sprintf(
					/* translators: 1: declared option count, 2: actual option count */
					__( 'manifest.json declares %1$d options but the archive contains %2$d.', 'bricks-ie' ),
					(int) $manifest['counts']['options'],
					count( $option_files )
				)
			);
		}

		$has_index   = isset( $entries['posts/index.json'] );
		$posts_index = array();

		if ( ! $has_index ) {
			foreach ( $entries as $name => $entry ) {
				if ( ! $entry['directory'] && 0 === strpos( $name, 'posts/' ) ) {
					return new WP_Error( 'missing_index', __( 'Archive contains post files but no posts/index.json.', 'bricks-ie' ) );
				}
			}
		} else {
			$posts_index = $this->read_posts_index( $zip, 'posts/index.json', $entries, $limits );
			if ( is_wp_error( $posts_index ) ) {
				return $posts_index;
			}
		}

		$index_files = $this->validate_posts_index( $posts_index, true );
		if ( is_wp_error( $index_files ) ) {
			return $index_files;
		}

		$post_files = $this->validate_post_payloads( $zip, $entries, $limits, $index_files, 'posts/', true, self::SCHEMA_VERSION_1 );
		if ( is_wp_error( $post_files ) ) {
			return $post_files;
		}

		$unlisted = $this->find_unlisted_post_files( $entries, $index_files, 'posts/', 'posts/index.json' );
		if ( is_wp_error( $unlisted ) ) {
			return $unlisted;
		}

		if ( count( $index_files ) !== (int) $manifest['counts']['posts'] ) {
			return new WP_Error(
				'manifest_count_mismatch',
				sprintf(
					/* translators: 1: declared post count, 2: indexed post count */
					__( 'manifest.json declares %1$d posts but the posts index lists %2$d.', 'bricks-ie' ),
					(int) $manifest['counts']['posts'],
					count( $index_files )
				)
			);
		}

		return $this->build_result(
			$structure,
			array(
				'posts_index'  => $posts_index,
				'post_files'   => $post_files,
				'option_files' => $option_files,
				'warnings'     => $warnings,
			)
		);
	}

	/**
	 * Validate a schema version 2 archive.
	 *
	 * @param ZipArchive $zip       Open archive.
	 * @param array      $structure Structure context.
	 * @return array|WP_Error
	 */
	private function validate_schema_v2( $zip, $structure ) {
		$manifest = $structure['manifest'];
		$entries  = $structure['entries'];
		$limits   = $structure['limits'];
		$warnings = array();

		if ( ! isset( $manifest['format'] ) || self::MANIFEST_FORMAT !== $manifest['format'] ) {
			return new WP_Error(
				'invalid_manifest',
				sprintf(
					/* translators: %s: expected manifest format identifier */
					__( 'manifest.json must declare the format "%s".', 'bricks-ie' ),
					self::MANIFEST_FORMAT
				)
			);
		}

		if ( ! isset( $manifest['bricks'] ) || ! is_array( $manifest['bricks'] ) || empty( $manifest['bricks']['version'] ) || ! is_string( $manifest['bricks']['version'] ) ) {
			return new WP_Error( 'invalid_manifest', __( 'manifest.json is missing the source Bricks version.', 'bricks-ie' ) );
		}

		if ( ! isset( $manifest['domains'] ) || ! is_array( $manifest['domains'] ) ) {
			return new WP_Error( 'invalid_manifest', __( 'manifest.json is missing the domains map.', 'bricks-ie' ) );
		}

		foreach ( array( 'native_bricks', 'posts', 'template_conditions', 'media_files' ) as $flag ) {
			if ( ! isset( $manifest['domains'][ $flag ] ) || ! is_bool( $manifest['domains'][ $flag ] ) ) {
				return new WP_Error(
					'invalid_manifest',
					sprintf(
						/* translators: %s: domain flag name */
						__( 'manifest.json domains.%s must be a boolean.', 'bricks-ie' ),
						$flag
					)
				);
			}
		}

		if ( true === $manifest['domains']['media_files'] ) {
			return new WP_Error( 'unsupported_domain', __( 'Media file transport is not supported in this release.', 'bricks-ie' ) );
		}

		$counts_check = $this->validate_manifest_counts( $manifest, array( 'native_types', 'native_items', 'posts' ) );
		if ( is_wp_error( $counts_check ) ) {
			return $counts_check;
		}

		$native_bricks = $manifest['domains']['native_bricks'];
		$native        = null;

		if ( $native_bricks ) {
			if (
				! isset( $manifest['bricks']['native_schema'] ) || self::NATIVE_SCHEMA !== $manifest['bricks']['native_schema']
				|| ! isset( $manifest['bricks']['native_version'] ) || self::NATIVE_SCHEMA_VERSION !== $manifest['bricks']['native_version']
			) {
				return new WP_Error(
					'unsupported_native_schema',
					__( 'The native Bricks transfer schema declared in manifest.json is not supported.', 'bricks-ie' )
				);
			}

			if (
				empty( $manifest['bricks']['package_sha256'] ) || ! is_string( $manifest['bricks']['package_sha256'] )
				|| ! preg_match( '/^[a-f0-9]{64}$/i', $manifest['bricks']['package_sha256'] )
			) {
				return new WP_Error( 'invalid_manifest', __( 'manifest.json must declare a valid SHA-256 hash for the native package.', 'bricks-ie' ) );
			}

			if ( ! isset( $entries['bricks/package.zip'] ) ) {
				return new WP_Error( 'missing_native_package', __( 'Archive is missing bricks/package.zip.', 'bricks-ie' ) );
			}

			if ( ! isset( $entries['bricks/package.sha256'] ) ) {
				return new WP_Error( 'missing_native_checksum', __( 'Archive is missing bricks/package.sha256.', 'bricks-ie' ) );
			}

			$sha_raw = $this->read_member( $zip, 'bricks/package.sha256', $entries['bricks/package.sha256'], $limits['max_json_member_size'] );
			if ( is_wp_error( $sha_raw ) ) {
				return $sha_raw;
			}

			$declared_sha = $this->parse_sha256_file( $sha_raw );
			if ( null === $declared_sha ) {
				return new WP_Error( 'invalid_native_checksum', __( 'bricks/package.sha256 does not contain a valid SHA-256 hash.', 'bricks-ie' ) );
			}

			$package_raw = $this->read_member( $zip, 'bricks/package.zip', $entries['bricks/package.zip'], $limits['max_native_package_size'] );
			if ( is_wp_error( $package_raw ) ) {
				return $package_raw;
			}

			if ( strlen( $package_raw ) < 4 || self::ZIP_SIGNATURE_LOCAL !== substr( $package_raw, 0, 4 ) ) {
				return new WP_Error( 'invalid_native_package', __( 'The embedded native package is not a valid zip archive.', 'bricks-ie' ) );
			}

			$actual_sha = hash( 'sha256', $package_raw );

			if ( ! hash_equals( $declared_sha, $actual_sha ) ) {
				return new WP_Error( 'native_package_hash_mismatch', __( 'The embedded native package does not match bricks/package.sha256.', 'bricks-ie' ) );
			}

			if ( ! hash_equals( strtolower( $manifest['bricks']['package_sha256'] ), $actual_sha ) ) {
				return new WP_Error( 'manifest_hash_mismatch', __( 'The embedded native package does not match the SHA-256 declared in manifest.json.', 'bricks-ie' ) );
			}

			$native_structure = $this->validate_native_package( $package_raw, $limits );
			if ( is_wp_error( $native_structure ) ) {
				return $native_structure;
			}

			$native = array(
				'size'              => strlen( $package_raw ),
				'sha256'            => $actual_sha,
				'entries'           => $native_structure['entries'],
				'uncompressed_size' => $native_structure['uncompressed_size'],
			);
		} else {
			if ( ! empty( $manifest['bricks']['package_sha256'] ) ) {
				return new WP_Error( 'invalid_manifest', __( 'manifest.json declares a native package hash but no native package is included.', 'bricks-ie' ) );
			}

			if ( isset( $entries['bricks/package.zip'] ) || isset( $entries['bricks/package.sha256'] ) ) {
				return new WP_Error( 'unexpected_entry', __( 'Native package files are present but domains.native_bricks is false.', 'bricks-ie' ) );
			}

			if ( 0 !== (int) $manifest['counts']['native_types'] || 0 !== (int) $manifest['counts']['native_items'] ) {
				return new WP_Error( 'manifest_count_mismatch', __( 'manifest.json declares native items but no native package is included.', 'bricks-ie' ) );
			}
		}

		$posts_index = array();
		$post_files  = array();

		if ( $manifest['domains']['posts'] ) {
			if ( ! isset( $entries['katsarov/posts/index.json'] ) ) {
				return new WP_Error( 'missing_index', __( 'Archive is missing katsarov/posts/index.json.', 'bricks-ie' ) );
			}

			$posts_index = $this->read_posts_index( $zip, 'katsarov/posts/index.json', $entries, $limits );
			if ( is_wp_error( $posts_index ) ) {
				return $posts_index;
			}

			$index_files = $this->validate_posts_index( $posts_index, false );
			if ( is_wp_error( $index_files ) ) {
				return $index_files;
			}

			$post_files = $this->validate_post_payloads( $zip, $entries, $limits, $index_files, 'katsarov/posts/', false, self::SCHEMA_VERSION_2 );
			if ( is_wp_error( $post_files ) ) {
				return $post_files;
			}

			$unlisted = $this->find_unlisted_post_files( $entries, $index_files, 'katsarov/posts/', 'katsarov/posts/index.json' );
			if ( is_wp_error( $unlisted ) ) {
				return $unlisted;
			}

			if ( count( $index_files ) !== (int) $manifest['counts']['posts'] ) {
				return new WP_Error(
					'manifest_count_mismatch',
					sprintf(
						/* translators: 1: declared post count, 2: indexed post count */
						__( 'manifest.json declares %1$d posts but the posts index lists %2$d.', 'bricks-ie' ),
						(int) $manifest['counts']['posts'],
						count( $index_files )
					)
				);
			}
		} else {
			foreach ( $entries as $name => $entry ) {
				if ( ! $entry['directory'] && 0 === strpos( $name, 'katsarov/posts/' ) ) {
					return new WP_Error(
						'unexpected_entry',
						sprintf( __( 'Post file %s is present but domains.posts is false.', 'bricks-ie' ), $name )
					);
				}
			}

			if ( 0 !== (int) $manifest['counts']['posts'] ) {
				return new WP_Error( 'manifest_count_mismatch', __( 'manifest.json declares posts but domains.posts is false.', 'bricks-ie' ) );
			}
		}

		$has_conditions = isset( $entries['katsarov/template-conditions.json'] );

		if ( $manifest['domains']['template_conditions'] && ! $has_conditions ) {
			return new WP_Error( 'invalid_manifest', __( 'manifest.json declares template conditions but katsarov/template-conditions.json is missing.', 'bricks-ie' ) );
		}

		if ( ! $manifest['domains']['template_conditions'] && $has_conditions ) {
			return new WP_Error( 'unexpected_entry', __( 'katsarov/template-conditions.json is present but domains.template_conditions is false.', 'bricks-ie' ) );
		}

		if ( $has_conditions ) {
			$conditions = $this->read_json_member( $zip, 'katsarov/template-conditions.json', $entries, $limits, true );
			if ( is_wp_error( $conditions ) ) {
				return $conditions;
			}

			$warnings[] = __( 'Template conditions are experimental and must be reviewed before import.', 'bricks-ie' );
		}

		if ( ! isset( $entries['katsarov/export-warnings.json'] ) ) {
			return new WP_Error( 'missing_export_warnings', __( 'Archive is missing katsarov/export-warnings.json.', 'bricks-ie' ) );
		}

		$export_warnings = $this->read_json_member( $zip, 'katsarov/export-warnings.json', $entries, $limits, true );
		if ( is_wp_error( $export_warnings ) ) {
			return $export_warnings;
		}

		$sidecar_check = $this->validate_export_warnings( $export_warnings );
		if ( is_wp_error( $sidecar_check ) ) {
			return $sidecar_check;
		}
		$warnings  = array_merge( $warnings, $sidecar_check['warnings'] );
		$omissions = $sidecar_check['omissions'];

		if ( isset( $manifest['warnings'] ) && is_array( $manifest['warnings'] ) ) {
			foreach ( $manifest['warnings'] as $warning ) {
				if ( is_string( $warning ) && '' !== trim( $warning ) ) {
					$warnings[] = $warning;
				}
			}
		}

		return $this->build_result(
			$structure,
			array(
				'posts_index'    => $posts_index,
				'post_files'     => $post_files,
				'native_package' => $native,
				'warnings'       => $warnings,
				'omissions'      => $omissions,
			)
		);
	}

	/**
	 * Validate the manifest counts map.
	 *
	 * @param array $manifest Decoded manifest.
	 * @param array $keys     Required non-negative integer keys.
	 * @return true|WP_Error
	 */
	private function validate_manifest_counts( $manifest, $keys ) {
		if ( ! isset( $manifest['counts'] ) || ! is_array( $manifest['counts'] ) ) {
			return new WP_Error( 'invalid_manifest', __( 'manifest.json is missing the counts map.', 'bricks-ie' ) );
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $manifest['counts'][ $key ] ) || ! is_int( $manifest['counts'][ $key ] ) || $manifest['counts'][ $key ] < 0 ) {
				return new WP_Error(
					'invalid_manifest',
					sprintf(
						/* translators: %s: counts key name */
						__( 'manifest.json counts.%s must be a non-negative integer.', 'bricks-ie' ),
						$key
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate the schema-v2 exporter warnings/omissions sidecar.
	 *
	 * @param mixed $sidecar Decoded sidecar object.
	 * @return array|WP_Error Normalized warnings and typed omission records.
	 */
	private function validate_export_warnings( $sidecar ) {
		if ( ! is_array( $sidecar ) || ! array_key_exists( 'schema_version', $sidecar ) || 2 !== $sidecar['schema_version'] ) {
			return new WP_Error( 'invalid_export_warnings', __( 'katsarov/export-warnings.json must declare schema_version 2.', 'bricks-ie' ) );
		}

		if ( ! array_key_exists( 'warnings', $sidecar ) || ! array_key_exists( 'omissions', $sidecar ) ) {
			return new WP_Error( 'invalid_export_warnings', __( 'The export warnings sidecar must contain warnings and omissions arrays.', 'bricks-ie' ) );
		}

		$warnings = $sidecar['warnings'];
		$omissions = $sidecar['omissions'];
		if ( ! is_array( $warnings ) || ! is_array( $omissions ) || count( $warnings ) > 1000 || count( $omissions ) > 1000 ) {
			return new WP_Error( 'invalid_export_warnings', __( 'The export warnings sidecar contains invalid or excessive records.', 'bricks-ie' ) );
		}

		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || '' === trim( $warning ) || strlen( $warning ) > 4096 ) {
				return new WP_Error( 'invalid_export_warnings', __( 'Export warnings must be bounded non-empty strings.', 'bricks-ie' ) );
			}
		}

		foreach ( $omissions as $omission ) {
			if ( ! is_array( $omission ) || ! isset( $omission['id'], $omission['message'] ) || ! is_string( $omission['id'] ) || '' === trim( $omission['id'] ) || strlen( $omission['id'] ) > 256 || ! is_string( $omission['message'] ) || '' === trim( $omission['message'] ) || strlen( $omission['message'] ) > 4096 ) {
				return new WP_Error( 'invalid_export_warnings', __( 'Export omission records must contain bounded string id and message fields.', 'bricks-ie' ) );
			}
		}

		return array(
			'warnings'  => array_values( $warnings ),
			'omissions' => array_values( $omissions ),
		);
	}

	/**
	 * Read a posts index member and decode it.
	 *
	 * @param ZipArchive $zip     Open archive.
	 * @param string     $name    Index member name.
	 * @param array      $entries Entry map.
	 * @param array      $limits  Effective limits.
	 * @return array|WP_Error Decoded index list.
	 */
	private function read_posts_index( $zip, $name, $entries, $limits ) {
		$raw = $this->read_member( $zip, $name, $entries[ $name ], $limits['max_json_member_size'] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = self::decode_json_member( $raw, $name, $limits['max_json_depth'] );
		if ( is_wp_error( $decoded ) ) {
			return new WP_Error( 'invalid_index', $decoded->get_error_message() );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_index',
				sprintf( __( '%s must contain a JSON array.', 'bricks-ie' ), $name )
			);
		}

		return array_values( $decoded );
	}

	/**
	 * Validate the shape of a decoded posts index.
	 *
	 * @param array $posts_index Decoded index list.
	 * @param bool  $allow_empty_slugs Whether legacy status-aware validation may defer empty slugs.
	 * @return array|WP_Error Map of file name => array( slug, type ).
	 */
	private function validate_posts_index( $posts_index, $allow_empty_slugs = false ) {
		$files = array();
		$identities = array();

		foreach ( $posts_index as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'invalid_index', __( 'Each posts index entry must be a JSON object.', 'bricks-ie' ) );
			}

			if ( empty( $item['file'] ) || ! is_string( $item['file'] ) || ! preg_match( '/^[A-Za-z0-9_\-]+\.json$/', $item['file'] ) ) {
				return new WP_Error( 'invalid_index', __( 'Posts index entries must reference a valid JSON file name.', 'bricks-ie' ) );
			}

			if ( ! isset( $item['slug'] ) || ! is_string( $item['slug'] ) || ( '' === $item['slug'] && ! $allow_empty_slugs ) || ! isset( $item['type'] ) || ! is_string( $item['type'] ) || '' === $item['type'] ) {
				return new WP_Error( 'invalid_index', __( 'Posts index entries must include a slug and a type.', 'bricks-ie' ) );
			}

			if ( isset( $files[ $item['file'] ] ) ) {
				return new WP_Error(
					'invalid_index',
					sprintf( __( 'Duplicate posts index entry: %s', 'bricks-ie' ), $item['file'] )
				);
			}

			$identity = $item['type'] . "\0" . $item['slug'];
			if ( isset( $identities[ $identity ] ) ) {
				return new WP_Error( 'invalid_index', __( 'Posts index contains a duplicate (type, slug) identity.', 'bricks-ie' ) );
			}
			$identities[ $identity ] = true;

			$files[ $item['file'] ] = array(
				'slug' => $item['slug'],
				'type' => $item['type'],
			);
		}

		return $files;
	}

	/**
	 * Validate every indexed post payload member.
	 *
	 * @param ZipArchive $zip             Open archive.
	 * @param array      $entries         Entry map.
	 * @param array      $limits          Effective limits.
	 * @param array      $index_files     Map from validate_posts_index().
	 * @param string     $prefix          Member path prefix.
	 * @param bool       $allow_templates Whether bricks_template payloads are allowed.
	 * @param int        $schema_version Schema version governing slug compatibility.
	 * @return array|WP_Error List of validated payload member names.
	 */
	private function validate_post_payloads( $zip, $entries, $limits, $index_files, $prefix, $allow_templates, $schema_version ) {
		$post_files = array();
		$source_ids = array();

		foreach ( $index_files as $file => $item ) {
			$name = $prefix . $file;

			if ( ! isset( $entries[ $name ] ) ) {
				return new WP_Error(
					'missing_post_file',
					sprintf( __( 'Missing post file: %s', 'bricks-ie' ), $name )
				);
			}

			$payload = $this->read_json_member( $zip, $name, $entries, $limits, true );
			if ( is_wp_error( $payload ) ) {
				if ( 'invalid_json' === $payload->get_error_code() ) {
					return new WP_Error(
						'invalid_post',
						sprintf( __( 'Invalid JSON in %s', 'bricks-ie' ), $name )
					);
				}

				return $payload;
			}

			if (
				! array_key_exists( 'id', $payload ) || ! is_int( $payload['id'] ) || $payload['id'] <= 0
				|| ! isset( $payload['slug'], $payload['type'], $payload['status'], $payload['title'] )
				|| ! is_string( $payload['slug'] ) || ! is_string( $payload['type'] )
				|| ! is_string( $payload['status'] ) || ! is_string( $payload['title'] )
			) {
				return new WP_Error(
					'invalid_post',
					sprintf( __( 'Post payload in %s is missing required fields.', 'bricks-ie' ), $name )
				);
			}

			if ( isset( $source_ids[ $payload['id'] ] ) ) {
				return new WP_Error( 'invalid_post', __( 'Post payload source IDs must be unique across the archive.', 'bricks-ie' ) );
			}
			$source_ids[ $payload['id'] ] = true;

			if ( '' === $payload['slug'] && ( self::SCHEMA_VERSION_2 === $schema_version || ! in_array( $payload['status'], array( 'draft', 'pending', 'private' ), true ) ) ) {
				return new WP_Error( 'invalid_post', sprintf( __( 'Post payload in %s has an empty slug that its status does not permit.', 'bricks-ie' ), $name ) );
			}

			if ( isset( $payload['meta'] ) && ! is_array( $payload['meta'] ) ) {
				return new WP_Error(
					'invalid_post',
					sprintf( __( 'Post meta in %s must be a JSON object.', 'bricks-ie' ), $name )
				);
			}

			if ( $payload['slug'] !== $item['slug'] || $payload['type'] !== $item['type'] ) {
				return new WP_Error(
					'index_payload_mismatch',
					sprintf( __( 'Post payload %s does not match its posts index entry.', 'bricks-ie' ), $name )
				);
			}

			if ( ! $allow_templates && 'bricks_template' === $payload['type'] ) {
				return new WP_Error(
					'forbidden_post_type',
					sprintf(
						__( 'bricks_template posts belong to the native Bricks package in schema version 2 archives: %s', 'bricks-ie' ),
						$name
					)
				);
			}

			$post_files[] = $name;
		}

		return $post_files;
	}

	/**
	 * Reject post payload files that are not listed in the posts index.
	 *
	 * @param array  $entries     Entry map.
	 * @param array  $index_files Map from validate_posts_index().
	 * @param string $prefix      Member path prefix.
	 * @param string $index_name  Index member name.
	 * @return true|WP_Error
	 */
	private function find_unlisted_post_files( $entries, $index_files, $prefix, $index_name ) {
		foreach ( $entries as $name => $entry ) {
			if ( $entry['directory'] || $index_name === $name || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}

			$file = substr( $name, strlen( $prefix ) );

			if ( ! isset( $index_files[ $file ] ) ) {
				return new WP_Error(
					'unlisted_post_file',
					sprintf( __( 'Post file %s is not listed in the posts index.', 'bricks-ie' ), $name )
				);
			}
		}

		return true;
	}

	/**
	 * Read a raw archive member with a size guard.
	 *
	 * @param ZipArchive $zip      Open archive.
	 * @param string     $name     Member name.
	 * @param array      $entry    Entry metadata from validate_structure().
	 * @param int        $max_size Maximum allowed uncompressed bytes.
	 * @return string|WP_Error
	 */
	private function read_member( $zip, $name, $entry, $max_size ) {
		if ( $entry['size'] > $max_size ) {
			return new WP_Error(
				'entry_too_large',
				sprintf(
					/* translators: 1: archive entry name, 2: entry size in bytes, 3: limit in bytes */
					__( 'Archive entry %1$s is %2$d bytes uncompressed, exceeding the %3$d byte limit.', 'bricks-ie' ),
					$name,
					(int) $entry['size'],
					(int) $max_size
				)
			);
		}

		$raw = $zip->getFromIndex( $entry['index'] );

		if ( false === $raw ) {
			return new WP_Error(
				'zip_read_failed',
				sprintf( __( 'Could not read archive entry: %s', 'bricks-ie' ), $name )
			);
		}

		return $raw;
	}

	/**
	 * Read and decode a JSON member.
	 *
	 * @param ZipArchive $zip           Open archive.
	 * @param string     $name          Member name.
	 * @param array      $entries       Entry map.
	 * @param array      $limits        Effective limits.
	 * @param bool       $require_array Optional. Require an array/object result.
	 * @return mixed|WP_Error
	 */
	private function read_json_member( $zip, $name, $entries, $limits, $require_array = false ) {
		if ( ! isset( $entries[ $name ] ) ) {
			return new WP_Error(
				'file_not_found',
				sprintf( __( 'Missing archive entry: %s', 'bricks-ie' ), $name )
			);
		}

		$raw = $this->read_member( $zip, $name, $entries[ $name ], $limits['max_json_member_size'] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = self::decode_json_member( $raw, $name, $limits['max_json_depth'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( $require_array && ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_json',
				sprintf( __( '%s must contain a JSON object or array.', 'bricks-ie' ), $name )
			);
		}

		return $decoded;
	}

	/**
	 * Parse a .sha256 checksum member.
	 *
	 * Accepts a bare hash or sha256sum-style output ("<hash>  <filename>").
	 *
	 * @param string $raw Raw member bytes.
	 * @return string|null Lower-case hex hash or null when invalid.
	 */
	private function parse_sha256_file( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( $raw );

		if ( '' === $raw ) {
			return null;
		}

		$parts = preg_split( '/\s+/', $raw );
		$hash  = strtolower( ltrim( (string) $parts[0], '*' ) );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}

		return $hash;
	}

	/**
	 * Build the normalized success report.
	 *
	 * @param array $structure Structure context.
	 * @param array $extra     Schema-specific additions.
	 * @return array
	 */
	private function build_result( $structure, $extra ) {
		$result = array(
			'schema_version'    => $structure['schema_version'],
			'manifest'          => $structure['manifest'],
			'entry_count'       => $structure['entry_count'],
			'entries'           => array_keys( $structure['entries'] ),
			'compressed_size'   => $structure['compressed_size'],
			'uncompressed_size' => $structure['total_uncompressed'],
			'posts_index'       => array(),
			'post_files'        => array(),
			'option_files'      => array(),
			'native_package'    => null,
			'warnings'          => array(),
			'omissions'         => array(),
		);

		foreach ( $extra as $key => $value ) {
			$result[ $key ] = $value;
		}

		return $result;
	}
}
