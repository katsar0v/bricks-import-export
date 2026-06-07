<?php
/**
 * Bricks Builder importer.
 *
 * Restores a previously exported Bricks zip archive. Supports both admin uploads
 * and direct file path imports (WP-CLI).
 *
 * @package BricksIE
 */

class Bricks_IE_Importer {

	/**
	 * Maps source post IDs to target post IDs for posts created during import.
	 *
	 * @var array
	 */
	private $id_map = array();

	/**
	 * Target post IDs restored during this import.
	 *
	 * @var array
	 */
	private $imported_post_ids = array();

	/**
	 * Source site URL recorded in the export manifest.
	 *
	 * @var string
	 */
	private $source_site_url = '';

	/**
	 * Get the list of meta keys that the importer handles.
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
	 * Get the list of option names to import.
	 *
	 * @return array
	 */
	private function get_option_names() {
		return bricks_ie_get_option_names();
	}

	/**
	 * Get post types that may be created when missing during import.
	 *
	 * @return array
	 */
	private function get_create_missing_post_types() {
		return bricks_ie_get_create_missing_post_types();
	}

	/**
	 * Get post types whose core post fields may be updated during import.
	 *
	 * @return array
	 */
	private function get_update_post_fields_post_types() {
		return bricks_ie_get_update_post_fields_post_types();
	}

	/**
	 * Get the current Bricks parent theme version.
	 *
	 * @return string|null
	 */
	private function get_current_bricks_version() {
		$theme = wp_get_theme( 'bricks' );
		return $theme->exists() ? $theme->get( 'Version' ) : null;
	}

	/**
	 * Import from a zip file path.
	 *
	 * This is the core import logic used by both admin upload and WP-CLI.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @return array|WP_Error On success returns array with keys 'posts_imported', 'options_imported', 'id_remaps'. On failure a WP_Error.
	 */
	public function import_from_zip( $zip_path ) {
		$this->id_map            = array();
		$this->imported_post_ids = array();
		$this->source_site_url   = '';

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'ZipArchive is not available on this server.', 'bricks-ie' ) );
		}

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Zip file not found.', 'bricks-ie' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open the zip archive.', 'bricks-ie' ) );
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		if ( false === $manifest_raw ) {
			$zip->close();
			return new WP_Error( 'missing_manifest', __( 'Archive is missing manifest.json.', 'bricks-ie' ) );
		}

		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['version'] ) ) {
			$zip->close();
			return new WP_Error( 'invalid_manifest', __( 'Invalid manifest.json in archive.', 'bricks-ie' ) );
		}

		$this->source_site_url = ! empty( $manifest['site_url'] ) ? esc_url_raw( (string) $manifest['site_url'] ) : '';

		$source_bricks = isset( $manifest['bricks_version'] ) ? $manifest['bricks_version'] : null;
		$target_bricks = $this->get_current_bricks_version();

		if ( null === $source_bricks ) {
			$zip->close();
			return new WP_Error( 'no_bricks_version', __( 'Archive does not contain a Bricks version. Please re-export from a site running this version of the export tool.', 'bricks-ie' ) );
		}

		if ( $source_bricks !== $target_bricks ) {
			$zip->close();
			return new WP_Error(
				'bricks_version_mismatch',
				sprintf(
					/* translators: 1: source Bricks version, 2: target Bricks version */
					__( 'Bricks version mismatch: archive was exported with Bricks %1$s, but this site runs Bricks %2$s.', 'bricks-ie' ),
					$source_bricks,
					$target_bricks
				)
			);
		}

		// 1. Import posts first (builds id_map).
		$result = $this->import_posts( $zip );
		if ( is_wp_error( $result ) ) {
			$zip->close();
			return $result;
		}
		$posts_imported = $result;

		// Bricks option update hooks may write generated CSS immediately.
		$this->ensure_bricks_css_dir();

		// 2. Import options.
		$result = $this->import_options( $zip );
		if ( is_wp_error( $result ) ) {
			$zip->close();
			return $result;
		}
		$options_imported = $result;

		$zip->close();

		// 3. Remap post IDs in all restored data.
		$this->remap_post_ids();

		// 4. Normalize migrated URLs and cached media data.
		$this->normalize_imported_media();

		// 5. Regenerate Bricks CSS files affected by restored global data.
		$this->regenerate_bricks_assets();

		// 6. Regenerate Bricks code signatures for code/SVG/query-editor elements.
		$this->regenerate_code_signatures();

		// 7. Flush cache.
		$this->flush_cache();

		return array(
			'posts_imported'   => $posts_imported,
			'options_imported' => $options_imported,
			'id_remaps'        => count( $this->id_map ),
		);
	}

	/**
	 * Handle the admin import request.
	 *
	 * Validates the uploaded file, runs the import, and redirects back with a status message.
	 */
	public function upload() {
		$redirect_url = add_query_arg( 'page', 'bricks-import-export', admin_url( 'admin.php' ) );

		if ( empty( $_FILES['bricks_ie_import_file'] ) || empty( $_FILES['bricks_ie_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'bricks_ie_import' => 'error', 'msg' => rawurlencode( __( 'No file was uploaded.', 'bricks-ie' ) ) ), $redirect_url ) );
			exit;
		}

		$file = $_FILES['bricks_ie_import_file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_safe_redirect( add_query_arg( array( 'bricks_ie_import' => 'error', 'msg' => rawurlencode( __( 'File upload failed.', 'bricks-ie' ) ) ), $redirect_url ) );
			exit;
		}

		$ext = pathinfo( $file['name'], PATHINFO_EXTENSION );
		if ( 'zip' !== strtolower( $ext ) ) {
			wp_safe_redirect( add_query_arg( array( 'bricks_ie_import' => 'error', 'msg' => rawurlencode( __( 'Uploaded file must be a .zip archive.', 'bricks-ie' ) ) ), $redirect_url ) );
			exit;
		}

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$result = $this->import_from_zip( $file['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'bricks_ie_import' => 'error', 'msg' => rawurlencode( $result->get_error_message() ) ), $redirect_url ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'bricks_ie_import', 'ok', $redirect_url ) );
		exit;
	}

	/**
	 * Restore Bricks options from the archive.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return int|WP_Error Number of options imported on success, WP_Error on failure.
	 */
	private function import_options( $zip ) {
		$count = 0;

		foreach ( $this->get_option_names() as $name ) {
			$content = $zip->getFromName( 'options/' . $name . '.json' );
			if ( false === $content ) {
				continue;
			}

			$value = json_decode( $content, true );
			if ( null === $value && json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'invalid_json', sprintf( __( 'Invalid JSON in options/%s.json: %s', 'bricks-ie' ), $name, json_last_error_msg() ) );
			}

			update_option( $name, $value, false );
			$count++;
		}

		return $count;
	}

	/**
	 * Upsert posts from the archive and build the source→target ID map.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return int|WP_Error Number of posts imported on success, WP_Error on failure.
	 */
	private function import_posts( $zip ) {
		$index_raw = $zip->getFromName( 'posts/index.json' );
		if ( false === $index_raw ) {
			return 0;
		}

		$index = json_decode( $index_raw, true );
		if ( ! is_array( $index ) ) {
			return new WP_Error( 'invalid_index', __( 'Invalid posts/index.json in archive.', 'bricks-ie' ) );
		}

		$count = 0;

		foreach ( $index as $entry ) {
			if ( empty( $entry['file'] ) ) {
				continue;
			}

			$post_raw = $zip->getFromName( 'posts/' . $entry['file'] );
			if ( false === $post_raw ) {
				return new WP_Error( 'missing_post', sprintf( __( 'Missing post file: %s', 'bricks-ie' ), $entry['file'] ) );
			}

			$post_data = json_decode( $post_raw, true );
			if ( ! is_array( $post_data ) ) {
				return new WP_Error( 'invalid_post', sprintf( __( 'Invalid JSON in posts/%s', 'bricks-ie' ), $entry['file'] ) );
			}

			$type      = $post_data['type'] ?? $entry['type'] ?? 'page';
			$slug      = $post_data['slug'] ?? $entry['slug'] ?? '';
			$source_id = isset( $post_data['id'] ) ? (int) $post_data['id'] : 0;

			if ( ! post_type_exists( $type ) ) {
				continue;
			}

			$existing = get_posts( array(
				'name'        => $slug,
				'post_type'   => $type,
				'post_status' => 'any',
				'numberposts' => 1,
			) );

			if ( $existing ) {
				$post_id = $existing[0]->ID;

				if ( in_array( $type, $this->get_update_post_fields_post_types(), true ) ) {
					wp_update_post( array(
						'ID'          => $post_id,
						'post_title'  => $post_data['title'] ?? $existing[0]->post_title,
						'post_status' => $post_data['status'] ?? $existing[0]->post_status,
					) );
				}
			} else {
				if ( ! in_array( $type, $this->get_create_missing_post_types(), true ) ) {
					continue;
				}

				$post_id = wp_insert_post( array(
					'post_name'   => $slug,
					'post_type'   => $type,
					'post_status' => $post_data['status'] ?? 'publish',
					'post_title'  => $post_data['title'] ?? '',
				) );
				if ( is_wp_error( $post_id ) ) {
					return new WP_Error( 'insert_failed', sprintf( __( 'Failed to create %s/%s: %s', 'bricks-ie' ), $type, $slug, $post_id->get_error_message() ) );
				}
			}

			// Record source→target mapping whenever the ID changed.
			if ( $source_id && $source_id !== $post_id ) {
				$this->id_map[ $source_id ] = $post_id;
			}

			$this->imported_post_ids[] = (int) $post_id;

			$meta = $post_data['meta'] ?? array();

			foreach ( $this->get_meta_keys() as $key ) {
				delete_post_meta( $post_id, $key );
			}

			foreach ( $meta as $key => $b64 ) {
				$value = @unserialize( base64_decode( $b64 ) );
				add_post_meta( $post_id, $key, $value );
			}

			$count++;
		}

		return $count;
	}

	/**
	 * Remap source post IDs to target post IDs in all imported data.
	 */
	private function remap_post_ids() {
		if ( empty( $this->id_map ) ) {
			return;
		}

		// Remap in post meta for every target post.
		foreach ( $this->id_map as $target_id ) {
			foreach ( $this->get_meta_keys() as $key ) {
				$value = get_post_meta( $target_id, $key, true );
				if ( '' === $value ) {
					continue;
				}

				$new_value = $this->recursive_replace_ids( $value, $this->id_map );
				if ( $new_value !== $value ) {
					delete_post_meta( $target_id, $key );
					add_post_meta( $target_id, $key, $new_value );
				}
			}
		}

		// Remap in options.
		foreach ( $this->get_option_names() as $name ) {
			$value = get_option( $name );
			if ( false === $value ) {
				continue;
			}

			$new_value = $this->recursive_replace_ids( $value, $this->id_map );
			if ( $new_value !== $value ) {
				update_option( $name, $new_value, false );
			}
		}
	}

	/**
	 * Recursively replace source post IDs with target post IDs in a data structure.
	 *
	 * @param mixed $data   The data to process.
	 * @param array $id_map Source ID → target ID map.
	 * @return mixed
	 */
	private function recursive_replace_ids( $data, $id_map ) {
		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$result[ $key ] = $this->recursive_replace_ids( $value, $id_map );
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			$result = clone $data;
			foreach ( get_object_vars( $result ) as $key => $value ) {
				$result->$key = $this->recursive_replace_ids( $value, $id_map );
			}
			return $result;
		}

		if ( is_int( $data ) && isset( $id_map[ $data ] ) ) {
			return $id_map[ $data ];
		}

		if ( is_string( $data ) && is_numeric( $data ) && isset( $id_map[ (int) $data ] ) ) {
			return $id_map[ (int) $data ];
		}

		return $data;
	}

	/**
	 * Normalize source URLs and cached Bricks media objects in imported data.
	 */
	private function normalize_imported_media() {
		$post_ids = array_values( array_unique( array_map( 'intval', $this->imported_post_ids ) ) );

		foreach ( $post_ids as $post_id ) {
			foreach ( $this->get_meta_keys() as $key ) {
				$value = get_post_meta( $post_id, $key, true );
				if ( '' === $value ) {
					continue;
				}

				$new_value = $this->recursive_normalize_imported_media( $value );
				if ( $new_value !== $value ) {
					update_post_meta( $post_id, $key, $new_value );
				}
			}
		}

		foreach ( $this->get_option_names() as $name ) {
			$value = get_option( $name );
			if ( false === $value ) {
				continue;
			}

			$new_value = $this->recursive_normalize_imported_media( $value );
			if ( $new_value !== $value ) {
				update_option( $name, $new_value, false );
			}
		}
	}

	/**
	 * Recursively normalize URLs and media arrays in imported data.
	 *
	 * @param mixed $data The data to process.
	 * @return mixed
	 */
	private function recursive_normalize_imported_media( $data ) {
		if ( is_array( $data ) ) {
			$data   = $this->normalize_attachment_media_array( $data );
			$result = array();

			foreach ( $data as $key => $value ) {
				$result[ $key ] = $this->recursive_normalize_imported_media( $value );
			}

			return $result;
		}

		if ( is_object( $data ) ) {
			$result = clone $data;

			foreach ( get_object_vars( $result ) as $key => $value ) {
				$result->$key = $this->recursive_normalize_imported_media( $value );
			}

			return $result;
		}

		if ( is_string( $data ) ) {
			return $this->replace_source_site_url( $data );
		}

		return $data;
	}

	/**
	 * Refresh a Bricks-style media array from the local attachment record.
	 *
	 * @param array $data Media array candidate.
	 * @return array
	 */
	private function normalize_attachment_media_array( $data ) {
		if ( empty( $data['id'] ) || ! is_numeric( $data['id'] ) ) {
			return $data;
		}

		if ( ! isset( $data['url'] ) && ! isset( $data['full'] ) && ! isset( $data['filename'] ) ) {
			return $data;
		}

		$attachment_id = $this->resolve_attachment_id_from_media_array( $data );
		if ( ! $attachment_id ) {
			return $data;
		}

		$data['id'] = $attachment_id;

		$full_url = wp_get_attachment_url( $attachment_id );
		if ( wp_attachment_is_image( $attachment_id ) ) {
			$full_image_url = wp_get_attachment_image_url( $attachment_id, 'full' );
			$full_url       = $full_image_url ? $full_image_url : $full_url;
		}

		if ( $full_url ) {
			$full_url_path    = wp_parse_url( $full_url, PHP_URL_PATH );
			$data['full']     = $full_url;
			$data['filename'] = basename( $full_url_path ? $full_url_path : $full_url );
		}

		$size = ! empty( $data['size'] ) && is_scalar( $data['size'] ) ? (string) $data['size'] : 'full';
		$url  = false;

		if ( wp_attachment_is_image( $attachment_id ) ) {
			$url = wp_get_attachment_image_url( $attachment_id, $size );
		}

		if ( ! $url ) {
			$url = $full_url;
		}

		if ( $url ) {
			$data['url'] = $url;
		}

		return $data;
	}

	/**
	 * Resolve a media array to a local attachment ID.
	 *
	 * @param array $data Media array candidate.
	 * @return int
	 */
	private function resolve_attachment_id_from_media_array( $data ) {
		$attachment_id = (int) $data['id'];

		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			return $attachment_id;
		}

		foreach ( array( 'full', 'url' ) as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}

			$url = $this->replace_source_site_url( $data[ $key ] );
			$id  = attachment_url_to_postid( $url );

			if ( $id ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Replace exported source-site URLs with the current site URL.
	 *
	 * @param string $value String to process.
	 * @return string
	 */
	private function replace_source_site_url( $value ) {
		if ( '' === $this->source_site_url ) {
			return $value;
		}

		$source = untrailingslashit( $this->source_site_url );
		$target = untrailingslashit( home_url() );

		if ( '' === $source || $source === $target ) {
			return $value;
		}

		$search = array_unique( array_filter( array(
			$source,
			set_url_scheme( $source, 'http' ),
			set_url_scheme( $source, 'https' ),
		) ) );

		return str_replace( $search, array_fill( 0, count( $search ), $target ), $value );
	}

	/**
	 * Regenerate Bricks CSS files affected by imported global options.
	 */
	private function regenerate_bricks_assets() {
		if ( ! $this->ensure_bricks_css_dir() ) {
			return;
		}

		if ( class_exists( '\Bricks\Assets_Color_Palettes' ) ) {
			\Bricks\Assets_Color_Palettes::generate_css_file( get_option( 'bricks_color_palette', array() ) );
		}

		if ( class_exists( '\Bricks\Assets_Global_Variables' ) ) {
			\Bricks\Assets_Global_Variables::generate_css_file( get_option( 'bricks_global_variables', array() ) );
		}

		if ( class_exists( '\Bricks\Assets_Theme_Styles' ) ) {
			\Bricks\Assets_Theme_Styles::generate_css_file( get_option( 'bricks_theme_styles', array() ) );
		}

		if ( class_exists( '\Bricks\Assets_Global_Custom_Css' ) ) {
			\Bricks\Assets_Global_Custom_Css::generate_css_file( get_option( 'bricks_global_settings', array() ) );
		}

		if ( class_exists( '\Bricks\Assets_Global_Elements' ) ) {
			\Bricks\Assets_Global_Elements::generate_css_file( get_option( 'bricks_global_elements', array() ) );
		}

		if ( class_exists( '\Bricks\Ajax' ) && method_exists( '\Bricks\Ajax', 'generate_style_manager_css_file' ) ) {
			\Bricks\Ajax::generate_style_manager_css_file();
		}
	}

	/**
	 * Ensure Bricks' generated CSS directory exists before calling file writers.
	 *
	 * @return bool
	 */
	private function ensure_bricks_css_dir() {
		if ( ! class_exists( '\Bricks\Assets' ) || empty( \Bricks\Assets::$css_dir ) ) {
			return false;
		}

		if ( ! is_dir( \Bricks\Assets::$css_dir ) ) {
			wp_mkdir_p( \Bricks\Assets::$css_dir );
		}

		return is_dir( \Bricks\Assets::$css_dir );
	}

	/**
	 * Regenerate Bricks code signatures for code, SVG, and query-editor elements.
	 *
	 * After import, code execution elements lose their signatures (security
	 * measure). This method calls Bricks' own regeneration logic to re-sign
	 * all code instances so they execute without manual re-approval.
	 *
	 * During WP-CLI there is no authenticated user, so we temporarily switch
	 * to the first administrator so that permission checks pass and the
	 * signatures record a valid user ID.
	 */
	private function regenerate_code_signatures() {
		// Bricks\Admin lives in the Bricks namespace and is only instantiated by
		// Bricks when is_admin() is true. In WP-CLI (and other headless contexts)
		// the class is never loaded automatically, so we require the file ourselves.
		// The Bricks PSR-4 autoloader is registered but won't help here because the
		// file is only executed inside an is_admin() gate in init.php.
		if ( ! class_exists( 'Bricks\Admin' ) ) {
			$bricks_admin_file = get_template_directory() . '/includes/admin.php';
			if ( file_exists( $bricks_admin_file ) ) {
				require_once $bricks_admin_file;
			}
		}

		if ( ! class_exists( 'Bricks\Admin' ) ) {
			return;
		}

		// Preserve the current user (may be 0 in WP-CLI).
		$original_user = get_current_user_id();

		// If running headless (WP-CLI, cron, …), switch to an administrator
		// so that current_user_can() checks and get_current_user_id() in
		// Bricks process_elements_for_signature() work correctly.
		$switched = false;
		if ( ! $original_user ) {
			$admins = get_users( [ 'role' => 'Administrator', 'number' => 1, 'fields' => 'ID' ] );
			if ( ! empty( $admins ) ) {
				wp_set_current_user( $admins[0] );
				$switched = true;
			}
		}

		\Bricks\Admin::crawl_and_update_code_signatures( false );

		// Mirror what the Bricks admin AJAX handler does: record the version and
		// timestamp so that the "Zuletzt generiert" notice in Bricks Settings is
		// updated immediately after import.
		if ( defined( 'BRICKS_VERSION' ) ) {
			update_option( 'bricks_code_signatures_last_generated', BRICKS_VERSION );
		}
		update_option( 'bricks_code_signatures_last_generated_timestamp', time() );

		if ( $switched ) {
			wp_set_current_user( $original_user );
		}
	}

	/**
	 * Flush WP Rocket HTML cache and object cache.
	 */
	private function flush_cache() {
		$cache_dir = WP_CONTENT_DIR . '/cache/';
		if ( is_dir( $cache_dir ) ) {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'html' === $f->getExtension() ) {
					unlink( $f->getRealPath() );
				}
			}
		}
		wp_cache_flush();
	}
}
