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
		$state = $this->create_import_state( $zip_path );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$batch_size = max( 1, (int) $state['posts_total'] + (int) $state['options_total'] + 1 );

		while ( empty( $state['done'] ) ) {
			$result = $this->advance_import_state( $state, $batch_size );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return array(
			'posts_imported'   => (int) $state['posts_imported'],
			'options_imported' => (int) $state['options_imported'],
			'id_remaps'        => count( $this->id_map ),
		);
	}

	/**
	 * Start an AJAX-driven import session from an uploaded zip file.
	 *
	 * @return array|WP_Error
	 */
	public function start_import_session() {
		$file = $this->validate_uploaded_import_file();
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$temp_file = wp_tempnam( 'bricks-ie-import-' . sanitize_file_name( $file['name'] ) );
		if ( ! $temp_file ) {
			return new WP_Error( 'temp_file_failed', __( 'Could not create a temporary file for the import.', 'bricks-ie' ) );
		}

		if ( file_exists( $temp_file ) ) {
			@unlink( $temp_file );
		}

		$moved = is_uploaded_file( $file['tmp_name'] )
			? @move_uploaded_file( $file['tmp_name'], $temp_file )
			: @copy( $file['tmp_name'], $temp_file );

		if ( ! $moved ) {
			return new WP_Error( 'temp_file_move_failed', __( 'Could not store the uploaded import file.', 'bricks-ie' ) );
		}

		$state = $this->create_import_state( $temp_file );
		if ( is_wp_error( $state ) ) {
			@unlink( $temp_file );
			return $state;
		}

		$session_id             = wp_generate_uuid4();
		$state['session_id']    = $session_id;
		$state['is_temporary']  = true;
		$state['created_at']    = time();
		$state['last_activity'] = time();

		set_transient( $this->get_import_session_key( $session_id ), $state, HOUR_IN_SECONDS );

		return $this->format_import_response(
			$state,
			__( 'Archive validated. Starting import...', 'bricks-ie' )
		);
	}

	/**
	 * Run the next unit of an AJAX import session.
	 *
	 * @param string $session_id Import session ID.
	 * @return array|WP_Error
	 */
	public function run_import_session_step( $session_id ) {
		$session_id = sanitize_key( $session_id );
		if ( '' === $session_id ) {
			return new WP_Error( 'missing_session', __( 'Missing import session.', 'bricks-ie' ) );
		}

		$key   = $this->get_import_session_key( $session_id );
		$state = get_transient( $key );

		if ( ! is_array( $state ) ) {
			return new WP_Error( 'expired_session', __( 'Import session expired. Please start the import again.', 'bricks-ie' ) );
		}

		@set_time_limit( 0 );
		wp_raise_memory_limit( 'admin' );

		$state['last_activity'] = time();
		$result                 = $this->advance_import_state( $state );

		if ( is_wp_error( $result ) ) {
			$this->cleanup_import_state( $state );
			return $result;
		}

		if ( ! empty( $result['done'] ) ) {
			$this->cleanup_import_state( $state );
		} else {
			set_transient( $key, $state, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Validate the uploaded admin import file.
	 *
	 * @return array|WP_Error
	 */
	private function validate_uploaded_import_file() {
		if ( empty( $_FILES['bricks_ie_import_file'] ) || empty( $_FILES['bricks_ie_import_file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file_uploaded', __( 'No file was uploaded.', 'bricks-ie' ) );
		}

		$file = $_FILES['bricks_ie_import_file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_failed', __( 'File upload failed.', 'bricks-ie' ) );
		}

		$ext = pathinfo( $file['name'], PATHINFO_EXTENSION );
		if ( 'zip' !== strtolower( $ext ) ) {
			return new WP_Error( 'invalid_upload_type', __( 'Uploaded file must be a .zip archive.', 'bricks-ie' ) );
		}

		return $file;
	}

	/**
	 * Create the normalized import state after validating the archive.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @return array|WP_Error
	 */
	private function create_import_state( $zip_path ) {
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

		$post_index = $this->get_post_index_from_zip( $zip );
		if ( is_wp_error( $post_index ) ) {
			$zip->close();
			return $post_index;
		}

		$option_names = $this->get_importable_option_names_from_zip( $zip );
		$zip->close();

		$total_units = 1 + count( $post_index ) + count( $option_names ) + 5;

		return array(
			'session_id'          => '',
			'is_temporary'        => false,
			'zip_path'            => $zip_path,
			'step'                => 'posts',
			'done'                => false,
			'post_index'          => $post_index,
			'posts_total'         => count( $post_index ),
			'posts_processed'     => 0,
			'posts_imported'      => 0,
			'option_names'        => $option_names,
			'options_total'       => count( $option_names ),
			'options_processed'   => 0,
			'options_imported'    => 0,
			'id_map'              => array(),
			'imported_post_ids'   => array(),
			'source_site_url'     => $this->source_site_url,
			'assets_dir_prepared' => false,
			'completed_steps'     => array( 'validate' ),
			'total_units'         => $total_units,
		);
	}

	/**
	 * Run the next import state transition.
	 *
	 * @param array $state Import state, passed by reference.
	 * @param int|null $batch_size Optional batch size override.
	 * @return array|WP_Error
	 */
	private function advance_import_state( &$state, $batch_size = null ) {
		$batch_size = null === $batch_size ? $this->get_import_batch_size() : max( 1, (int) $batch_size );

		$this->hydrate_import_state( $state );

		switch ( $state['step'] ) {
			case 'posts':
				if ( (int) $state['posts_processed'] >= (int) $state['posts_total'] ) {
					$this->mark_import_step_completed( $state, 'posts' );
					$state['step'] = 'options';
					return $this->format_import_response( $state, __( 'Post import complete. Preparing options...', 'bricks-ie' ) );
				}

				$zip = $this->open_import_zip( $state['zip_path'] );
				if ( is_wp_error( $zip ) ) {
					return $zip;
				}

				$result = $this->import_posts_batch( $zip, $state['post_index'], (int) $state['posts_processed'], $batch_size );
				$zip->close();

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$state['posts_processed'] += (int) $result['processed'];
				$state['posts_imported']  += (int) $result['imported'];
				$this->persist_import_runtime_to_state( $state );

				if ( (int) $state['posts_processed'] >= (int) $state['posts_total'] ) {
					$this->mark_import_step_completed( $state, 'posts' );
					$state['step'] = 'options';
					return $this->format_import_response(
						$state,
						sprintf(
							/* translators: %d: number of imported posts */
							__( 'Post import complete: %d post(s) imported.', 'bricks-ie' ),
							(int) $state['posts_imported']
						)
					);
				}

				return $this->format_import_response(
					$state,
					sprintf(
						/* translators: 1: processed posts, 2: total posts */
						__( 'Processed %1$d of %2$d post(s).', 'bricks-ie' ),
						(int) $state['posts_processed'],
						(int) $state['posts_total']
					)
				);

			case 'options':
				if ( empty( $state['assets_dir_prepared'] ) ) {
					$this->ensure_bricks_css_dir();
					$state['assets_dir_prepared'] = true;
				}

				if ( (int) $state['options_processed'] >= (int) $state['options_total'] ) {
					$this->mark_import_step_completed( $state, 'options' );
					$state['step'] = 'remap';
					return $this->format_import_response( $state, __( 'Options import complete. Remapping post IDs...', 'bricks-ie' ) );
				}

				$zip = $this->open_import_zip( $state['zip_path'] );
				if ( is_wp_error( $zip ) ) {
					return $zip;
				}

				$result = $this->import_options_batch( $zip, $state['option_names'], (int) $state['options_processed'], $batch_size );
				$zip->close();

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$state['options_processed'] += (int) $result['processed'];
				$state['options_imported']  += (int) $result['imported'];
				$this->persist_import_runtime_to_state( $state );

				if ( (int) $state['options_processed'] >= (int) $state['options_total'] ) {
					$this->mark_import_step_completed( $state, 'options' );
					$state['step'] = 'remap';
					return $this->format_import_response(
						$state,
						sprintf(
							/* translators: %d: number of imported options */
							__( 'Options import complete: %d option(s) imported.', 'bricks-ie' ),
							(int) $state['options_imported']
						)
					);
				}

				return $this->format_import_response(
					$state,
					sprintf(
						/* translators: 1: processed options, 2: total options */
						__( 'Processed %1$d of %2$d option(s).', 'bricks-ie' ),
						(int) $state['options_processed'],
						(int) $state['options_total']
					)
				);

			case 'remap':
				$this->remap_post_ids();
				$this->persist_import_runtime_to_state( $state );
				$this->mark_import_step_completed( $state, 'remap' );
				$state['step'] = 'normalize';
				return $this->format_import_response( $state, __( 'Post IDs remapped. Normalizing URLs and media data...', 'bricks-ie' ) );

			case 'normalize':
				$this->normalize_imported_media();
				$this->persist_import_runtime_to_state( $state );
				$this->mark_import_step_completed( $state, 'normalize' );
				$state['step'] = 'assets';
				return $this->format_import_response( $state, __( 'URLs and media data normalized. Regenerating Bricks assets...', 'bricks-ie' ) );

			case 'assets':
				$this->regenerate_bricks_assets();
				$this->mark_import_step_completed( $state, 'assets' );
				$state['step'] = 'signatures';
				return $this->format_import_response( $state, __( 'Bricks assets regenerated. Regenerating code signatures...', 'bricks-ie' ) );

			case 'signatures':
				$this->regenerate_code_signatures();
				$this->mark_import_step_completed( $state, 'signatures' );
				$state['step'] = 'cache';
				return $this->format_import_response( $state, __( 'Code signatures regenerated. Flushing cache...', 'bricks-ie' ) );

			case 'cache':
				$this->flush_cache();
				$this->mark_import_step_completed( $state, 'cache' );
				$state['step'] = 'complete';
				$state['done'] = true;
				return $this->format_import_response( $state, __( 'Import complete.', 'bricks-ie' ), true );
		}

		return new WP_Error( 'invalid_import_step', __( 'Invalid import step. Please start the import again.', 'bricks-ie' ) );
	}

	/**
	 * Handle the admin import request.
	 *
	 * Validates the uploaded file, runs the import, and redirects back with a status message.
	 */
	public function upload() {
		$redirect_url = add_query_arg( 'page', 'bricks-import-export', admin_url( 'admin.php' ) );

		$file = $this->validate_uploaded_import_file();
		if ( is_wp_error( $file ) ) {
			wp_safe_redirect( add_query_arg( array( 'bricks_ie_import' => 'error', 'msg' => rawurlencode( $file->get_error_message() ) ), $redirect_url ) );
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
		$option_names = $this->get_importable_option_names_from_zip( $zip );
		$result       = $this->import_options_batch( $zip, $option_names, 0, count( $option_names ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result['imported'];
	}

	/**
	 * Upsert posts from the archive and build the source→target ID map.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return int|WP_Error Number of posts imported on success, WP_Error on failure.
	 */
	private function import_posts( $zip ) {
		$index = $this->get_post_index_from_zip( $zip );
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		$result = $this->import_posts_batch( $zip, $index, 0, count( $index ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result['imported'];
	}

	/**
	 * Get valid post index entries from an archive.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return array|WP_Error
	 */
	private function get_post_index_from_zip( $zip ) {
		$index_raw = $zip->getFromName( 'posts/index.json' );
		if ( false === $index_raw ) {
			return array();
		}

		$index = json_decode( $index_raw, true );
		if ( ! is_array( $index ) ) {
			return new WP_Error( 'invalid_index', __( 'Invalid posts/index.json in archive.', 'bricks-ie' ) );
		}

		$entries = array();
		foreach ( $index as $entry ) {
			if ( ! empty( $entry['file'] ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Get option names that are present in an archive.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return array
	 */
	private function get_importable_option_names_from_zip( $zip ) {
		$option_names = array();

		foreach ( $this->get_option_names() as $name ) {
			if ( false !== $zip->locateName( 'options/' . $name . '.json' ) ) {
				$option_names[] = $name;
			}
		}

		return $option_names;
	}

	/**
	 * Import a batch of post entries.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @param array      $index Post index entries.
	 * @param int        $offset Batch offset.
	 * @param int        $limit Batch limit.
	 * @return array|WP_Error
	 */
	private function import_posts_batch( $zip, $index, $offset, $limit ) {
		$processed = 0;
		$imported  = 0;
		$total     = count( $index );

		for ( $i = $offset; $i < $total && $processed < $limit; $i++ ) {
			$result = $this->import_post_entry( $zip, $index[ $i ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$processed++;
			if ( $result ) {
				$imported++;
			}
		}

		return array(
			'processed' => $processed,
			'imported'  => $imported,
		);
	}

	/**
	 * Import one post entry.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @param array      $entry Post index entry.
	 * @return bool|WP_Error True when a post was imported, false when skipped.
	 */
	private function import_post_entry( $zip, $entry ) {
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
			return false;
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
				return false;
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

		return true;
	}

	/**
	 * Import a batch of option names.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @param array      $option_names Option names.
	 * @param int        $offset Batch offset.
	 * @param int        $limit Batch limit.
	 * @return array|WP_Error
	 */
	private function import_options_batch( $zip, $option_names, $offset, $limit ) {
		$processed = 0;
		$imported  = 0;
		$total     = count( $option_names );

		for ( $i = $offset; $i < $total && $processed < $limit; $i++ ) {
			$result = $this->import_option_name( $zip, $option_names[ $i ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$processed++;
			$imported += (int) $result;
		}

		return array(
			'processed' => $processed,
			'imported'  => $imported,
		);
	}

	/**
	 * Import one option from the archive.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @param string     $name Option name.
	 * @return int|WP_Error
	 */
	private function import_option_name( $zip, $name ) {
		$content = $zip->getFromName( 'options/' . $name . '.json' );
		if ( false === $content ) {
			return 0;
		}

		$value = json_decode( $content, true );
		if ( null === $value && json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', sprintf( __( 'Invalid JSON in options/%s.json: %s', 'bricks-ie' ), $name, json_last_error_msg() ) );
		}

		update_option( $name, $value, false );

		return 1;
	}

	/**
	 * Get the AJAX import batch size.
	 *
	 * @return int
	 */
	private function get_import_batch_size() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_batch_size', 10 ) );
	}

	/**
	 * Get the transient key for an import session.
	 *
	 * @param string $session_id Import session ID.
	 * @return string
	 */
	private function get_import_session_key( $session_id ) {
		return 'bricks_ie_import_' . sanitize_key( $session_id );
	}

	/**
	 * Open an import zip archive.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @return ZipArchive|WP_Error
	 */
	private function open_import_zip( $zip_path ) {
		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Zip file not found.', 'bricks-ie' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open the zip archive.', 'bricks-ie' ) );
		}

		return $zip;
	}

	/**
	 * Hydrate runtime properties from import state.
	 *
	 * @param array $state Import state.
	 */
	private function hydrate_import_state( $state ) {
		$this->id_map = array();
		if ( ! empty( $state['id_map'] ) && is_array( $state['id_map'] ) ) {
			foreach ( $state['id_map'] as $source_id => $target_id ) {
				$this->id_map[ (int) $source_id ] = (int) $target_id;
			}
		}

		$this->imported_post_ids = array();
		if ( ! empty( $state['imported_post_ids'] ) && is_array( $state['imported_post_ids'] ) ) {
			foreach ( $state['imported_post_ids'] as $post_id ) {
				$this->imported_post_ids[] = (int) $post_id;
			}
		}

		$this->source_site_url = ! empty( $state['source_site_url'] ) ? esc_url_raw( (string) $state['source_site_url'] ) : '';
	}

	/**
	 * Persist runtime properties back into import state.
	 *
	 * @param array $state Import state, passed by reference.
	 */
	private function persist_import_runtime_to_state( &$state ) {
		$state['id_map'] = array();
		foreach ( $this->id_map as $source_id => $target_id ) {
			$state['id_map'][ (int) $source_id ] = (int) $target_id;
		}

		$state['imported_post_ids'] = array_values( array_unique( array_map( 'intval', $this->imported_post_ids ) ) );
		$state['source_site_url']   = $this->source_site_url;
	}

	/**
	 * Mark a visible import step as complete.
	 *
	 * @param array  $state Import state, passed by reference.
	 * @param string $step Step key.
	 */
	private function mark_import_step_completed( &$state, $step ) {
		if ( empty( $state['completed_steps'] ) || ! is_array( $state['completed_steps'] ) ) {
			$state['completed_steps'] = array();
		}

		if ( ! in_array( $step, $state['completed_steps'], true ) ) {
			$state['completed_steps'][] = $step;
		}
	}

	/**
	 * Format import progress response data.
	 *
	 * @param array  $state Import state.
	 * @param string $message User-facing message.
	 * @param bool   $done Whether the import is complete.
	 * @return array
	 */
	private function format_import_response( $state, $message, $done = false ) {
		$response = array(
			'session_id'      => isset( $state['session_id'] ) ? $state['session_id'] : '',
			'current_step'    => $done ? 'complete' : $state['step'],
			'steps'           => $this->get_import_step_labels(),
			'completed_steps' => array_values( array_unique( $state['completed_steps'] ) ),
			'percent'         => $this->calculate_import_percent( $state, $done ),
			'message'         => $message,
			'done'            => $done,
			'counts'          => array(
				'posts_processed'   => (int) $state['posts_processed'],
				'posts_imported'    => (int) $state['posts_imported'],
				'options_processed' => (int) $state['options_processed'],
				'options_imported'  => (int) $state['options_imported'],
				'id_remaps'         => ! empty( $state['id_map'] ) && is_array( $state['id_map'] ) ? count( $state['id_map'] ) : 0,
			),
			'totals'          => array(
				'posts'   => (int) $state['posts_total'],
				'options' => (int) $state['options_total'],
				'units'   => (int) $state['total_units'],
			),
		);

		if ( $done ) {
			$response['summary'] = sprintf(
				/* translators: 1: imported options, 2: imported posts, 3: remapped IDs */
				__( 'Imported %1$d option(s), %2$d post(s), remapped %3$d ID(s).', 'bricks-ie' ),
				(int) $state['options_imported'],
				(int) $state['posts_imported'],
				! empty( $state['id_map'] ) && is_array( $state['id_map'] ) ? count( $state['id_map'] ) : 0
			);
		}

		return $response;
	}

	/**
	 * Get visible import step labels.
	 *
	 * @return array
	 */
	private function get_import_step_labels() {
		return array(
			array( 'key' => 'validate', 'label' => __( 'Validate archive', 'bricks-ie' ) ),
			array( 'key' => 'posts', 'label' => __( 'Import posts', 'bricks-ie' ) ),
			array( 'key' => 'options', 'label' => __( 'Import options', 'bricks-ie' ) ),
			array( 'key' => 'remap', 'label' => __( 'Remap post IDs', 'bricks-ie' ) ),
			array( 'key' => 'normalize', 'label' => __( 'Normalize URLs and media', 'bricks-ie' ) ),
			array( 'key' => 'assets', 'label' => __( 'Regenerate Bricks assets', 'bricks-ie' ) ),
			array( 'key' => 'signatures', 'label' => __( 'Regenerate code signatures', 'bricks-ie' ) ),
			array( 'key' => 'cache', 'label' => __( 'Flush cache', 'bricks-ie' ) ),
		);
	}

	/**
	 * Calculate overall import progress.
	 *
	 * @param array $state Import state.
	 * @param bool  $done Whether the import is complete.
	 * @return int
	 */
	private function calculate_import_percent( $state, $done ) {
		if ( $done ) {
			return 100;
		}

		$completed_steps = ! empty( $state['completed_steps'] ) && is_array( $state['completed_steps'] ) ? $state['completed_steps'] : array();
		$completed       = in_array( 'validate', $completed_steps, true ) ? 1 : 0;
		$completed      += (int) $state['posts_processed'];
		$completed      += (int) $state['options_processed'];

		foreach ( array( 'remap', 'normalize', 'assets', 'signatures', 'cache' ) as $step ) {
			if ( in_array( $step, $completed_steps, true ) ) {
				$completed++;
			}
		}

		$total = max( 1, (int) $state['total_units'] );

		return min( 99, max( 1, (int) floor( ( $completed / $total ) * 100 ) ) );
	}

	/**
	 * Clean up temporary files and transient state for an import session.
	 *
	 * @param array $state Import state.
	 */
	private function cleanup_import_state( $state ) {
		if ( ! empty( $state['is_temporary'] ) && ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
			@unlink( $state['zip_path'] );
		}

		if ( ! empty( $state['session_id'] ) ) {
			delete_transient( $this->get_import_session_key( $state['session_id'] ) );
		}
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
