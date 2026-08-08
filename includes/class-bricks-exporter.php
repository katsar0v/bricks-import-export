<?php
/**
 * Bricks Builder exporter.
 *
 * Builds a zip archive containing Bricks options, templates, pages, and Bricks
 * meta on configured post types. The archive can be streamed to the browser
 * (admin) or written to disk (WP-CLI).
 *
 * Two archive schemas are supported:
 *
 * - Schema version 1 (legacy): `options/*.json` plus `posts/*.json` with
 *   base64-encoded serialized meta. This remains the compatibility fallback
 *   when the Bricks 2.4 native unified transfer is unavailable or unsupported.
 * - Schema version 2 (1.1.0): `manifest.json`, an opaque native Bricks
 *   package (`bricks/package.zip` + `bricks/package.sha256`), Katsarov-owned
 *   posts under `katsarov/posts/` with JSON-safe meta, and
 *   `katsarov/export-warnings.json`. Templates never appear in Katsarov posts
 *   in schema version 2, native-owned options are never duplicated as
 *   `options/*.json`, and the finished archive is reopened and validated with
 *   Bricks_IE_Archive_Validator; failed output is deleted.
 *
 * @package BricksIE
 */

class Bricks_IE_Exporter {

	/**
	 * Legacy archive schema version (plugin 1.0.x layout).
	 *
	 * @var int
	 */
	const SCHEMA_VERSION_1 = 1;

	/**
	 * Unified transfer archive schema version (plugin 1.1.0 layout).
	 *
	 * @var int
	 */
	const SCHEMA_VERSION_2 = 2;

	/**
	 * Outer manifest format identifier used by schema version 2.
	 *
	 * @var string
	 */
	const MANIFEST_FORMAT = 'katsarov/bricks-import-export';

	/**
	 * Post types owned by the native Bricks package in schema version 2.
	 *
	 * These never appear in the Katsarov posts payload of a schema version 2
	 * archive; they travel inside the opaque native package instead.
	 *
	 * @var string[]
	 */
	const NATIVE_OWNED_POST_TYPES = array( 'bricks_template' );

	/**
	 * Injected transfer adapter, or null to construct the default.
	 *
	 * @var Bricks_IE_Bricks_Transfer_Adapter|null
	 */
	private $adapter;

	/**
	 * Injected archive validator, or null to construct the default.
	 *
	 * @var Bricks_IE_Archive_Validator|null
	 */
	private $validator;

	/**
	 * Cached native transfer capability report.
	 *
	 * @var array|null
	 */
	private $capabilities_report;

	/**
	 * Constructor.
	 *
	 * Dependencies are injectable so the export contract can be exercised
	 * against stubs in isolation; production uses the defaults.
	 *
	 * @since 1.1.0
	 *
	 * @param array $dependencies {
	 *     Optional overrides.
	 *
	 *     @type Bricks_IE_Bricks_Transfer_Adapter $adapter   Transfer adapter.
	 *     @type Bricks_IE_Archive_Validator       $validator Archive validator.
	 * }
	 */
	public function __construct( $dependencies = array() ) {
		if ( ! is_array( $dependencies ) ) {
			$dependencies = array();
		}

		$this->adapter   = isset( $dependencies['adapter'] ) && is_object( $dependencies['adapter'] )
			? $dependencies['adapter']
			: null;
		$this->validator = isset( $dependencies['validator'] ) && is_object( $dependencies['validator'] )
			? $dependencies['validator']
			: null;
	}

	/**
	 * Get the list of option names to export.
	 *
	 * @return array
	 */
	private function get_option_names() {
		return bricks_ie_get_option_names();
	}

	/**
	 * Get the list of meta keys to export.
	 *
	 * @return array
	 */
	private function get_meta_keys() {
		return apply_filters( 'bricks_ie_meta_keys', array(
			'_bricks_page_content_2',
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
			'_bricks_editor_mode',
			'_bricks_template_type',
			'_bricks_template_settings',
			'_bricks_page_settings',
		) );
	}

	/**
	 * Get the list of post types to export.
	 *
	 * @return array
	 */
	private function get_post_types() {
		return bricks_ie_get_post_types();
	}

	/**
	 * Build the zip archive at the given file path.
	 *
	 * By default the exporter uses schema version 2 when the Bricks 2.4 native
	 * unified transfer is verified, and falls back to the legacy schema
	 * version 1 layout otherwise. Callers that pass only an output path keep
	 * the previous behavior and result keys.
	 *
	 * @since 1.1.0 Accepts an optional request array.
	 *
	 * @param string $output_path Absolute path where the zip file will be written.
	 * @param array  $request {
	 *     Optional export request.
	 *
	 *     @type int      $schema                   Force 1 (legacy) or 2 (native transfer). Default: auto-detect.
	 *     @type bool     $allow_sensitive_settings Include sensitive settings tabs. Default false.
	 *     @type string[] $types                    Restrict the native export to these transfer type IDs.
	 * }
	 * @return array|WP_Error On success returns array with keys 'file', 'options_count', 'posts_count',
	 *                        'size', 'schema_version', 'warnings' (plus 'omissions', 'domains', 'native',
	 *                        'validated' for schema version 2). On failure a WP_Error.
	 */
	public function build_zip( $output_path, $request = array() ) {
		if ( ! is_array( $request ) ) {
			$request = array();
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'ZipArchive is not available on this server.', 'bricks-ie' ) );
		}

		$forced_schema = isset( $request['schema'] ) ? (int) $request['schema'] : 0;

		if ( self::SCHEMA_VERSION_2 === $forced_schema ) {
			return $this->build_zip_v2( $output_path, $request );
		}

		if ( self::SCHEMA_VERSION_1 === $forced_schema ) {
			return $this->build_zip_v1( $output_path, array(
				__( 'Schema version 1 was explicitly requested; the legacy format does not use the Bricks native transfer.', 'bricks-ie' ),
			), $request );
		}

		if ( 0 !== $forced_schema ) {
			return new WP_Error(
				'bricks_ie_unsupported_schema',
				sprintf(
					/* translators: %s: requested schema version. */
					__( 'Unsupported export schema version "%s". Supported versions are 1 and 2.', 'bricks-ie' ),
					(string) $request['schema']
				)
			);
		}

		$report = $this->detect_native_transfer();

		if ( $this->native_transfer_usable( $report ) ) {
			return $this->build_zip_v2( $output_path, $request );
		}

		$errors   = isset( $report['errors'] ) ? (array) $report['errors'] : array();
		$warnings = array(
			sprintf(
				/* translators: %s: machine-readable availability problems. */
				__( 'The Bricks 2.4 native unified transfer is unavailable (%s). The export used the legacy schema version 1 fallback.', 'bricks-ie' ),
				! empty( $errors ) ? implode( ', ', array_map( 'strval', $errors ) ) : __( 'unknown reason', 'bricks-ie' )
			),
		);

		return $this->build_zip_v1( $output_path, $warnings, $request );
	}

	/**
	 * Handle the admin export request — build the zip and stream it to the browser.
	 */
	public function download() {
		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$temp_placeholder = wp_tempnam( 'bricks-ie-export-' );
		if ( ! $temp_placeholder ) {
			wp_die( esc_html__( 'Could not create temporary file for the export.', 'bricks-ie' ) );
		}

		// wp_tempnam() deliberately creates a placeholder, commonly ending in
		// .tmp.  The validator uses the extension as part of its archive
		// contract, so rename the secure placeholder before handing it to the
		// exporter.  Keep the placeholder path for cleanup if the rename fails.
		$temp_file = $this->prepare_download_archive_path( $temp_placeholder );
		if ( ! $temp_file ) {
			@unlink( $temp_placeholder );
			wp_die( esc_html__( 'Could not create temporary file for the export.', 'bricks-ie' ) );
		}

		$result = $this->build_zip( $temp_file );

		if ( is_wp_error( $result ) ) {
			@unlink( $temp_placeholder );
			@unlink( $temp_file );
			wp_die( esc_html( $result->get_error_message() ) );
		}

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="bricks-export-' . gmdate( 'Y-m-d' ) . '.zip"' );
		header( 'Content-Length: ' . $result['size'] );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $temp_file );
		@unlink( $temp_placeholder );
		@unlink( $temp_file );
		exit;
	}

	/**
	 * Turn a wp_tempnam() placeholder into a unique path ending in .zip.
	 *
	 * @param string $placeholder Secure placeholder created by wp_tempnam().
	 * @return string|false
	 */
	private function prepare_download_archive_path( $placeholder ) {
		$placeholder = (string) $placeholder;
		if ( '' === $placeholder ) {
			return false;
		}

		$archive = preg_replace( '/\.tmp$/i', '.zip', $placeholder );
		if ( ! is_string( $archive ) || $archive === $placeholder ) {
			$archive = $placeholder . '.zip';
		}

		// wp_tempnam() supplies the uniqueness and secure directory. Never
		// overwrite an existing path if a platform's temp naming is unusual.
		if ( file_exists( $archive ) || ! @rename( $placeholder, $archive ) ) {
			return false;
		}

		return $archive;
	}

	// ==================================================================
	// Schema version 1 (legacy compatibility layout)
	// ==================================================================

	/**
	 * Build a legacy schema version 1 archive.
	 *
	 * Preserves the historical 1.0.x layout exactly: `options/*.json` plus
	 * `posts/*.json` with base64-encoded serialized meta, including templates.
	 *
	 * @since 1.1.0
	 *
	 * @param string $output_path Absolute zip path.
	 * @param array  $warnings    Warnings to report for this fallback.
	 * @param array  $request     Export request, including sensitive-settings authorization.
	 * @return array|WP_Error
	 */
	private function build_zip_v1( $output_path, array $warnings = array(), array $request = array() ) {
		$temp_path = $this->create_temporary_output_path( $output_path );
		if ( false === $temp_path ) {
			return new WP_Error( 'zip_temp_failed', __( 'Could not create a temporary path for the zip archive.', 'bricks-ie' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_open_failed', __( 'Could not create the zip archive.', 'bricks-ie' ) );
		}

		$options     = $this->collect_options();
		$omissions   = array();
		$allow_sensitive = ! empty( $request['allow_sensitive_settings'] );

		// Validate before writing any member. If a filtered option payload is
		// malformed, close and remove the partially-created archive.
		if ( array_key_exists( 'bricks_global_settings', $options ) && ! is_array( $options['bricks_global_settings'] ) ) {
			$zip->close();
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'invalid_global_settings', __( 'The bricks_global_settings option must be an array.', 'bricks-ie' ) );
		}

		if ( ! $allow_sensitive && isset( $options['bricks_global_settings'] ) ) {
			$removed_keys = array();
			$this->redact_legacy_sensitive_settings( $options['bricks_global_settings'], $removed_keys );

			if ( ! empty( $removed_keys ) ) {
				$warnings[] = __( 'Sensitive global settings were excluded from the legacy export. They are included only when explicitly authorized.', 'bricks-ie' );
				$omissions[] = array(
					'id'   => 'sensitive_settings',
					'keys' => $removed_keys,
					'message' => __( 'Sensitive global-setting keys were omitted because allow_sensitive_settings was not authorized for this export.', 'bricks-ie' ),
				);
			}
		}
		$posts       = $this->collect_posts();
		$prepared    = $this->prepare_posts_for_export( $posts );
		if ( is_wp_error( $prepared ) ) {
			$zip->close();
			$this->remove_temporary_output( $temp_path );
			return $prepared;
		}
		$posts = $prepared['posts'];
		$warnings = array_merge( $warnings, $prepared['warnings'] );
		$omissions = array_merge( $omissions, $prepared['omissions'] );
		$posts_index = array();

		foreach ( $options as $name => $value ) {
			$error = $this->zip_add_json( $zip, 'options/' . $name . '.json', $value );
			if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }
		}

		if ( ! empty( $posts ) ) {
			$used_files = array();
			foreach ( $posts as $item ) {
				$filename = $this->build_post_filename( $item, $used_files );

				$posts_index[] = array(
					'slug' => $item['slug'],
					'type' => $item['type'],
					'file' => $filename,
				);
				$used_files[ $filename ] = true;

				$error = $this->zip_add_json( $zip, 'posts/' . $filename, $item );
				if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }
			}

			$error = $this->zip_add_json( $zip, 'posts/index.json', $posts_index );
			if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }
		}

		$manifest = $this->build_manifest( count( $options ), count( $posts ), $warnings, $omissions );
		$error = $this->zip_add_json( $zip, 'manifest.json', $manifest );
		if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }

		// Finalize and inspect the temporary archive before replacing a caller's
		// existing destination. A failed publication must not remove that file.
		if ( true !== $zip->close() ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_close_failed', __( 'The zip archive could not be finalized.', 'bricks-ie' ) );
		}
		$size = filesize( $temp_path );
		if ( false === $size ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_filesize_failed', __( 'The completed temporary zip archive size could not be determined.', 'bricks-ie' ) );
		}
		if ( ! $this->publish_temporary_output( $temp_path, $output_path ) ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_publish_failed', __( 'The completed zip archive could not be published.', 'bricks-ie' ) );
		}

		return array(
			'file'           => $output_path,
			'options_count'  => count( $options ),
			'posts_count'    => count( $posts ),
			'size'           => $size,
			'schema_version' => self::SCHEMA_VERSION_1,
			'warnings'       => array_values( $warnings ),
			'omissions'      => $omissions,
		);
	}

	/**
	 * Collect Bricks options. Skips options that do not exist.
	 *
	 * @return array Associative array of option_name => value.
	 */
	private function collect_options() {
		$options = array();

		foreach ( $this->get_option_names() as $name ) {
			$value = get_option( $name, null );
			if ( null !== $value ) {
				$options[ $name ] = $value;
			}
		}

		return $options;
	}

	private function get_legacy_sensitive_keys() {
		$defaults = array( 'apiKeyGoogleMaps', 'apiKeyGoogleRecaptcha', 'apiSecretKeyGoogleRecaptcha', 'executeCodeEnabled', 'customCode', 'customCss', 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter', 'myTemplatesPassword', 'remoteTemplatesPassword', 'password', 'pass' );
		$filtered = function_exists( 'bricks_ie_get_legacy_sensitive_settings_keys' ) ? bricks_ie_get_legacy_sensitive_settings_keys() : array();
		if ( ! is_array( $filtered ) ) { $filtered = array(); }
		return array_values( array_unique( array_merge( $defaults, array_filter( $filtered, 'is_string' ) ) ) );
	}

	/** Recursively remove sensitive aliases, recording paths but never values. */
	private function redact_legacy_sensitive_settings( &$value, &$removed_keys, $path = '' ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		$sensitive_keys = array_fill_keys( $this->get_legacy_sensitive_keys(), true );
		foreach ( $value as $key => &$child ) {
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;
			if ( is_string( $key ) && isset( $sensitive_keys[ $key ] ) ) {
				unset( $value[ $key ] );
				$removed_keys[] = $key_path;
				continue;
			}
			$this->redact_legacy_sensitive_settings( $child, $removed_keys, $key_path );
		}
		unset( $child );
	}

	private function prepare_posts_for_export( array $posts ) {
		$seen = array(); $out = array(); $warnings = array(); $omissions = array();
		foreach ( $posts as $item ) {
			$type = isset( $item['type'] ) ? (string) $item['type'] : '';
			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			if ( '' === $slug ) {
				$warnings[] = sprintf( __( 'Post %d was omitted because it has an empty slug.', 'bricks-ie' ), isset( $item['id'] ) ? (int) $item['id'] : 0 );
				$omissions[] = array( 'id' => 'empty_slug', 'post_id' => isset( $item['id'] ) ? (int) $item['id'] : 0, 'message' => __( 'Posts with empty slugs cannot be imported and were omitted.', 'bricks-ie' ) );
				continue;
			}
			$identity = $type . "\0" . $slug;
			if ( isset( $seen[ $identity ] ) ) {
				return new WP_Error( 'bricks_ie_duplicate_post_identity', sprintf( __( 'Duplicate post identity "%1$s" / "%2$s" cannot be exported.', 'bricks-ie' ), $type, $slug ) );
			}
			$seen[ $identity ] = true; $out[] = $item;
		}
		return array( 'posts' => $out, 'warnings' => $warnings, 'omissions' => $omissions );
	}

	private function create_temporary_output_path( $output_path ) {
		$dir = dirname( $output_path );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { return false; }
		$placeholder = tempnam( $dir, '.' . basename( $output_path ) . '.' );
		if ( false === $placeholder ) { return false; }
		$archive = $placeholder . '.zip';
		if ( file_exists( $archive ) || is_link( $archive ) || ! @rename( $placeholder, $archive ) ) {
			@unlink( $placeholder );
			return false;
		}
		return $archive;
	}

	private function remove_temporary_output( $path ) { if ( is_string( $path ) && '' !== $path ) { @unlink( $path ); } }

	private function publish_temporary_output( $temp_path, $output_path ) {
		// Do not replace a symlink supplied as the caller's destination.
		if ( is_link( $output_path ) ) {
			return false;
		}
		return @rename( $temp_path, $output_path );
	}

	private function zip_add_json( $zip, $name, $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		if ( false === $json ) { return new WP_Error( 'bricks_ie_json_encode_failed', sprintf( __( 'Could not encode archive member "%s" as JSON.', 'bricks-ie' ), $name ) ); }
		if ( true !== $zip->addFromString( $name, $json ) ) { return new WP_Error( 'bricks_ie_zip_member_failed', sprintf( __( 'Could not add archive member "%s".', 'bricks-ie' ), $name ) ); }
		return true;
	}

	/**
	 * Collect posts that carry Bricks meta.
	 *
	 * @return array Array of post data items with meta encoded as base64 of serialize.
	 */
	private function collect_posts() {
		$posts = get_posts( array(
			'post_type'      => $this->get_post_types(),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$out     = array();
		$meta_keys = $this->get_meta_keys();

		foreach ( $posts as $p ) {
			$meta = array();

			foreach ( $meta_keys as $k ) {
				$v = get_post_meta( $p->ID, $k, true );
				if ( '' !== $v ) {
					$meta[ $k ] = base64_encode( serialize( $v ) );
				}
			}

			if ( ! empty( $meta ) ) {
				$out[] = array(
					'id'     => $p->ID,
					'slug'   => $p->post_name,
					'type'   => $p->post_type,
					'status' => $p->post_status,
					'title'  => $p->post_title,
					'meta'   => $meta,
				);
			}
		}

		return $out;
	}

	/**
	 * Build the manifest describing the export.
	 *
	 * @param int $options_count Number of options included.
	 * @param int $posts_count   Number of posts included.
	 * @return array
	 */
	private function build_manifest( $options_count, $posts_count, array $warnings = array(), array $omissions = array() ) {
		$bricks_theme = wp_get_theme( 'bricks' );

		return array(
			'version'        => 1,
			'plugin_version' => defined( 'BRICKS_IE_VERSION' ) ? BRICKS_IE_VERSION : null,
			'generated_at'   => gmdate( 'c' ),
			'site_url'       => home_url(),
			'bricks_version' => $bricks_theme->exists() ? $bricks_theme->get( 'Version' ) : null,
			'counts'         => array(
				'options' => $options_count,
				'posts'   => $posts_count,
			),
			// Additive disclosure fields. Keep the historical v1 fields above
			// unchanged so old readers can still consume this manifest.
			'warnings'       => array_values( $warnings ),
			'omissions'      => array_values( $omissions ),
		);
	}

	// ==================================================================
	// Schema version 2 (native unified transfer layout)
	// ==================================================================

	/**
	 * Build a schema version 2 archive.
	 *
	 * Flow: verify the native contract, list native items, build an explicit
	 * item selection, export the opaque native package, inspect it, collect
	 * Katsarov-owned posts with JSON-safe meta, write the outer archive, then
	 * reopen and validate it with Bricks_IE_Archive_Validator. Failed output
	 * is deleted.
	 *
	 * @since 1.1.0
	 *
	 * @param string $output_path Absolute zip path.
	 * @param array  $request     Export request, see build_zip().
	 * @return array|WP_Error
	 */
	private function build_zip_v2( $output_path, array $request ) {
		$adapter = $this->get_adapter();
		if ( null === $adapter ) {
			return $this->native_unavailable_error( array( 'adapter_unavailable' ) );
		}

		$report = $this->detect_native_transfer();
		if ( ! $this->native_transfer_usable( $report ) ) {
			$errors = isset( $report['errors'] ) ? (array) $report['errors'] : array();
			return $this->native_unavailable_error( $errors );
		}

		$allow_sensitive = ! empty( $request['allow_sensitive_settings'] );
		$requested_types = isset( $request['types'] ) && is_array( $request['types'] ) ? $request['types'] : array();

		// Explicit list flow: enumerate the exportable native items.
		$list = $adapter->list_items( $requested_types );
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$list_types = isset( $list['types'] ) && is_array( $list['types'] ) ? $list['types'] : array();
		$list_inventory = $this->native_list_inventory( $list_types );
		$warnings  = array();
		$omissions = $this->get_v2_omissions();
		$intended_types = ! empty( $requested_types )
			? array_values( array_unique( array_map( 'strval', $requested_types ) ) )
			: ( isset( $report['type_ids'] ) && is_array( $report['type_ids'] ) ? array_values( array_map( 'strval', $report['type_ids'] ) ) : Bricks_IE_Bricks_Transfer_Adapter::KNOWN_TYPE_IDS );

		foreach ( $intended_types as $type ) {
			if ( ! array_key_exists( $type, $list_inventory ) ) {
				if ( ! empty( $requested_types ) ) {
					return new WP_Error( 'bricks_ie_requested_native_type_omitted', sprintf( __( 'The explicitly requested native transfer type "%s" was omitted by permissions or native listing.', 'bricks-ie' ), $type ), array( 'type' => $type ) );
				}
				$warnings[] = sprintf( __( 'Native transfer type "%s" was unavailable or omitted by permissions and was not exported.', 'bricks-ie' ), $type );
				$omissions[] = array( 'id' => 'native_type_omitted', 'type' => $type, 'message' => __( 'The native type was omitted by permissions or native listing.', 'bricks-ie' ) );
			} elseif ( empty( $list_inventory[ $type ] ) ) {
				$warnings[] = sprintf( __( 'Native transfer type "%s" was listed but contained no exportable items.', 'bricks-ie' ), $type );
				$omissions[] = array( 'id' => 'native_type_empty', 'type' => $type, 'message' => __( 'The native type was listed with zero exportable items.', 'bricks-ie' ) );
			}
		}

		// Explicit items flow: select concrete item IDs per transfer type.
		$selection_result = $this->build_native_selection(
			$list_types,
			$allow_sensitive
		);
		$selection        = $selection_result['selection'];

		if ( $selection_result['sensitive_excluded'] ) {
			$warnings[]  = __( 'Sensitive settings (API keys, custom code, template passwords) were excluded from the export. They are included only when explicitly authorized.', 'bricks-ie' );
			$omissions[] = array(
				'id'      => 'sensitive_settings',
				'message' => __( 'Sensitive settings tabs were excluded because allow_sensitive_settings was not authorized for this export.', 'bricks-ie' ),
			);
		}

		// Explicit export flow: generate the opaque native package.
		$native_package  = '';
		$native_sha      = '';
		$native_types    = 0;
		$native_items    = 0;
		$native_included = false;

		if ( ! empty( $selection['types'] ) ) {
			$export = $adapter->export_package(
				$selection,
				array( 'allow_sensitive_settings' => $allow_sensitive )
			);
			if ( is_wp_error( $export ) ) {
				return $export;
			}

			$bytes = $this->decode_native_package( $export );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}

			// Explicit inspect flow: verify the package before embedding it.
			$inspect = $adapter->inspect_package( $bytes );
			if ( is_wp_error( $inspect ) ) {
				return $inspect;
			}

			$inspect_schema = isset( $inspect['manifest']['schema'] ) ? (string) $inspect['manifest']['schema'] : '';
			if ( '' !== $inspect_schema && Bricks_IE_Bricks_Transfer_Adapter::EXPECTED_SCHEMA !== $inspect_schema ) {
				return new WP_Error(
					'bricks_ie_native_schema_mismatch',
					sprintf(
						/* translators: %s: schema reported by the native package inspection. */
						__( 'The generated native package declares the unexpected transfer schema "%s" and was rejected.', 'bricks-ie' ),
						$inspect_schema
					),
					array( 'schema' => $inspect_schema )
				);
			}

			$inspected_inventory = $this->native_manifest_inventory( isset( $inspect['manifest'] ) && is_array( $inspect['manifest'] ) ? $inspect['manifest'] : array() );
			foreach ( $selection['items'] as $type => $ids ) {
				foreach ( $ids as $id ) {
					if ( ! isset( $inspected_inventory['items'][ $type ][ (string) $id ] ) ) {
						return new WP_Error( 'bricks_ie_native_selected_item_dropped', sprintf( __( 'The native export silently dropped selected item "%1$s" from type "%2$s".', 'bricks-ie' ), (string) $id, $type ), array( 'type' => $type, 'item' => (string) $id ) );
					}
				}
			}

			$native_package  = $bytes;
			$native_sha      = hash( 'sha256', $bytes );
			$native_types    = $inspected_inventory['types'];
			$native_items    = $inspected_inventory['items_count'];
			$native_included = true;
		} else {
			$warnings[] = __( 'No native Bricks items were available for this export; the native package was omitted.', 'bricks-ie' );
		}

		// Katsarov-owned posts. Templates are native-owned in schema version 2
		// and are never included here.
		$posts_result = $this->collect_posts_v2();
		$prepared     = $this->prepare_posts_for_export( $posts_result['posts'] );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$posts        = $prepared['posts'];
		foreach ( $posts_result['warnings'] as $warning ) {
			$warnings[] = $warning;
		}
		$warnings  = array_merge( $warnings, $prepared['warnings'] );
		$omissions = array_merge( $omissions, $prepared['omissions'] );

		$domains = array(
			'native_bricks'       => $native_included,
			'posts'               => ! empty( $posts ),
			'template_conditions' => false,
			'media_files'         => false,
		);

		$manifest = array(
			'format'            => self::MANIFEST_FORMAT,
			'version'           => self::SCHEMA_VERSION_2,
			'plugin_version'    => defined( 'BRICKS_IE_VERSION' ) ? BRICKS_IE_VERSION : null,
			'generated_at'      => gmdate( 'c' ),
			'site_url'          => home_url(),
			'wordpress_version' => $this->get_wordpress_version(),
			'php_version'       => PHP_VERSION,
			'bricks'            => array(
				'version'        => (string) $report['bricks_version'],
				'native_schema'  => Bricks_IE_Bricks_Transfer_Adapter::EXPECTED_SCHEMA,
				'native_version' => Bricks_IE_Bricks_Transfer_Adapter::EXPECTED_VERSION,
			),
			'domains'           => $domains,
			'counts'            => array(
				'native_types' => $native_types,
				'native_items' => $native_items,
				'posts'        => count( $posts ),
			),
			'warnings'          => array_values( $warnings ),
		);

		if ( $native_included ) {
			$manifest['bricks']['package_sha256'] = $native_sha;
		}

		$temp_path = $this->create_temporary_output_path( $output_path );
		if ( false === $temp_path ) { return new WP_Error( 'zip_temp_failed', __( 'Could not create a temporary path for the zip archive.', 'bricks-ie' ) ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_open_failed', __( 'Could not create the zip archive.', 'bricks-ie' ) );
		}

		if ( $native_included ) {
			if ( true !== $zip->addFromString( 'bricks/package.zip', $native_package ) || true !== $zip->addFromString( 'bricks/package.sha256', $native_sha . "  package.zip\n" ) ) {
				$zip->close(); $this->remove_temporary_output( $temp_path ); return new WP_Error( 'bricks_ie_zip_member_failed', __( 'Could not add the native package to the archive.', 'bricks-ie' ) );
			}
		}

		if ( ! empty( $posts ) ) {
			$posts_index = array();
			$used_files  = array();

			foreach ( $posts as $item ) {
				$filename               = $this->build_v2_post_filename( $item, $used_files );
				$used_files[ $filename ] = true;

				$posts_index[] = array(
					'slug' => $item['slug'],
					'type' => $item['type'],
					'file' => $filename,
				);

				$encoded = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
				if ( false === $encoded ) {
					$zip->close();
					$this->remove_temporary_output( $temp_path );
					return new WP_Error(
						'bricks_ie_post_encode_failed',
						sprintf(
							/* translators: %d: source post ID. */
							__( 'The post payload for source post %d could not be encoded as JSON.', 'bricks-ie' ),
							(int) $item['id']
						)
					);
				}

				if ( true !== $zip->addFromString( 'katsarov/posts/' . $filename, $encoded ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return new WP_Error( 'bricks_ie_zip_member_failed', __( 'Could not add a post to the archive.', 'bricks-ie' ) ); }
			}

			$error = $this->zip_add_json( $zip, 'katsarov/posts/index.json', $posts_index );
			if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }
		}

		$export_warnings = array(
			'schema_version' => self::SCHEMA_VERSION_2,
			'generated_at'   => gmdate( 'c' ),
			'warnings'       => array_values( $warnings ),
			'omissions'      => array_values( $omissions ),
		);
		$error = $this->zip_add_json( $zip, 'katsarov/export-warnings.json', $export_warnings );
		if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }
		$error = $this->zip_add_json( $zip, 'manifest.json', $manifest );
		if ( is_wp_error( $error ) ) { $zip->close(); $this->remove_temporary_output( $temp_path ); return $error; }
		if ( true !== $zip->close() ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_close_failed', __( 'The zip archive could not be finalized.', 'bricks-ie' ) );
		}
		$size = filesize( $temp_path );
		if ( false === $size ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_filesize_failed', __( 'The completed temporary zip archive size could not be determined.', 'bricks-ie' ) );
		}

		// Reopen and validate the finished temporary archive before publication.
		$validator = $this->get_validator();
		if ( null === $validator ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error(
				'bricks_ie_validator_unavailable',
				__( 'The archive validator is not available; the generated export could not be verified and was deleted.', 'bricks-ie' )
			);
		}

		$validation = $validator->validate( $temp_path );
		if ( is_wp_error( $validation ) ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error(
				'bricks_ie_export_validation_failed',
				sprintf(
					/* translators: %s: validation failure message. */
					__( 'The generated export archive failed validation and was deleted: %s', 'bricks-ie' ),
					$validation->get_error_message()
				),
				array( 'validation_code' => $validation->get_error_code() )
			);
		}
		if ( ! $this->publish_temporary_output( $temp_path, $output_path ) ) {
			$this->remove_temporary_output( $temp_path );
			return new WP_Error( 'zip_publish_failed', __( 'The validated zip archive could not be published.', 'bricks-ie' ) );
		}

		return array(
			'file'           => $output_path,
			'options_count'  => 0,
			'posts_count'    => count( $posts ),
			'size'           => $size,
			'schema_version' => self::SCHEMA_VERSION_2,
			'domains'        => $domains,
			'native'         => array(
				'types'  => $native_types,
				'items'  => $native_items,
				'sha256' => $native_included ? $native_sha : null,
			),
			'warnings'       => array_values( $warnings ),
			'omissions'      => array_values( $omissions ),
			'validated'      => true,
		);
	}

	/**
	 * Collect Katsarov-owned posts with JSON-safe meta for schema version 2.
	 *
	 * `bricks_template` posts are excluded unconditionally: templates belong to
	 * the native Bricks package in schema version 2. Meta values are stored as
	 * native JSON values; objects, resources, and non-UTF-8 strings are
	 * rejected per meta key and recorded as warnings. No PHP serialization is
	 * used.
	 *
	 * @since 1.1.0
	 *
	 * @return array { posts: array[], warnings: string[] }
	 */
	private function collect_posts_v2() {
		$warnings   = array();
		$post_types = array_values( array_diff( $this->get_post_types(), self::NATIVE_OWNED_POST_TYPES ) );

		if ( empty( $post_types ) ) {
			return array( 'posts' => array(), 'warnings' => $warnings );
		}

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$out       = array();
		$meta_keys = $this->get_meta_keys();

		foreach ( $posts as $p ) {
			$meta = array();

			foreach ( $meta_keys as $k ) {
				$v = get_post_meta( $p->ID, $k, true );
				if ( '' === $v ) {
					continue;
				}

				if ( ! $this->is_json_safe( $v ) ) {
					$warnings[] = sprintf(
						/* translators: 1: meta key, 2: source post ID. */
						__( 'Post meta "%1$s" on source post %2$d contains objects, resources, or non-UTF-8 data and was excluded from the export.', 'bricks-ie' ),
						$k,
						(int) $p->ID
					);
					continue;
				}

				$meta[ $k ] = $v;
			}

			if ( ! empty( $meta ) ) {
				$out[] = array(
					'id'     => (int) $p->ID,
					'slug'   => (string) $p->post_name,
					'type'   => (string) $p->post_type,
					'status' => (string) $p->post_status,
					'title'  => (string) $p->post_title,
					'meta'   => $meta,
				);
			}
		}

		return array( 'posts' => $out, 'warnings' => $warnings );
	}

	/**
	 * Whether a meta value can be represented as JSON without loss.
	 *
	 * Scalars, null, and arrays of JSON-safe values are accepted. Objects and
	 * resources are rejected; strings must be valid UTF-8.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Meta value.
	 * @param int   $depth Current recursion depth.
	 * @return bool
	 */
	private function is_json_safe( $value, $depth = 0 ) {
		if ( $depth > 100 ) {
			return false;
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}

		if ( is_string( $value ) ) {
			return 1 === preg_match( '//u', $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && 1 !== preg_match( '//u', $key ) ) {
					return false;
				}
				if ( ! $this->is_json_safe( $item, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}

		// Objects and resources are never JSON-safe in schema version 2.
		return false;
	}

	/**
	 * Build a deterministic, layout-safe post file name for schema version 2.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Post payload item.
	 * @param array $used Map of already-used file names.
	 * @return string
	 */
	private function build_v2_post_filename( array $item, array $used ) {
		return $this->build_post_filename( $item, $used );
	}

	/**
	 * Build a collision-proof post filename for either archive schema.
	 *
	 * The first occurrence retains the historical name. Later occurrences use
	 * the source ID, then a deterministic numeric disambiguator if necessary.
	 *
	 * @param array $item Post payload item.
	 * @param array $used Already-used filenames.
	 * @return string
	 */
	private function build_post_filename( array $item, array $used ) {
		$type = isset( $item['type'] ) ? $item['type'] : 'post';
		$slug = isset( $item['slug'] ) ? $item['slug'] : '';
		$id   = isset( $item['id'] ) ? (int) $item['id'] : 0;
		$base = preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_file_name( $type . '__' . $slug ) );

		if ( '' === $base ) {
			$base = preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_file_name( $type . '__id-' . $id ) );
		}

		$filename = $base . '.json';
		if ( isset( $used[ $filename ] ) ) {
			$suffix = '__id-' . $id;
			$filename = $base . $suffix . '.json';
			$counter = 2;
			while ( isset( $used[ $filename ] ) ) {
				$filename = $base . $suffix . '-' . $counter . '.json';
				$counter++;
			}
		}

		return $filename;
	}

	/**
	 * Build the explicit native selection from a list result.
	 *
	 * Accepts both native list shapes: the audited descriptor list
	 * (`types` = list of `{ id, label, items, ... }` objects, as returned by
	 * Bricks 2.4) and a plain `type => items` map. Unknown transfer types are
	 * ignored. Sensitive settings tabs are removed unless explicitly
	 * authorized. Types whose item list is empty after filtering are dropped:
	 * an empty selection never means "all items".
	 *
	 * @since 1.1.0
	 *
	 * @param array $types_map       List result `types` value.
	 * @param bool  $allow_sensitive Whether sensitive settings are authorized.
	 * @return array { selection: array{types: string[], items: array}, sensitive_excluded: bool }
	 */
	private function build_native_selection( $types_map, $allow_sensitive ) {
		$selection_types    = array();
		$selection_items    = array();
		$sensitive_excluded = false;

		if ( is_array( $types_map ) ) {
			foreach ( $types_map as $key => $value ) {
				$type  = '';
				$items = null;

				if ( is_array( $value ) && isset( $value['id'] ) && is_string( $value['id'] ) ) {
					// Native descriptor shape: { id, label, items, ... }.
					$type  = $value['id'];
					$items = isset( $value['items'] ) ? $value['items'] : array();
				} elseif ( is_string( $key ) ) {
					// Keyed map shape: type => items.
					$type  = $key;
					$items = $value;
				}

				if ( '' === $type || ! in_array( $type, Bricks_IE_Bricks_Transfer_Adapter::KNOWN_TYPE_IDS, true ) ) {
					continue;
				}

				$ids = $this->extract_item_ids( $items );

				if ( 'settings' === $type && ! $allow_sensitive ) {
					$filtered = array_values( array_diff( $ids, Bricks_IE_Bricks_Transfer_Adapter::SENSITIVE_SETTINGS_IDS ) );
					if ( count( $filtered ) !== count( $ids ) ) {
						$sensitive_excluded = true;
					}
					$ids = $filtered;
				}

				if ( empty( $ids ) ) {
					continue;
				}

				$selection_types[]          = $type;
				$selection_items[ $type ]   = $ids;
			}
		}

		return array(
			'selection'          => array(
				'types' => $selection_types,
				'items' => $selection_items,
			),
			'sensitive_excluded' => $sensitive_excluded,
		);
	}

	/**
	 * Extract item IDs from a native list-result item collection.
	 *
	 * Accepts items shaped as arrays with an `id` key or as scalar IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $items Item collection for one transfer type.
	 * @return string[]
	 */
	private function extract_item_ids( $items ) {
		$ids = array();

		if ( ! is_array( $items ) ) {
			return $ids;
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['id'] ) && ( is_string( $item['id'] ) || is_int( $item['id'] ) ) && '' !== (string) $item['id'] ) {
					$ids[] = (string) $item['id'];
				}
			} elseif ( is_string( $item ) || is_int( $item ) ) {
				if ( '' !== (string) $item ) {
					$ids[] = (string) $item;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Normalize a native list's two supported type shapes to type => item IDs.
	 */
	private function native_list_inventory( $types ) {
		$inventory = array();
		if ( ! is_array( $types ) ) {
			return $inventory;
		}
		foreach ( $types as $key => $entry ) {
			$type = is_array( $entry ) && isset( $entry['id'] ) ? (string) $entry['id'] : ( is_string( $key ) ? $key : '' );
			if ( '' === $type ) {
				continue;
			}
			$items = is_array( $entry ) && array_key_exists( 'items', $entry ) ? $entry['items'] : ( is_array( $entry ) && ! isset( $entry['id'] ) ? $entry : array() );
			$ids = $this->extract_item_ids( $items );
			// Some native manifests use an item map (`id` => descriptor) rather
			// than a descriptor list. Preserve those keys as the item IDs.
			if ( is_array( $items ) ) {
				foreach ( $items as $item_key => $item ) {
					if ( is_string( $item_key ) && '' !== $item_key && ! in_array( $item_key, $ids, true ) ) {
						$ids[] = $item_key;
					}
				}
			}
			$inventory[ $type ] = array_values( array_unique( $ids ) );
		}
		return $inventory;
	}

	/**
	 * Return counts and IDs from an inspected native manifest. The manifest is
	 * authoritative: dependencies may add types and items to the package.
	 */
	private function native_manifest_inventory( array $manifest ) {
		$types = isset( $manifest['types'] ) && is_array( $manifest['types'] ) ? $manifest['types'] : array();
		$inventory = $this->native_list_inventory( $types );
		$items = array();
		$count = 0;
		foreach ( $inventory as $type => $ids ) {
			$items[ $type ] = array_fill_keys( $ids, true );
			$count += count( $ids );
		}
		return array( 'types' => count( $inventory ), 'items_count' => $count, 'items' => $items );
	}

	/**
	 * Decode and integrity-check the opaque native package.
	 *
	 * @since 1.1.0
	 *
	 * @param array $export Normalized adapter export result.
	 * @return string|WP_Error Decoded package bytes.
	 */
	private function decode_native_package( array $export ) {
		$encoded = isset( $export['zip_base64'] ) ? (string) $export['zip_base64'] : '';
		if ( '' === $encoded ) {
			return new WP_Error(
				'bricks_ie_native_package_empty',
				__( 'The Bricks native transfer did not return a package.', 'bricks-ie' )
			);
		}

		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error(
				'bricks_ie_native_package_invalid',
				__( 'The Bricks native package could not be decoded.', 'bricks-ie' )
			);
		}

		if ( strlen( $bytes ) < 4 || "PK\x03\x04" !== substr( $bytes, 0, 4 ) ) {
			return new WP_Error(
				'bricks_ie_native_package_invalid',
				__( 'The Bricks native package is not a zip archive.', 'bricks-ie' )
			);
		}

		$declared_hash = isset( $export['zip_hash'] ) ? strtolower( (string) $export['zip_hash'] ) : '';
		if ( '' !== $declared_hash && ! hash_equals( $declared_hash, hash( 'sha256', $bytes ) ) ) {
			return new WP_Error(
				'bricks_ie_native_package_hash_mismatch',
				__( 'The Bricks native package does not match the hash reported by the native transfer.', 'bricks-ie' )
			);
		}

		return $bytes;
	}

	/**
	 * Fixed domains omitted from every schema version 2 export.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Omission records with `id` and `message`.
	 */
	private function get_v2_omissions() {
		return array(
			array(
				'id'      => 'media_files',
				'message' => __( 'General media files are not transported. Exported pages keep their media references, which must resolve on the target site.', 'bricks-ie' ),
			),
			array(
				'id'      => 'template_conditions',
				'message' => __( 'Template conditions are excluded from schema version 2 until a typed sidecar schema with safe target resolution is implemented.', 'bricks-ie' ),
			),
			array(
				'id'      => 'style_manager',
				'message' => __( 'The Style Manager state (bricks_style_manager) is not covered by the Bricks native transfer and is excluded from schema version 2.', 'bricks-ie' ),
			),
			array(
				'id'      => 'pseudo_classes',
				'message' => __( 'Global pseudo classes (bricks_global_pseudo_classes) are not covered by the Bricks native transfer and are excluded from schema version 2.', 'bricks-ie' ),
			),
			array(
				'id'      => 'ui_workflow_state',
				'message' => __( 'Builder UI and workflow state (element manager, font favorites, locked or trashed classes) is excluded from schema version 2.', 'bricks-ie' ),
			),
		);
	}

	// ==================================================================
	// Native transfer collaborators
	// ==================================================================

	/**
	 * Detect the installed Bricks native transfer capabilities (cached).
	 *
	 * @since 1.1.0
	 *
	 * @return array Capability report; `available` is false when the adapter
	 *               itself cannot be constructed.
	 */
	private function detect_native_transfer() {
		if ( null !== $this->capabilities_report ) {
			return $this->capabilities_report;
		}

		$adapter = $this->get_adapter();
		if ( null === $adapter ) {
			$this->capabilities_report = array(
				'available'      => false,
				'bricks_version' => '',
				'errors'         => array( 'adapter_unavailable' ),
			);
			return $this->capabilities_report;
		}

		$this->capabilities_report = $adapter->detect_capabilities();

		return $this->capabilities_report;
	}

	/**
	 * Whether the native transfer contract is verified for schema version 2.
	 *
	 * @since 1.1.0
	 *
	 * @param array $report Capability report.
	 * @return bool
	 */
	private function native_transfer_usable( $report ) {
		return is_array( $report )
			&& ! empty( $report['available'] )
			&& isset( $report['bricks_version'] )
			&& is_string( $report['bricks_version'] )
			&& '' !== $report['bricks_version'];
	}

	/**
	 * Standard error for a schema version 2 export without a native contract.
	 *
	 * @since 1.1.0
	 *
	 * @param array $errors Machine-readable availability problems.
	 * @return WP_Error
	 */
	private function native_unavailable_error( array $errors ) {
		return new WP_Error(
			'bricks_ie_native_transfer_unavailable',
			sprintf(
				/* translators: %s: machine-readable availability problems. */
				__( 'Schema version 2 requires the Bricks 2.4 native unified transfer, which is unavailable (%s).', 'bricks-ie' ),
				! empty( $errors ) ? implode( ', ', array_map( 'strval', $errors ) ) : __( 'unknown reason', 'bricks-ie' )
			),
			array( 'errors' => array_values( $errors ) )
		);
	}

	/**
	 * Get the transfer adapter, constructing the default when needed.
	 *
	 * @since 1.1.0
	 *
	 * @return Bricks_IE_Bricks_Transfer_Adapter|null
	 */
	private function get_adapter() {
		if ( null !== $this->adapter ) {
			return $this->adapter;
		}

		if ( ! class_exists( 'Bricks_IE_Bricks_Transfer_Adapter' ) ) {
			$file = dirname( __FILE__ ) . '/class-bricks-transfer-adapter.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'Bricks_IE_Bricks_Transfer_Adapter' ) ) {
			return null;
		}

		$this->adapter = new Bricks_IE_Bricks_Transfer_Adapter();

		return $this->adapter;
	}

	/**
	 * Get the archive validator, constructing the default when needed.
	 *
	 * The self-validation limit for the outer archive is not narrowed by the
	 * upload size: the archive is generated locally, not uploaded.
	 *
	 * @since 1.1.0
	 *
	 * @return Bricks_IE_Archive_Validator|null
	 */
	private function get_validator() {
		if ( null !== $this->validator ) {
			return $this->validator;
		}

		if ( ! class_exists( 'Bricks_IE_Archive_Validator' ) ) {
			$file = dirname( __FILE__ ) . '/class-archive-validator.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'Bricks_IE_Archive_Validator' ) ) {
			return null;
		}

		$this->validator = new Bricks_IE_Archive_Validator( array(
			'max_compressed_size' => Bricks_IE_Archive_Validator::DEFAULT_MAX_COMPRESSED_SIZE,
		) );

		return $this->validator;
	}

	/**
	 * Get the WordPress version for the schema version 2 manifest.
	 *
	 * @since 1.1.0
	 *
	 * @return string|null
	 */
	private function get_wordpress_version() {
		if ( function_exists( 'get_bloginfo' ) ) {
			$version = get_bloginfo( 'version' );
			if ( is_string( $version ) && '' !== $version ) {
				return $version;
			}
		}

		return null;
	}
}
