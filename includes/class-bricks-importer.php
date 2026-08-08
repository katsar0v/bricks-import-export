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
	 * Archive schema version supported by this mutation path.
	 *
	 * Schema version 2 import orchestration is a later work package; the v1
	 * mutation path rejects every non-v1 archive fail-closed.
	 *
	 * @since 1.1.0
	 */
	const SCHEMA_VERSION_1 = 1;

	/**
	 * Maximum uncompressed bytes for a single post/option archive member (16 MiB).
	 *
	 * @since 1.1.0
	 */
	const MAX_ARCHIVE_MEMBER_SIZE = 16777216;

	/**
	 * Maximum decoded (base64-decoded) bytes for one schema 1 meta value (16 MiB).
	 *
	 * @since 1.1.0
	 */
	const MAX_DECODED_META_SIZE = 16777216;

	/**
	 * Maximum nesting depth accepted inside a decoded schema 1 meta value.
	 *
	 * Doubles as the bound against reference cycles produced by unserialize().
	 *
	 * @since 1.1.0
	 */
	const MAX_META_DEPTH = 128;

	/**
	 * Maximum total array elements accepted inside one decoded schema 1 meta value.
	 *
	 * @since 1.1.0
	 */
	const MAX_META_ELEMENTS = 50000;

	/**
	 * Maximum number of posts index entries.
	 *
	 * @since 1.1.0
	 */
	const MAX_INDEX_ENTRIES = 2000;

	/**
	 * Maximum JSON nesting depth when decoding archive members.
	 *
	 * @since 1.1.0
	 */
	const MAX_JSON_DEPTH = 128;
	const IMPORT_STATE_VERSION = 2;
	const IMPORT_LOCK_OPTION = 'bricks_ie_import_lock';
	const IMPORT_REGISTRY_OPTION = 'bricks_ie_import_sessions';
	const IMPORT_LEASE_SECONDS = 120;
	const IMPORT_STALE_RECOVERY_SECONDS = 86400;
	const IMPORT_MUTATION_GUARD_SECONDS = 86400;
	const OPTION_CAS_ATTEMPTS = 5;

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
	 * Option names actually written during this import.
	 *
	 * @var array
	 */
	private $imported_option_names = array();

	/**
	 * Source site URL recorded in the export manifest.
	 *
	 * @var string
	 */
	private $source_site_url = '';

	/**
	 * Archive validator collaborator (Bricks_IE_Archive_Validator instance).
	 *
	 * Null means "resolve lazily when the class is available"; false means
	 * "explicitly disabled" (used by isolated tests to exercise the legacy
	 * fallback validation path).
	 *
	 * @since 1.1.0
	 *
	 * @var object|false|null
	 */
	private $archive_validator = null;

	/**
	 * Bricks transfer adapter collaborator (Bricks_IE_Bricks_Transfer_Adapter
	 * instance).
	 *
	 * Null means "resolve lazily when the class is available"; false means
	 * "explicitly disabled" (used by isolated tests).
	 *
	 * @since 1.1.0
	 *
	 * @var object|false|null
	 */
	private $transfer_adapter = null;

	/**
	 * Error code collected while recursively validating a decoded meta value.
	 *
	 * Empty string means "no problem found so far".
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private $meta_structure_error = '';

	/**
	 * Element counter used while recursively validating a decoded meta value.
	 *
	 * @since 1.1.0
	 *
	 * @var int
	 */
	private $meta_structure_elements = 0;

	private $native_identity_maps = array();
	private $import_conflict_mode = 'skip';
	private $allow_overwrite = false;
	private $v2_source_post_ids = array();
	private $native_source_ids = array();
	private $allow_sensitive_settings = false;

	/**
	 * Constructor.
	 *
	 * Collaborator instances are injectable for isolated tests. Production
	 * callers keep constructing the importer without arguments; the archive
	 * validator and transfer adapter are then resolved lazily from their
	 * class names when those files are loaded.
	 *
	 * @since 1.1.0
	 *
	 * @param array $collaborators {
	 *     Optional collaborator overrides.
	 *
	 *     @type object $archive_validator         Bricks_IE_Archive_Validator instance.
	 *     @type object $transfer_adapter          Bricks_IE_Bricks_Transfer_Adapter instance.
	 *     @type bool   $disable_archive_validator Force the validator off (tests only).
	 *     @type bool   $disable_transfer_adapter  Force the adapter off (tests only).
	 * }
	 */
	public function __construct( array $collaborators = array() ) {
		if ( isset( $collaborators['archive_validator'] ) && is_object( $collaborators['archive_validator'] ) ) {
			$this->archive_validator = $collaborators['archive_validator'];
		} elseif ( ! empty( $collaborators['disable_archive_validator'] ) ) {
			$this->archive_validator = false;
		}

		if ( isset( $collaborators['transfer_adapter'] ) && is_object( $collaborators['transfer_adapter'] ) ) {
			$this->transfer_adapter = $collaborators['transfer_adapter'];
		} elseif ( ! empty( $collaborators['disable_transfer_adapter'] ) ) {
			$this->transfer_adapter = false;
		}
	}

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
	 * Enforce the exact source/target Bricks version equality for schema 1.
	 *
	 * Preserved unchanged from the 1.0.2 importer: schema version 1 archives
	 * carry raw version-specific option data and must have been exported with
	 * exactly the Bricks version this site runs.
	 *
	 * @since 1.1.0
	 *
	 * @param array $manifest Decoded outer manifest.
	 * @return true|WP_Error
	 */
	private function check_exact_bricks_version( $manifest ) {
		$source_bricks = isset( $manifest['bricks_version'] ) ? $manifest['bricks_version'] : null;
		$target_bricks = $this->get_current_bricks_version();

		if ( null === $source_bricks ) {
			return new WP_Error( 'no_bricks_version', __( 'Archive does not contain a Bricks version. Please re-export from a site running this version of the export tool.', 'bricks-ie' ) );
		}

		if ( $source_bricks !== $target_bricks ) {
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

		return true;
	}

	/**
	 * Require a real current WordPress administrator for importer mutations.
	 *
	 * @return int|WP_Error Current user ID or an authorization error.
	 */
	private function authorize_current_import_admin() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id < 1 || ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'import_auth_required', __( 'A real current WordPress administrator is required to import an archive.', 'bricks-ie' ) );
		}

		return $user_id;
	}

	/**
	 * Import from a zip file path.
	 *
	 * This is the core schema version 1 import logic used by both admin upload
	 * and WP-CLI. Since 1.1.0 (T6) the mutation path itself is hardened, not
	 * just preflight: the archive validator result is used when available, the
	 * exact Bricks version check is retained, option/meta/post-type allowlists
	 * are enforced before any decode/delete/write, schema version 1 meta
	 * values are decoded strictly (strict base64, size limits,
	 * allowed_classes=false unserialize with an explicit b:0; exception, and a
	 * recursive rejection of objects, resources, and excessive nesting/count),
		 * automatic code-signature approval no longer runs, and cache cleanup is a
		 * scoped no-op. Schema version 2 uses the confirmed native transfer path.
	 *
	 * The optional $request parameter carries the import policy, including the
	 * sensitive-settings authorization used by the synchronous v1 path. The
	 * public 'posts_imported' / 'options_imported' / 'id_remaps' result keys
	 * remain unchanged.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @param array  $request  Optional. Reserved import request/policy flags
	 *                         (including sensitive-settings authorization).
	 * @return array|WP_Error On success returns array with keys 'posts_imported', 'options_imported', 'id_remaps'. On failure a WP_Error.
	 */
	public function import_from_zip( $zip_path, array $request = array() ) {
		$user_id = $this->authorize_current_import_admin();
		if ( is_wp_error( $user_id ) ) return $user_id;

		$policy = $this->preflight_normalize_policy( $request );
		if ( is_wp_error( $policy ) ) return $policy;

		$this->import_conflict_mode     = $policy['conflict_mode'];
		$this->allow_overwrite          = (bool) $policy['allow_overwrite'];
		$this->allow_sensitive_settings = (bool) $policy['allow_sensitive_settings'];
		$validation = $this->preflight_validate_archive( $zip_path );
		if ( ! is_wp_error( $validation ) && 2 === (int) $validation['schema_version'] ) {
			return $this->import_schema_v2( $zip_path, $request );
		}
		$state = $this->create_import_state( $zip_path, $this->allow_sensitive_settings, $this->import_conflict_mode, $this->allow_overwrite );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$payload_check = $this->validate_v1_payloads_before_lock( $zip_path, $state['post_index'] );
		if ( is_wp_error( $payload_check ) ) return $payload_check;
		$archive_check = $this->verify_import_archive_hash( $state );
		if ( is_wp_error( $archive_check ) ) return $archive_check;
		$secret = $this->new_secret_token(); if ( is_wp_error( $secret ) ) return $secret;
		$owner = hash( 'sha256', $secret );
		$sync_session = $this->canonicalize_import_session_id( 'sync-v1-' . substr( $owner, 0, 16 ) );
		$lease = $this->acquire_import_lease( $owner, $sync_session, $user_id, $state['archive_hash'] );
		if ( is_wp_error( $lease ) ) return $lease;

		$batch_size = max( 1, (int) $state['posts_total'] + (int) $state['options_total'] + 1 );

		try {
			while ( empty( $state['done'] ) ) {
				$archive_check = $this->verify_import_archive_hash( $state );
				if ( is_wp_error( $archive_check ) ) return $archive_check;
				if ( ! $this->renew_import_lease( array( 'session_id' => $sync_session, 'lease_owner_hash' => $owner ), self::IMPORT_MUTATION_GUARD_SECONDS ) ) return new WP_Error( 'import_lease_lost', __( 'Import lease was lost.', 'bricks-ie' ) );
				$result = $this->advance_import_state( $state, $batch_size );
				if ( is_wp_error( $result ) ) return $result;
			}
			return array(
				'posts_imported'   => (int) $state['posts_imported'],
				'options_imported' => (int) $state['options_imported'],
				'id_remaps'        => count( $this->id_map ),
			);
		} finally {
			$this->release_import_lease( array( 'session_id' => $sync_session, 'lease_owner_hash' => $owner ) );
		}
	}

	private function validate_v1_payloads_before_lock( $zip_path, $index ) {
		$zip = $this->open_import_zip( $zip_path );
		if ( is_wp_error( $zip ) ) return $zip;
		foreach ( (array) $index as $entry ) {
			$member = 'posts/' . $entry['file'];
			$payload = json_decode( $zip->getFromName( $member ), true, $this->get_max_json_depth() );
			if ( ! is_array( $payload ) ) { $zip->close(); return new WP_Error( 'invalid_post', sprintf( __( 'Invalid JSON in %s', 'bricks-ie' ), $member ) ); }
			if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) foreach ( $payload['meta'] as $key => $value ) {
				if ( ! in_array( (string) $key, $this->get_meta_keys(), true ) ) { $zip->close(); return new WP_Error( 'forbidden_meta_key', sprintf( __( 'Meta key "%s" is not allowed.', 'bricks-ie' ), $key ) ); }
				$decoded = $this->decode_legacy_meta_value( $value, (string) $key, $member );
				if ( is_wp_error( $decoded ) ) { $zip->close(); return $decoded; }
			}
		}
		$zip->close();
		return true;
	}

	/**
	 * Execute a confirmed schema-v2 import. Native domains are deliberately
	 * imported one canonical type at a time so a failed call is never retried
	 * blindly and completed stages remain observable in the result.
	 *
	 * @param string $zip_path Archive path.
	 * @param array  $request  Preflight policy and acknowledgement.
	 * @return array|WP_Error
	 */
	private function import_schema_v2( $zip_path, array $request ) {
		$this->id_map = array();
		$this->native_identity_maps = array();
		$this->native_source_ids = array();
		$report = $this->preflight( $zip_path, $request );
		if ( is_wp_error( $report ) ) return $report;
		if ( 'blocked' === $report['status'] ) return new WP_Error( 'preflight_blocked', __( 'The archive is blocked by preflight and was not imported.', 'bricks-ie' ) );
		if ( empty( $request['backup_acknowledged'] ) ) return new WP_Error( 'backup_acknowledgement_required', __( 'A backup acknowledgement is required before a schema-v2 import.', 'bricks-ie' ) );
		if ( ! empty( $request['import_images'] ) ) return new WP_Error( 'import_images_disabled', __( 'Image downloads are disabled for schema-v2 imports.', 'bricks-ie' ) );
		$confirmed = isset( $request['preflight'] ) && is_array( $request['preflight'] ) ? $request['preflight'] : ( isset( $request['preflight_plan'] ) && is_array( $request['preflight_plan'] ) ? array( 'plan' => $request['preflight_plan'], 'archive_hash' => isset( $request['archive_hash'] ) ? $request['archive_hash'] : '' ) : array() );
		if ( empty( $confirmed['plan'] ) || ! isset( $confirmed['archive_hash'], $confirmed['plan_hash'] ) || $confirmed['archive_hash'] !== $report['archive_hash'] || $confirmed['plan_hash'] !== $report['plan_hash'] ) return new WP_Error( 'preflight_confirmation_required', __( 'The schema-v2 import must use the exact plan, plan hash, and archive hash returned by preflight.', 'bricks-ie' ) );
		if ( ! isset( $confirmed['plan']['conflict_mode'], $confirmed['plan']['allow_overwrite'] ) || $confirmed['plan']['conflict_mode'] !== $report['plan']['conflict_mode'] || (bool) $confirmed['plan']['allow_overwrite'] !== (bool) $report['plan']['allow_overwrite'] ) return new WP_Error( 'preflight_policy_mismatch', __( 'The confirmed conflict policy no longer matches preflight.', 'bricks-ie' ) );
		$plan = $report['plan'];

		$validation = $this->preflight_validate_archive( $zip_path );
		if ( is_wp_error( $validation ) || 2 !== (int) $validation['schema_version'] ) return new WP_Error( 'invalid_v2_confirmation', __( 'The confirmed archive is no longer a schema-v2 archive.', 'bricks-ie' ) );
		$this->source_site_url = ! empty( $validation['manifest']['site_url'] ) ? esc_url_raw( (string) $validation['manifest']['site_url'] ) : '';
		$archive_hash = hash_file( 'sha256', $zip_path );
		if ( $archive_hash !== $report['archive_hash'] ) return new WP_Error( 'archive_changed', __( 'The archive changed after preflight.', 'bricks-ie' ) );

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$owner   = $this->new_secret_token();
		if ( is_wp_error( $owner ) ) return $owner;
		$owner   = hash( 'sha256', $owner );
		$session = 'sync-' . substr( $owner, 0, 16 );
		$lease   = $this->acquire_import_lease( $owner, $session, $user_id, $archive_hash );
		if ( is_wp_error( $lease ) ) return $lease;

		$state = array( 'session_id' => $session, 'lease_owner_hash' => $owner, 'zip_path' => $zip_path, 'is_temporary' => false );
		$result = $this->get_v2_result_skeleton();
		try {
			$zip = $this->open_import_zip( $zip_path );
			if ( is_wp_error( $zip ) ) throw new Exception( $zip->get_error_message() );
			$bytes = $zip->getFromName( 'bricks/package.zip' );
			$zip->close();
			$expected = ! empty( $report['plan']['native']['package_sha256'] ) ? $report['plan']['native']['package_sha256'] : '';
			if ( $expected && ( false === $bytes || '' === $bytes || ! hash_equals( $expected, hash( 'sha256', $bytes ) ) ) ) throw new Exception( __( 'The native package hash does not match preflight.', 'bricks-ie' ) );

			$adapter = $this->get_transfer_adapter();
			$canonical = $this->get_v2_native_order();
			$selected  = isset( $report['plan']['native']['items'] ) && is_array( $report['plan']['native']['items'] ) ? $report['plan']['native']['items'] : array();
			foreach ( $canonical as $type ) {
				if ( ! $expected ) break;
				if ( empty( $selected[ $type ] ) ) continue;
				if ( ! is_object( $adapter ) || ! is_callable( array( $adapter, 'import_package' ) ) || ! is_callable( array( $adapter, 'list_items' ) ) ) {
					$result['status'] = 'partial';
					$result['failed'][] = $type;
					$result['warnings'][] = __( 'The verified native transfer adapter is unavailable or incomplete.', 'bricks-ie' );
					break;
				}
				if ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) throw new Exception( __( 'Import lease was lost before a native stage.', 'bricks-ie' ) );
				$native = $adapter->import_package( $bytes, array( 'types' => array( $type ), 'items' => array( $type => $selected[ $type ] ) ), array( 'conflict_mode' => $plan['conflict_mode'], 'allow_overwrite' => (bool) $plan['allow_overwrite'], 'allow_sensitive_settings' => ! empty( $plan['allow_sensitive_settings'] ), 'import_images' => false ) );
				if ( is_wp_error( $native ) || ( is_array( $native ) && isset( $native['success'] ) && false === $native['success'] ) ) { $result['status'] = 'partial'; $result['failed'][] = $type; $result['native_result'][ $type ] = is_wp_error( $native ) ? $native->get_error_code() : 'native_failed'; break; }
				$result['native_result'][ $type ] = $native;
				if ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) { $result['status'] = 'partial'; $result['warnings'][] = __( 'Import lease was lost after a native mutation; no retry will be attempted.', 'bricks-ie' ); break; }
				if ( ! is_callable( array( $adapter, 'list_items' ) ) ) { $result['status'] = 'partial'; $result['failed'][] = $type; $result['warnings'][] = __( 'The native identity-listing adapter method is no longer callable.', 'bricks-ie' ); break; }
				$listed = $adapter->list_items( array( $type ) );
				if ( is_wp_error( $listed ) ) { $result['status'] = 'partial'; $result['failed'][] = $type; $result['warnings'][] = $listed->get_error_message(); break; }
				$source_descriptors = isset( $report['plan']['native']['descriptors'][ $type ] ) ? $report['plan']['native']['descriptors'][ $type ] : array();
				if ( 'global-queries' === $type ) foreach ( $source_descriptors as $descriptor ) if ( is_array( $descriptor ) && isset( $descriptor['id'] ) ) $this->native_source_ids['global-queries'][ (string) $descriptor['id'] ] = true;
				$result['mappings'][ $type ] = $this->derive_native_identity_map( $source_descriptors, $listed, $type );
				$this->native_identity_maps[ $type ] = $result['mappings'][ $type ];
				if ( ! in_array( $type, $result['completed_steps'], true ) ) $result['completed_steps'][] = $type;
				set_transient( 'bricks_ie_import_v2_' . sanitize_key( $session ), $result, HOUR_IN_SECONDS );
				if ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) throw new Exception( __( 'Import lease was lost after a native stage.', 'bricks-ie' ) );
			}
			if ( 'partial' !== $result['status'] ) {
				if ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) throw new Exception( __( 'Import lease was lost before post import.', 'bricks-ie' ) );
				$post_result = $this->import_v2_posts( $zip_path, $validation['posts_index'], $result, isset( $plan['posts'] ) ? $plan['posts'] : array(), $plan['conflict_mode'], ! empty( $plan['allow_overwrite'] ) );
				if ( is_wp_error( $post_result ) ) {
					$result['status'] = empty( $result['completed_steps'] ) ? 'failed' : 'partial';
					if ( ! in_array( 'posts', $result['failed'], true ) ) $result['failed'][] = 'posts';
					$result['warnings'][] = $post_result->get_error_message();
				} else {
					$result = $post_result;
					$result['mappings']['posts'] = $this->id_map;
					$result['id_remaps'] = count( $this->id_map );
					if ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) { $result['status'] = 'partial'; $result['warnings'][] = __( 'Import lease was lost after post import.', 'bricks-ie' ); }
					if ( ! is_object( $adapter ) || ! is_callable( array( $adapter, 'regenerate_css_files' ) ) ) {
						$result['status'] = 'partial';
						$this->mark_v2_assets_failed( $result );
						$result['warnings'][] = __( 'Bricks CSS regeneration was not run because no verified callable public regeneration method was available.', 'bricks-ie' );
					} elseif ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) {
						$result['status'] = 'partial';
						$this->mark_v2_assets_failed( $result );
						$result['warnings'][] = __( 'Import lease was lost before Bricks CSS regeneration.', 'bricks-ie' );
					} else {
						$css = $adapter->regenerate_css_files();
						if ( ! is_array( $css ) || ! array_key_exists( 'success', $css ) || true !== $css['success'] ) {
							$result['status'] = 'partial';
							$this->mark_v2_assets_failed( $result );
							$result['warnings'][] = is_wp_error( $css ) ? $css->get_error_message() : __( 'Bricks CSS regeneration did not return a verified success result.', 'bricks-ie' );
						} elseif ( ! in_array( 'assets', $result['completed_steps'], true ) ) {
							$result['completed_steps'][] = 'assets';
						}
					}
				}
			}
		} catch ( Exception $exception ) {
			$result['status'] = empty( $result['completed_steps'] ) ? 'failed' : 'partial';
			$result['warnings'][] = $exception->getMessage();
			set_transient( 'bricks_ie_import_v2_' . sanitize_key( $session ), $result, HOUR_IN_SECONDS );
		} finally {
			if ( 'completed' === $result['status'] ) delete_transient( 'bricks_ie_import_v2_' . sanitize_key( $session ) );
			$this->release_import_lease( $state );
		}
		return $result;
	}

	private function import_v2_posts( $zip_path, $index, array $result, $planned_posts = array(), $conflict_mode = 'skip', $allow_overwrite = false ) {
		$zip = $this->open_import_zip( $zip_path );
		if ( is_wp_error( $zip ) ) return $zip;
		$records = array();
		$blocked = array();
		$this->id_map = array();
		$this->v2_source_post_ids = array();
		foreach ( (array) $index as $source_entry ) {
			if ( ! empty( $source_entry['file'] ) ) {
				$source_payload = json_decode( $zip->getFromName( 'katsarov/posts/' . $source_entry['file'] ), true, $this->get_max_json_depth() );
				if ( is_array( $source_payload ) && ! empty( $source_payload['id'] ) ) {
					$this->v2_source_post_ids[] = (int) $source_payload['id'];
				}
			}
		}
		// Validate every native reference before the first shell write. This
		// prevents a page with an unresolved class/component/etc. from leaving
		// behind a partially-created post.
		foreach ( (array) $index as $candidate ) {
			if ( ! is_array( $candidate ) || empty( $candidate['file'] ) ) continue;
			$raw = $zip->getFromName( 'katsarov/posts/' . $candidate['file'] );
			$decoded = json_decode( $raw, true, $this->get_max_json_depth() );
			$findings = array();
			if ( is_array( $decoded ) && isset( $decoded['meta'] ) ) {
				$check = $decoded['meta'];
				$this->apply_native_identity_references( $check, array(), $findings );
				$this->find_unresolved_v2_post_references( $decoded['meta'], $this->v2_source_post_ids, array(), $findings );
			}
			if ( ! empty( $findings ) ) $blocked[ $candidate['file'] ] = $findings;
		}
		foreach ( (array) $index as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['file'] ) ) continue;
			if ( isset( $blocked[ $entry['file'] ] ) ) { $blocked_slug = isset( $entry['slug'] ) ? $entry['slug'] : $entry['file']; $result['skipped'][] = $blocked_slug; $this->mark_v2_post_failed( $result, $blocked_slug ); $result['warnings'][] = sprintf( __( 'Page "%s" has unresolved typed references: %s.', 'bricks-ie' ), $blocked_slug, implode( ', ', $blocked[ $entry['file'] ] ) ); continue; }
			$member = 'katsarov/posts/' . $entry['file'];
			$payload = json_decode( $zip->getFromName( $member ), true, $this->get_max_json_depth() );
			if ( ! is_array( $payload ) ) { $zip->close(); return new WP_Error( 'invalid_post', sprintf( __( 'Invalid JSON in %s', 'bricks-ie' ), $member ) ); }
			$type = isset( $payload['type'] ) && is_string( $payload['type'] ) ? $payload['type'] : '';
			$slug = isset( $payload['slug'] ) && is_string( $payload['slug'] ) ? $payload['slug'] : '';
			if ( 'bricks_template' === $type || '' === $type || '' === $slug || ! post_type_exists( $type ) ) { $result['skipped'][] = $slug; continue; }
			$planned = $this->find_v2_post_plan( $planned_posts, $entry['file'] );
			$source_id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
			if ( ! is_array( $planned ) || ! isset( $planned['action'] ) ) {
				$this->report_v2_target_state_mismatch( $result, $slug, 'unplanned', __( 'the confirmed plan has no action for this post', 'bricks-ie' ) );
				continue;
			}

			$action             = (string) $planned['action'];
			$expected_target_id = isset( $planned['target_id'] ) ? (int) $planned['target_id'] : 0;
			$existing           = $this->find_posts_by_slug_type( $slug, $type );

			if ( count( $existing ) > 1 ) {
				$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'multiple matching targets now exist', 'bricks-ie' ) );
				continue;
			}

			if ( 'skip' === $action ) {
				if ( $expected_target_id > 0 ) {
					if ( 1 !== count( $existing ) || $expected_target_id !== (int) $existing[0]->ID ) {
						$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'the previously confirmed target is no longer present', 'bricks-ie' ) );
						continue;
					}
					if ( $source_id ) $this->id_map[ $source_id ] = $expected_target_id;
				}
				$result['skipped'][] = $slug;
				continue;
			}

			if ( 'create' === $action ) {
				if ( ! empty( $existing ) ) {
					$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'a matching target now exists', 'bricks-ie' ) );
					continue;
				}
				if ( ! in_array( $type, $this->get_create_missing_post_types(), true ) ) {
					$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'this post type is not approved for creation', 'bricks-ie' ) );
					continue;
				}

				$post_id = wp_insert_post( array(
					'post_name'   => $slug,
					'post_type'   => $type,
					'post_status' => isset( $payload['status'] ) ? (string) $payload['status'] : 'publish',
					'post_title'  => isset( $payload['title'] ) ? (string) $payload['title'] : '',
				), true );
				if ( is_wp_error( $post_id ) || (int) $post_id < 1 ) {
					$result['status']   = 'partial';
					$result['failed'][] = $slug;
					$result['warnings'][] = is_wp_error( $post_id )
						? sprintf( __( 'Page "%1$s" could not be created: %2$s', 'bricks-ie' ), $slug, $post_id->get_error_message() )
						: sprintf( __( 'Page "%s" could not be created because WordPress returned no post ID.', 'bricks-ie' ), $slug );
					continue;
				}
				$post_id = (int) $post_id;
				if ( $source_id ) $this->id_map[ $source_id ] = $post_id;
				if ( ! in_array( $slug, $result['created'], true ) ) $result['created'][] = $slug;
			} elseif ( 'update' === $action || 'meta_only' === $action ) {
				if ( 'replace' !== $conflict_mode || ! $allow_overwrite ) {
					$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'replacement is no longer authorized', 'bricks-ie' ) );
					continue;
				}
				if ( 1 !== count( $existing ) || $expected_target_id < 1 || $expected_target_id !== (int) $existing[0]->ID ) {
					$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'the confirmed update target is missing or has changed', 'bricks-ie' ) );
					continue;
				}

				$post_id = $expected_target_id;
				if ( 'update' === $action ) {
					if ( ! in_array( $type, $this->get_update_post_fields_post_types(), true ) ) {
						$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'this post type is not approved for core-field updates', 'bricks-ie' ) );
						continue;
					}
					$updated_id = wp_update_post( array(
						'ID'          => $post_id,
						'post_title'  => isset( $payload['title'] ) ? (string) $payload['title'] : '',
						'post_status' => isset( $payload['status'] ) ? (string) $payload['status'] : 'publish',
					), true );
					if ( is_wp_error( $updated_id ) || (int) $updated_id < 1 || (int) $updated_id !== $post_id ) {
						$result['status']   = 'partial';
						$result['failed'][] = $slug;
						$result['warnings'][] = is_wp_error( $updated_id )
							? sprintf( __( 'Page "%1$s" could not be updated: %2$s', 'bricks-ie' ), $slug, $updated_id->get_error_message() )
							: sprintf( __( 'Page "%s" could not be updated because WordPress returned an invalid post ID.', 'bricks-ie' ), $slug );
						continue;
					}
					if ( ! in_array( $slug, $result['updated'], true ) ) $result['updated'][] = $slug;
				}
			} else {
				$this->report_v2_target_state_mismatch( $result, $slug, $action, __( 'the confirmed action is not supported', 'bricks-ie' ) );
				continue;
			}

			// Retain provisional mappings for cyclic resolution; partial results report both core mutation and metadata failure.
			if ( $source_id ) $this->id_map[ $source_id ] = $post_id;
			$records[] = array( 'post_id' => $post_id, 'slug' => $slug, 'action' => $action, 'meta' => isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array() );
		}
		foreach ( $records as $record ) {
			$findings = array();
			$meta = $record['meta'];
			$this->apply_native_identity_references( $meta, array(), $findings );
			if ( ! empty( $findings ) ) { $result['skipped'][] = $record['slug']; $this->mark_v2_post_failed( $result, $record['slug'] ); $result['warnings'][] = sprintf( __( 'Page "%s" has unresolved typed references: %s.', 'bricks-ie' ), $record['slug'], implode( ', ', $findings ) ); continue; }
			$allowlisted = $this->get_meta_keys();
			if ( in_array( $record['action'], array( 'update', 'meta_only' ), true ) ) {
				$stored_meta = get_post_meta( $record['post_id'], '', false );
				foreach ( $allowlisted as $key ) {
					if ( ! array_key_exists( $key, $record['meta'] ) && is_array( $stored_meta ) && array_key_exists( $key, $stored_meta ) ) {
						$deleted = delete_post_meta( $record['post_id'], $key );
						if ( false === $deleted && array_key_exists( $key, (array) get_post_meta( $record['post_id'], '', false ) ) ) {
							$this->report_v2_meta_failure( $result, $record['slug'], $key, 'delete' );
							continue 2;
						}
					}
				}
			}
			foreach ( $record['meta'] as $key => $value ) {
				if ( ! is_string( $key ) || ! in_array( $key, $allowlisted, true ) ) continue;
				$value = $this->replace_typed_post_references( $value );
				$this->apply_native_identity_references( $value, array( (string) $key ), $findings );
				$value = $this->recursive_normalize_imported_media( $value );
				$written = update_post_meta( $record['post_id'], $key, $value );
				$stored = get_post_meta( $record['post_id'], $key, true );
				if ( false === $written && ! $this->v2_meta_values_equal( $stored, $value ) ) {
					$this->report_v2_meta_failure( $result, $record['slug'], $key, 'write' );
					continue 2;
				}
				if ( ! $this->v2_meta_values_equal( $stored, $value ) ) {
					$this->report_v2_meta_failure( $result, $record['slug'], $key, 'write' );
					continue 2;
				}
			}
			$result['posts_imported']++;
		}
		$zip->close();
		if ( ! in_array( 'posts', $result['completed_steps'], true ) ) $result['completed_steps'][] = 'posts';
		return $result;
	}

	private function report_v2_target_state_mismatch( &$result, $slug, $action, $reason ) {
		$result['skipped'][]  = $slug;
		$this->mark_v2_post_failed( $result, $slug );
		$result['warnings'][] = sprintf(
			/* translators: 1: post slug, 2: planned action, 3: target-state mismatch */
			__( 'Page "%1$s" was skipped because its planned %2$s action no longer matches the target: %3$s.', 'bricks-ie' ),
			$slug,
			$action,
			$reason
		);
	}

	private function mark_v2_post_failed( &$result, $slug ) {
		$result['status'] = 'partial';
		if ( ! in_array( $slug, $result['failed'], true ) ) $result['failed'][] = $slug;
	}

	private function report_v2_meta_failure( &$result, $slug, $key, $operation ) {
		$this->mark_v2_post_failed( $result, $slug );
		$result['warnings'][] = sprintf( __( 'Page "%1$s" metadata %2$s failed for key "%3$s"; the post was not completed.', 'bricks-ie' ), $slug, $operation, $key );
	}

	private function v2_meta_values_equal( $actual, $expected ) {
		return serialize( $actual ) === serialize( $expected );
	}

	private function get_v2_native_order() {
		return array( 'settings', 'breakpoints', 'color-palettes', 'theme-styles', 'classes', 'variables', 'custom-fonts', 'icon-manager', 'global-queries', 'components', 'templates', 'custom-capabilities' );
	}

	private function find_unresolved_v2_post_references( $data, $source_ids, $path, &$findings ) {
		if ( ! is_array( $data ) ) return;
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			$next = array_merge( $path, array( $key ) );
			if ( $this->is_post_reference_key( $key, $path ) ) {
				$values = is_array( $value ) ? $value : array( $value );
				foreach ( $values as $item ) if ( ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) && ! in_array( (int) $item, array_map( 'intval', $source_ids ), true ) && !( 'templateId' === $key && isset( $this->native_identity_maps['templates'][ (string) $item ] ) ) ) $findings[] = implode( '.', $next );
			}
			$this->find_unresolved_v2_post_references( $value, $source_ids, $next, $findings );
		}
	}

	private function get_v2_result_skeleton() {
		return array( 'posts_imported' => 0, 'options_imported' => 0, 'id_remaps' => 0, 'status' => 'completed', 'native_result' => array(), 'warnings' => array(), 'created' => array(), 'updated' => array(), 'skipped' => array(), 'failed' => array(), 'mappings' => array(), 'completed_steps' => array() );
	}

	private function mark_v2_assets_failed( &$result ) {
		if ( ! in_array( 'assets', $result['failed'], true ) ) $result['failed'][] = 'assets';
	}

	private function derive_native_identity_map( $source, $listed, $type_id = '' ) {
		$types = isset( $listed['types'] ) && is_array( $listed['types'] ) ? $listed['types'] : array();
		$target_items = array();
		if ( isset( $types[ $type_id ] ) && is_array( $types[ $type_id ] ) ) $target_items = isset( $types[ $type_id ]['items'] ) && is_array( $types[ $type_id ]['items'] ) ? $types[ $type_id ]['items'] : $types[ $type_id ];
		else foreach ( $types as $descriptor ) if ( is_array( $descriptor ) && isset( $descriptor['id'] ) && (string) $descriptor['id'] === (string) $type_id ) { $target_items = isset( $descriptor['items'] ) && is_array( $descriptor['items'] ) ? $descriptor['items'] : array(); break; }
		if ( empty( $target_items ) && isset( $types[0] ) && is_array( $types[0] ) && isset( $types[0]['id'] ) && ! isset( $types[0]['items'] ) ) $target_items = $types;
		if ( empty( $target_items ) && isset( $listed['items'] ) && is_array( $listed['items'] ) ) $target_items = $listed['items'];
		$map = array();
		foreach ( (array) $source as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) continue;
			$candidates = array();
			foreach ( (array) $target_items as $target ) {
				if ( ! is_array( $target ) || ! isset( $target['id'] ) ) continue;
				if ( (string) $target['id'] === (string) $item['id'] ) $candidates[] = $target;
			}
			if ( 1 === count( $candidates ) ) { $map[ (string) $item['id'] ] = (string) $candidates[0]['id']; continue; }
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			if ( '' === $label ) continue;
			$candidates = array();
			foreach ( (array) $target_items as $target ) if ( is_array( $target ) && isset( $target['id'], $target['label'] ) && $label === (string) $target['label'] ) $candidates[] = $target;
			if ( count( $candidates ) > 1 ) {
				$category = isset( $item['category'] ) ? (string) $item['category'] : ( isset( $item['type'] ) ? (string) $item['type'] : '' );
				if ( '' !== $category ) {
					$candidates = array_filter( $candidates, function( $target ) use ( $category ) {
						$target_category = isset( $target['category'] ) ? (string) $target['category'] : ( isset( $target['type'] ) ? (string) $target['type'] : '' );
						return $category === $target_category;
					} );
				}
			}
			if ( 1 === count( $candidates ) ) $map[ (string) $item['id'] ] = (string) $candidates[0]['id'];
		}
		return $map;
	}

	private function apply_native_identity_references( &$data, $path, &$unresolved ) {
		if ( ! is_array( $data ) ) return;
		foreach ( $data as $key => &$value ) {
			$key = (string) $key;
			$next = array_merge( $path, array( $key ) );
			$type = false;
			if ( '_cssGlobalClasses' === $key || '_cssGlobalClassesProps' === $key ) $type = 'classes';
			elseif ( 'cid' === $key ) $type = 'components';
			elseif ( 'globalQueryId' === $key || ( 'id' === $key && ! empty( $path ) && 'query' === end( $path ) ) ) $type = 'global-queries';
			elseif ( in_array( $key, array( 'fontId', 'customFontId' ), true ) ) $type = 'custom-fonts';
			elseif ( in_array( $key, array( 'iconId', 'iconManagerId' ), true ) ) $type = 'icon-manager';
			elseif ( 'templateId' === $key && ! isset( $this->id_map[ (int) $value ] ) && ! in_array( (int) $value, $this->v2_source_post_ids, true ) ) $type = 'templates';
			if ( 'global-queries' === $type && 'id' === $key && ! isset( $this->native_source_ids['global-queries'][ (string) $value ] ) ) $type = false;
			if ( $type && isset( $this->native_identity_maps[ $type ] ) ) {
				$map = $this->native_identity_maps[ $type ];
				if ( '_cssGlobalClassesProps' === $key && is_array( $value ) ) {
					$mapped = array();
					foreach ( $value as $class_id => $props ) { if ( ! isset( $map[ (string) $class_id ] ) ) { $unresolved[] = implode( '.', array_merge( $next, array( (string) $class_id ) ) ); continue; } $mapped[ $map[ (string) $class_id ] ] = $props; }
					$value = $mapped;
				} else {
					$values = is_array( $value ) ? $value : array( $value ); $mapped = array();
					foreach ( $values as $item ) { if ( ! isset( $map[ (string) $item ] ) ) { $unresolved[] = implode( '.', $next ); continue; } $mapped[] = $map[ (string) $item ]; }
					$value = is_array( $value ) ? $mapped : ( isset( $mapped[0] ) ? $mapped[0] : $value );
				}
			} elseif ( $type ) $unresolved[] = implode( '.', $next );
			$this->apply_native_identity_references( $value, $next, $unresolved );
		}
		unset( $value );
	}

	private function find_v2_post_plan( $plans, $file ) {
		foreach ( (array) $plans as $plan ) {
			if ( is_array( $plan ) && isset( $plan['file'] ) && $plan['file'] === $file ) return $plan;
		}
		return null;
	}

	/**
	 * Run a complete no-write preflight for an export archive.
	 *
	 * Validates archive schema version 1 (plugin 1.0.x) and schema version 2
	 * (plugin 1.1.0) archives and produces a normalized report describing what
	 * an import would do. Preflight never writes: no options, posts, meta,
	 * transients, native import calls, CSS generation, signature regeneration,
	 * or cache operations happen here. Read-only database access (post
	 * existence lookups) is used for conflict detection.
	 *
	 * Uses Bricks_IE_Archive_Validator and Bricks_IE_Bricks_Transfer_Adapter
	 * when those classes are available. Schema version 2 archives fail closed
	 * (status "blocked") when either collaborator is missing or the installed
	 * native transfer contract has drifted. Schema version 2 mutation consumes
	 * this plan only after explicit hash, policy, and backup confirmation.
	 *
	 * @since 1.1.0
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @param array  $request  Optional import request/policy flags:
	 *                         'conflict_mode' ('skip'|'replace', default 'skip'),
	 *                         'allow_overwrite' (bool, required for 'replace'),
	 *                         'allow_sensitive_settings' (bool),
	 *                         'import_images' (bool; stays disabled in this release).
	 * @return array|WP_Error Normalized no-write report on success with keys:
	 *                        status (ready|warning|blocked), format_version,
	 *                        archive_hash, source_environment,
	 *                        target_environment, native_domains, posts,
	 *                        conflicts, dependencies, omissions,
	 *                        security_warnings, warnings, estimated_steps,
	 *                        plan, blocking. WP_Error for missing/unreadable
	 *                        archives, archive/schema violations, exact Bricks
	 *                        version mismatches (schema 1), and rejected
	 *                        policy input.
	 */
	public function preflight( $zip_path, array $request = array() ) {
		$this->cleanup_expired_import_sessions();
		if ( ! is_string( $zip_path ) || '' === $zip_path || ! file_exists( $zip_path ) || ! is_file( $zip_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Zip file not found.', 'bricks-ie' ) );
		}

		$archive_hash = hash_file( 'sha256', $zip_path );
		if ( false === $archive_hash ) {
			return new WP_Error( 'file_not_readable', __( 'The zip archive cannot be read.', 'bricks-ie' ) );
		}

		$validation = $this->preflight_validate_archive( $zip_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$policy = $this->preflight_normalize_policy( $request );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		if ( 1 === (int) $validation['schema_version'] ) {
			return $this->preflight_schema_v1( $zip_path, $archive_hash, $validation, $policy );
		}

		return $this->preflight_schema_v2( $zip_path, $archive_hash, $validation, $policy );
	}

	/**
	 * Resolve the archive validator collaborator.
	 *
	 * @since 1.1.0
	 *
	 * @return object|null Bricks_IE_Archive_Validator instance or null.
	 */
	private function get_archive_validator() {
		if ( false === $this->archive_validator ) {
			return null;
		}

		if ( is_object( $this->archive_validator ) ) {
			return $this->archive_validator;
		}

		if ( class_exists( 'Bricks_IE_Archive_Validator' ) ) {
			$this->archive_validator = new Bricks_IE_Archive_Validator();
			return $this->archive_validator;
		}

		return null;
	}

	/**
	 * Resolve the Bricks transfer adapter collaborator.
	 *
	 * @since 1.1.0
	 *
	 * @return object|null Bricks_IE_Bricks_Transfer_Adapter instance or null.
	 */
	private function get_transfer_adapter() {
		if ( false === $this->transfer_adapter ) {
			return null;
		}

		if ( is_object( $this->transfer_adapter ) ) {
			return $this->transfer_adapter;
		}

		if ( class_exists( 'Bricks_IE_Bricks_Transfer_Adapter' ) ) {
			$this->transfer_adapter = new Bricks_IE_Bricks_Transfer_Adapter();
			return $this->transfer_adapter;
		}

		return null;
	}

	/**
	 * Validate the archive structure before building the preflight report.
	 *
	 * Prefers the hardened Bricks_IE_Archive_Validator when available and
	 * falls back to the legacy 1.0.2 validation reads otherwise. Both paths
	 * are strictly read-only.
	 *
	 * @since 1.1.0
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @return array|WP_Error Validation context; includes 'via_validator'.
	 */
	private function preflight_validate_archive( $zip_path ) {
		$validator = $this->get_archive_validator();
		if ( ! $validator ) return new WP_Error( 'archive_validator_unavailable', __( 'The hardened archive validator is required for imports.', 'bricks-ie' ) );

		if ( $validator ) {
			$result = $validator->validate( $zip_path );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['via_validator'] = true;
			return $result;
		}

		return new WP_Error( 'archive_validator_unavailable', __( 'The hardened archive validator is required for imports.', 'bricks-ie' ) );
	}

	/**
	 * Legacy fallback validation reusing the existing 1.0.2 read helpers.
	 *
	 * Only used when Bricks_IE_Archive_Validator is not loaded. Schema
	 * version 2 archives cannot be fully validated on this path and are
	 * blocked later in preflight_schema_v2().
	 *
	 * @since 1.1.0
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @return array|WP_Error Validation context with 'via_validator' false.
	 */
	private function preflight_legacy_validate( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'ZipArchive is not available on this server.', 'bricks-ie' ) );
		}

		$zip = $this->open_import_zip( $zip_path );
		if ( is_wp_error( $zip ) ) {
			return $zip;
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

		$version = (int) $manifest['version'];
		if ( 1 !== $version && 2 !== $version ) {
			$zip->close();
			return new WP_Error( 'unsupported_schema_version', __( 'Unsupported archive schema version. Expected 1 or 2.', 'bricks-ie' ) );
		}

		$post_index = $this->get_post_index_from_zip( $zip );
		if ( is_wp_error( $post_index ) ) {
			$zip->close();
			return $post_index;
		}

		$option_files = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( is_string( $name ) && 'options/' !== $name && 0 === strpos( $name, 'options/' ) && '.json' === substr( $name, -5 ) ) {
				$option_files[] = $name;
			}
		}

		$post_files = array();
		foreach ( $post_index as $entry ) {
			$post_files[] = 'posts/' . $entry['file'];
		}

		$zip->close();

		return array(
			'schema_version' => $version,
			'manifest'       => $manifest,
			'posts_index'    => $post_index,
			'post_files'     => $post_files,
			'option_files'   => $option_files,
			'native_package' => null,
			'warnings'       => array(),
			'via_validator'  => false,
		);
	}

	/**
	 * Normalize and validate the import policy flags from the request.
	 *
	 * The default conflict mode is "skip". "replace" always requires explicit
	 * overwrite authorization. Remote template image import stays disabled in
	 * this release regardless of the request.
	 *
	 * @since 1.1.0
	 *
	 * @param array $request Raw request flags.
	 * @return array|WP_Error Normalized policy or WP_Error when rejected.
	 */
	private function preflight_normalize_policy( array $request ) {
		$conflict_mode = isset( $request['conflict_mode'] ) ? (string) $request['conflict_mode'] : 'skip';
		if ( ! in_array( $conflict_mode, array( 'skip', 'replace' ), true ) ) {
			$conflict_mode = 'skip';
		}

		$allow_overwrite = ! empty( $request['allow_overwrite'] );

		if ( 'replace' === $conflict_mode && ! $allow_overwrite ) {
			return new WP_Error(
				'bricks_ie_overwrite_requires_authorization',
				__( 'Replacing existing data requires explicit overwrite authorization. The default conflict mode is "skip".', 'bricks-ie' )
			);
		}

		return array(
			'conflict_mode'            => $conflict_mode,
			'allow_overwrite'          => $allow_overwrite,
			'allow_sensitive_settings' => ! empty( $request['allow_sensitive_settings'] ),
			'import_images'            => false,
		);
	}

	/**
	 * Preflight a schema version 1 (legacy 1.0.x) archive.
	 *
	 * Keeps the exact 1.0.2 Bricks version requirement: the archive must have
	 * been exported with exactly the Bricks version this site runs. The plan
	 * mirrors the synchronous 1.0.2 import state machine; the legacy import
	 * behavior itself is unchanged in this release.
	 *
	 * @since 1.1.0
	 *
	 * @param string $zip_path     Absolute path to the zip file.
	 * @param string $archive_hash SHA-256 of the outer archive.
	 * @param array  $validation   Validation context.
	 * @param array  $policy       Normalized policy flags.
	 * @return array|WP_Error Normalized preflight report.
	 */
	private function preflight_schema_v1( $zip_path, $archive_hash, $validation, $policy ) {
		$manifest = $validation['manifest'];

		// Exact Bricks version validation preserved from the 1.0.2 importer.
		$source_bricks = isset( $manifest['bricks_version'] ) ? $manifest['bricks_version'] : null;
		$target_bricks = $this->get_current_bricks_version();

		if ( null === $source_bricks ) {
			return new WP_Error( 'no_bricks_version', __( 'Archive does not contain a Bricks version. Please re-export from a site running this version of the export tool.', 'bricks-ie' ) );
		}

		if ( $source_bricks !== $target_bricks ) {
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

		$zip = $this->open_import_zip( $zip_path );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$importable_options = $this->get_importable_option_names_from_zip( $zip );
		$legacy_sensitive = array();
		if ( in_array( 'bricks_global_settings', $importable_options, true ) ) {
			$settings = json_decode( $zip->getFromName( 'options/bricks_global_settings.json' ), true, $this->get_max_json_depth() );
			if ( ! is_array( $settings ) ) {
				$zip->close();
				return new WP_Error( 'invalid_global_settings', __( 'The bricks_global_settings payload must be an array.', 'bricks-ie' ) );
			}
			$legacy_sensitive = array_values( array_intersect( array_keys( $settings ), $this->get_legacy_sensitive_settings_keys() ) );
			if ( $this->legacy_settings_contain_nested_secrets( $settings ) ) {
				$legacy_sensitive[] = 'remoteTemplates[*].password';
			}
			$legacy_sensitive = array_values( array_unique( $legacy_sensitive ) );
		}
		$planned            = $this->preflight_plan_posts( $zip, $validation['posts_index'], 'posts/', 1, $policy );
		$zip->close();

		if ( is_wp_error( $planned ) ) {
			return $planned;
		}

		$omissions = $planned['omissions'];
		foreach ( $validation['option_files'] as $option_file ) {
			$name = basename( $option_file, '.json' );
			if ( ! in_array( $name, $importable_options, true ) ) {
				$omissions[] = sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" is not in the import allowlist and will be skipped.', 'bricks-ie' ),
					$name
				);
			}
		}

		$report                = $this->preflight_base_report( 1, $archive_hash, $manifest, $validation['warnings'], $planned['security_warnings'], $omissions );
		$report['posts']       = $planned['posts'];
		$report['conflicts']   = $planned['conflicts'];
		$report['dependencies'] = $planned['dependencies'];
		if ( ! empty( $legacy_sensitive ) ) {
			$report['security_warnings'][] = $policy['allow_sensitive_settings']
				? __( 'Legacy sensitive Bricks settings are explicitly authorized for import.', 'bricks-ie' )
				: __( 'Legacy sensitive Bricks settings are present but will be excluded from import.', 'bricks-ie' );
		}

		foreach ( $validation['option_files'] as $option_file ) {
			$name                       = basename( $option_file, '.json' );
			$report['native_domains'][] = array(
				'domain'    => $name,
				'transport' => 'raw_option',
				'selected'  => in_array( $name, $importable_options, true ),
			);
		}

		// Mirrors the 1.0.2 progress model: validate + one unit per post and
		// option + remap, normalize, assets, signatures, cache.
		$report['estimated_steps'] = 1 + count( $validation['posts_index'] ) + count( $importable_options ) + 5;

		$report['plan'] = array(
			'format_version'  => 1,
			'path'            => 'legacy_v1',
			'conflict_mode'   => $policy['conflict_mode'],
			'allow_overwrite' => $policy['allow_overwrite'],
			'allow_sensitive_settings' => $policy['allow_sensitive_settings'],
			'legacy_sensitive_keys' => $legacy_sensitive,
			'import_images'   => false,
			'options'         => $importable_options,
			'posts'           => $this->preflight_plan_actions( $planned['posts'] ),
			'steps'           => array( 'validate', 'posts', 'options', 'remap', 'normalize', 'assets', 'signatures', 'cache' ),
		);

		return $this->preflight_finalize_status( $report );
	}

	/**
	 * Preflight a schema version 2 (1.1.0) archive.
	 *
	 * Inspects the embedded native Bricks package bytes through the transfer
	 * adapter (never parsing or rewriting them), validates the installed
	 * native target contract, derives an explicit native selection from the
	 * inspected manifest, and plans the plugin-owned posts. No mutation is
	 * performed in this release; the plan is consumed by the later schema
	 * version 2 import orchestration.
	 *
	 * @since 1.1.0
	 *
	 * @param string $zip_path     Absolute path to the zip file.
	 * @param string $archive_hash SHA-256 of the outer archive.
	 * @param array  $validation   Validation context.
	 * @param array  $policy       Normalized policy flags.
	 * @return array|WP_Error Normalized preflight report.
	 */
	private function preflight_schema_v2( $zip_path, $archive_hash, $validation, $policy ) {
		$manifest = $validation['manifest'];
		$warnings = isset( $validation['warnings'] ) ? array_values( (array) $validation['warnings'] ) : array();
		$omissions = $this->preflight_format_validated_omissions( isset( $validation['omissions'] ) ? $validation['omissions'] : array() );
		$omissions[] = __( 'General media files are not included; existing media references are normalized without downloading files.', 'bricks-ie' );

		// Schema version 2 requires the hardened validator; fail closed when
		// only the legacy fallback validation ran.
		if ( empty( $validation['via_validator'] ) ) {
			return $this->preflight_blocked_report(
				2,
				$archive_hash,
				$manifest,
				array(
					'code'    => 'archive_validator_unavailable',
					'message' => __( 'Schema version 2 archives require the hardened archive validator, which is not available.', 'bricks-ie' ),
				),
				null,
				$warnings,
				$omissions
			);
		}

		$security  = array();

		$adapter        = $this->get_transfer_adapter();
		$caps_report    = null;
		$native_domains = array();
		$native_plan    = array(
			'types'          => array(),
			'items'          => array(),
			'descriptors'    => array(),
			'excluded_items' => array(),
			'package_sha256' => null,
			'package_bytes'  => 0,
		);
		$conflicts    = array();
		$dependencies = array();

		if ( ! empty( $manifest['domains']['native_bricks'] ) ) {
			if ( ! $adapter ) {
				return $this->preflight_blocked_report(
					2,
					$archive_hash,
					$manifest,
					array(
						'code'    => 'native_adapter_unavailable',
						'message' => __( 'The archive contains a native Bricks package but the Bricks transfer adapter is not available.', 'bricks-ie' ),
					),
					null,
					$warnings,
					$omissions
				);
			}

			// Validate the installed native target contract before trusting
			// any native package inspection.
			$caps_report = $adapter->detect_capabilities();

			if ( empty( $caps_report['available'] ) ) {
				return $this->preflight_blocked_report(
					2,
					$archive_hash,
					$manifest,
					array(
						'code'    => 'native_contract_unavailable',
						'message' => __( 'The installed Bricks native transfer contract does not match the audited contract; refusing to import the native package.', 'bricks-ie' ),
						'errors'  => isset( $caps_report['errors'] ) ? (array) $caps_report['errors'] : array(),
					),
					$caps_report,
					$warnings,
					$omissions
				);
			}

			$zip = $this->open_import_zip( $zip_path );
			if ( is_wp_error( $zip ) ) {
				return $zip;
			}

			$package_bytes = $zip->getFromName( 'bricks/package.zip' );
			$zip->close();

			if ( false === $package_bytes ) {
				return new WP_Error( 'missing_native_package', __( 'Archive is missing bricks/package.zip.', 'bricks-ie' ) );
			}

			// Inspect the native package bytes without writing.
			$inspect = $adapter->inspect_package( $package_bytes );
			if ( is_wp_error( $inspect ) ) {
				return $this->preflight_blocked_report(
					2,
					$archive_hash,
					$manifest,
					array(
						'code'    => $inspect->get_error_code(),
						'message' => $inspect->get_error_message(),
					),
					$caps_report,
					$warnings,
					$omissions
				);
			}

			$native_manifest = isset( $inspect['manifest'] ) && is_array( $inspect['manifest'] ) ? $inspect['manifest'] : array();

			$expected_schema  = (string) $this->preflight_adapter_constant( $adapter, 'EXPECTED_SCHEMA', '' );
			$expected_version = (int) $this->preflight_adapter_constant( $adapter, 'EXPECTED_VERSION', 0 );
			$native_schema    = isset( $native_manifest['schema'] ) ? (string) $native_manifest['schema'] : '';
			$native_version   = isset( $native_manifest['version'] ) ? (int) $native_manifest['version'] : 0;

			if ( '' === $expected_schema || $expected_schema !== $native_schema || $expected_version < 1 || $expected_version !== $native_version ) {
				return $this->preflight_blocked_report(
					2,
					$archive_hash,
					$manifest,
					array(
						'code'    => 'native_schema_mismatch',
						'message' => __( 'The native package manifest does not match the audited Bricks transfer schema.', 'bricks-ie' ),
					),
					$caps_report,
					$warnings,
					$omissions
				);
			}

			$known_types   = (array) $this->preflight_adapter_constant( $adapter, 'KNOWN_TYPE_IDS', array() );
			$sensitive_ids = (array) $this->preflight_adapter_constant( $adapter, 'IMPORT_SENSITIVE_SETTINGS_IDS', array(
				'general', 'templates', 'builder', 'performance', 'maintenance', 'api-keys',
				'custom-code', 'woocommerce', 'settings', 'all',
			) );
			$code_types    = (array) $this->preflight_adapter_constant( $adapter, 'CODE_BEARING_TYPES', array() );
			$native_types  = isset( $native_manifest['types'] ) && is_array( $native_manifest['types'] ) ? $native_manifest['types'] : array();

			if ( empty( $known_types ) || empty( $sensitive_ids ) ) {
				return $this->preflight_blocked_report(
					2,
					$archive_hash,
					$manifest,
					array(
						'code'    => 'adapter_contract_unavailable',
						'message' => __( 'The Bricks transfer adapter does not expose the audited policy constants; refusing to plan the native import.', 'bricks-ie' ),
					),
					$caps_report,
					$warnings,
					$omissions
				);
			}

			// Derive the explicit native selection from the inspected
			// manifest. An empty selection never means "all items".
			$total_items = 0;

			foreach ( $native_types as $type_id => $type_manifest ) {
				$type_id = (string) $type_id;

				if ( ! in_array( $type_id, $known_types, true ) ) {
					return $this->preflight_blocked_report(
						2,
						$archive_hash,
						$manifest,
						array(
							'code'    => 'unsupported_native_type',
							'message' => sprintf(
								/* translators: %s: transfer type ID */
								__( 'The native package contains the unsupported transfer type "%s".', 'bricks-ie' ),
								$type_id
							),
						),
						$caps_report,
						$warnings,
						$omissions
					);
				}

				$items = isset( $type_manifest['items'] ) && is_array( $type_manifest['items'] ) ? $type_manifest['items'] : array();

				$item_ids       = array();
				$conflict_count = 0;
				$valid_items    = 0;

				foreach ( $items as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
						continue;
					}

					$valid_items++;
					$item_id = (string) $item['id'];

					// Sensitive settings are excluded unless explicitly
					// authorized by the request policy.
					if ( 'settings' === $type_id && in_array( $item_id, $sensitive_ids, true ) ) {
						if ( $policy['allow_sensitive_settings'] ) {
							$security[] = sprintf(
								/* translators: %s: sensitive settings item ID */
								__( 'Sensitive settings item "%s" is explicitly authorized for import.', 'bricks-ie' ),
								$item_id
							);
						} else {
							$native_plan['excluded_items']['settings'][] = $item_id;
							$security[]                                  = sprintf(
								/* translators: %s: sensitive settings item ID */
								__( 'Sensitive settings item "%s" is excluded; importing it requires explicit authorization.', 'bricks-ie' ),
								$item_id
							);
							continue;
						}
					}

					if ( isset( $item['conflict'] ) ) {
						$conflict_count++;
						$conflicts[] = array(
							'domain'     => 'native:' . $type_id,
							'type'       => $type_id,
							'id'         => $item_id,
							'label'      => isset( $item['label'] ) ? (string) $item['label'] : $item_id,
							'message'    => isset( $item['conflict']['message'] ) ? (string) $item['conflict']['message'] : '',
							'resolution' => $policy['conflict_mode'],
						);
					}

					$item_ids[] = $item_id;
				}

				$total_items += $valid_items;

				if ( empty( $item_ids ) ) {
					$native_plan['types'][] = $type_id;
					$native_plan['items'][ $type_id ] = array();
					$native_plan['descriptors'][ $type_id ] = array_values( array_filter( $items, 'is_array' ) );
					if ( isset( $native_plan['excluded_items'][ $type_id ] ) ) {
						$omissions[] = sprintf(
							/* translators: %s: transfer type ID */
							__( 'Native domain "%s" is fully excluded by policy and will not be imported.', 'bricks-ie' ),
							$type_id
						);
						$native_domains[] = array(
							'domain' => $type_id, 'transport' => 'native_package', 'selected' => false,
							'count' => 0, 'conflicts' => 0,
						);
					}
					continue;
				}

				$native_plan['types'][]           = $type_id;
				$native_plan['items'][ $type_id ] = $item_ids;
				$native_plan['descriptors'][ $type_id ] = array_values( array_filter( $items, 'is_array' ) );

				$native_domains[] = array(
					'domain'    => $type_id,
					'transport' => 'native_package',
					'selected'  => true,
					'count'     => count( $item_ids ),
					'conflicts' => $conflict_count,
				);

				if ( in_array( $type_id, $code_types, true ) ) {
					$security[] = sprintf(
						/* translators: %s: transfer type ID */
						__( 'Native domain "%s" can carry executable code; imported code remains unapproved until an administrator reviews it.', 'bricks-ie' ),
						$type_id
					);
				}

				if ( 'settings' === $type_id ) {
					$warnings[] = __( 'Bricks settings are environment-specific and may contain values that should not move between sites.', 'bricks-ie' );
				}
			}

			// Reconcile the outer manifest counts with the inspected package.
			$declared_types = isset( $manifest['counts']['native_types'] ) ? (int) $manifest['counts']['native_types'] : 0;
			$declared_items = isset( $manifest['counts']['native_items'] ) ? (int) $manifest['counts']['native_items'] : 0;

			if ( count( $native_types ) !== $declared_types || $total_items !== $declared_items ) {
				$warnings[] = sprintf(
					/* translators: 1: declared native types, 2: declared native items, 3: actual native types, 4: actual selectable items */
					__( 'manifest.json declares %1$d native type(s) and %2$d item(s), but the native package contains %3$d type(s) and %4$d selectable item(s).', 'bricks-ie' ),
					$declared_types,
					$declared_items,
					count( $native_types ),
					$total_items
				);
			}

			$native_plan['package_sha256'] = isset( $validation['native_package']['sha256'] )
				? (string) $validation['native_package']['sha256']
				: hash( 'sha256', $package_bytes );
			$native_plan['package_bytes']  = strlen( $package_bytes );
		}

		// Plan the plugin-owned posts (ordinary pages and Bricks-enabled CPT
		// records; bricks_template posts are rejected by the validator).
		$zip = $this->open_import_zip( $zip_path );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$planned = $this->preflight_plan_posts( $zip, $validation['posts_index'], 'katsarov/posts/', 2, $policy );
		$zip->close();

		if ( is_wp_error( $planned ) ) {
			return $planned;
		}

		$conflicts = array_merge( $conflicts, $planned['conflicts'] );
		$security  = array_merge( $security, $planned['security_warnings'] );
		$omissions = array_merge( $omissions, $planned['omissions'] );

		$active_posts = 0;
		foreach ( $planned['posts'] as $post ) {
			if ( 'skip' !== $post['action'] ) {
				$active_posts++;
			}
		}

		// Structural dependency notes between plugin-owned posts and the
		// native domains that satisfy their references. Per-field reference
		// resolution belongs to the later mapping phase.
		if ( $active_posts > 0 ) {
			foreach ( array( 'classes', 'variables', 'components', 'global-queries', 'templates', 'color-palettes', 'theme-styles' ) as $reference_type ) {
				if ( in_array( $reference_type, $native_plan['types'], true ) ) {
					$dependencies[] = array(
						'type'        => $reference_type,
						'required_by' => 'posts',
						'satisfied'   => true,
					);
				}
			}

			if ( empty( $dependencies ) ) {
				$warnings[] = __( 'Imported pages may reference native classes, variables, components, queries, or templates that are not included in this archive.', 'bricks-ie' );
			}
		}

		if ( ! empty( $manifest['domains']['template_conditions'] ) ) {
			$omissions[] = __( 'The template conditions sidecar is present but is not applied in this release; it requires review and a typed target map.', 'bricks-ie' );
		}

		if ( empty( $native_plan['types'] ) && 0 === $active_posts ) {
			return $this->preflight_blocked_report(
				2,
				$archive_hash,
				$manifest,
				array(
					'code'    => 'nothing_to_import',
					'message' => __( 'The archive contains no importable native domains or posts.', 'bricks-ie' ),
				),
				$caps_report,
				$warnings,
				$omissions
			);
		}

		$report                         = $this->preflight_base_report( 2, $archive_hash, $manifest, $warnings, $security, $omissions );
		$report['target_environment']   = $this->preflight_target_environment( $caps_report );
		$report['native_domains']       = $native_domains;
		$report['posts']                = $planned['posts'];
		$report['conflicts']            = $conflicts;
		$report['dependencies']         = $dependencies;
		// validate + one step per native type + posts + assets + cache.
		$report['estimated_steps']      = 3 + count( $native_plan['types'] ) + count( $planned['posts'] );

		$native_plan['conflict_mode']   = $policy['conflict_mode'];
		$native_plan['allow_overwrite'] = $policy['allow_overwrite'];
		$native_plan['import_images']   = false;

		$report['plan'] = array(
			'format_version'  => 2,
			'path'            => 'native_v2',
			'conflict_mode'   => $policy['conflict_mode'],
			'allow_overwrite' => $policy['allow_overwrite'],
			'allow_sensitive_settings' => $policy['allow_sensitive_settings'],
			'import_images'   => false,
			'native'          => $native_plan,
			'posts'           => $this->preflight_plan_actions( $planned['posts'] ),
			'sidecars'        => array(
				'template_conditions' => ! empty( $manifest['domains']['template_conditions'] ) ? 'review_required' : 'absent',
			),
			'steps'           => array_merge(
				array( 'validate' ),
				$native_plan['types'],
				array( 'posts', 'assets', 'cache' )
			),
		);

		return $this->preflight_finalize_status( $report );
	}

	/**
	 * Plan every indexed post entry without writing anything.
	 *
	 * Determines the planned action per post (create, update, meta_only, or
	 * skip) from read-only post existence lookups and the configured post
	 * type allowlists, and scans the declared meta keys against the meta
	 * allowlist. Schema version 1 meta values are additionally checked for
	 * malformed base64, malformed serialization, and serialized objects.
	 *
	 * @since 1.1.0
	 *
	 * @param ZipArchive $zip            Open zip archive.
	 * @param array      $index_entries  Posts index entries (file/slug/type).
	 * @param string     $prefix         Member path prefix for post payloads.
	 * @param int        $format_version Archive schema version (1 or 2).
	 * @return array|WP_Error Keys: posts, conflicts, security_warnings,
	 *                        omissions, dependencies.
	 */
	private function preflight_plan_posts( $zip, $index_entries, $prefix, $format_version, array $policy = array() ) {
		$result = array(
			'posts'             => array(),
			'conflicts'         => array(),
			'security_warnings' => array(),
			'omissions'         => array(),
			'dependencies'      => array(),
		);

		$meta_allowlist = $this->get_meta_keys();
		$create_types   = $this->get_create_missing_post_types();
		$update_types   = $this->get_update_post_fields_post_types();

		foreach ( (array) $index_entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
				continue;
			}

			$name = $prefix . $entry['file'];
			$raw  = $zip->getFromName( $name );
			if ( false === $raw ) {
				return new WP_Error( 'missing_post_file', sprintf( __( 'Missing post file: %s', 'bricks-ie' ), $name ) );
			}

			$payload = json_decode( $raw, true );
			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'invalid_post', sprintf( __( 'Invalid JSON in %s', 'bricks-ie' ), $name ) );
			}

			$type      = isset( $payload['type'] ) ? (string) $payload['type'] : ( isset( $entry['type'] ) ? (string) $entry['type'] : 'page' );
			$slug      = isset( $payload['slug'] ) ? (string) $payload['slug'] : ( isset( $entry['slug'] ) ? (string) $entry['slug'] : '' );
			$source_id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
			$title     = isset( $payload['title'] ) ? (string) $payload['title'] : '';
			$meta      = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

			$action = 'skip';
			$reason = '';
			$target_id = 0;

			// Draft/pending/private legacy records can legitimately have no slug,
			// but must never be targeted by an existence lookup or created.
			$status = isset( $payload['status'] ) ? (string) $payload['status'] : '';
			if ( '' === $slug && in_array( $status, array( 'draft', 'pending', 'private' ), true ) ) {
				$reason                = 'unsafe_empty_slug';
				$result['omissions'][] = sprintf( __( 'Legacy %1$s record with an empty slug is structurally valid but unsafe and will be skipped.', 'bricks-ie' ), $status );
			} elseif ( ! post_type_exists( $type ) ) {
				$reason                = 'post_type_missing';
				$result['omissions'][] = sprintf(
					/* translators: 1: post type, 2: post slug */
					__( 'Post type "%1$s" is not registered on this site; "%2$s" will be skipped.', 'bricks-ie' ),
					$type,
					$slug
				);
			} else {
				$existing = $this->find_posts_by_slug_type( $slug, $type );

				if ( count( $existing ) > 1 ) {
					$reason                = 'multiple_targets';
					$result['omissions'][] = sprintf(
						/* translators: 1: post type, 2: post slug */
						__( 'Multiple %1$s records use the slug "%2$s"; the target is ambiguous and will be skipped.', 'bricks-ie' ),
						$type,
						$slug
					);
				} elseif ( 1 === count( $existing ) ) {
					$existing_id = (int) $existing[0]->ID;
					$target_id   = $existing_id;
					$updatable   = in_array( $type, $update_types, true );
					$action      = 'replace' === ( isset( $policy['conflict_mode'] ) ? $policy['conflict_mode'] : 'skip' ) ? ( $updatable ? 'update' : 'meta_only' ) : 'skip';

					$result['conflicts'][] = array(
						'domain'     => 'posts',
						'type'       => $type,
						'id'         => $existing_id,
						'label'      => $slug,
						'message'    => sprintf(
							/* translators: 1: post type, 2: post slug */
							__( 'A %1$s with the slug "%2$s" already exists on this site.', 'bricks-ie' ),
							$type,
							$slug
						),
						'resolution' => $action,
					);
				} elseif ( in_array( $type, $create_types, true ) ) {
					$action = 'create';
				} else {
					$reason                = 'create_not_allowed';
					$result['omissions'][] = sprintf(
						/* translators: 1: post type, 2: post slug */
						__( '%1$s "%2$s" does not exist on this site and will not be created by this plugin.', 'bricks-ie' ),
						$type,
						$slug
					);
				}
			}

			$meta_keys     = array();
			$meta_rejected = array();

			foreach ( $meta as $key => $value ) {
				$key = (string) $key;

				if ( ! in_array( $key, $meta_allowlist, true ) ) {
					$meta_rejected[]           = $key;
					$result['security_warnings'][] = sprintf(
						/* translators: 1: meta key, 2: post member name */
						__( 'Post file %2$s carries meta key "%1$s", which is not in the meta allowlist and must not be written.', 'bricks-ie' ),
						$key,
						$name
					);
					continue;
				}

				$meta_keys[] = $key;

				if ( 1 === $format_version ) {
					$check = $this->preflight_check_legacy_meta_value( $value );
					if ( 'ok' !== $check ) {
						$result['security_warnings'][] = sprintf(
							/* translators: 1: problem code, 2: meta key, 3: post member name */
							__( 'Post file %3$s carries a problematic value for meta key "%2$s" (%1$s).', 'bricks-ie' ),
							$check,
							$key,
							$name
						);
					}
				}
			}

			$result['posts'][] = array(
				'file'          => (string) $entry['file'],
				'source_id'     => $source_id,
				'slug'          => $slug,
				'type'          => $type,
				'title'         => $title,
				'action'        => $action,
				'reason'        => $reason,
				'target_id'     => $target_id,
				'meta_keys'     => $meta_keys,
				'meta_rejected' => $meta_rejected,
			);
		}

		return $result;
	}

	/**
	 * Find up to two exact targets so ambiguous slugs are never selected by order.
	 *
	 * @param string $slug Post slug.
	 * @param string $type Post type.
	 * @return array
	 */
	private function find_posts_by_slug_type( $slug, $type ) {
		$posts = get_posts( array(
			'name'           => $slug,
			'post_type'      => $type,
			'post_status'    => 'any',
			'numberposts'    => 2,
			'posts_per_page' => 2,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		$matches = array();
		foreach ( (array) $posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) continue;
			if ( isset( $post->post_name ) && (string) $post->post_name !== (string) $slug ) continue;
			if ( isset( $post->post_type ) && (string) $post->post_type !== (string) $type ) continue;
			$matches[] = $post;
			if ( count( $matches ) >= 2 ) break;
		}

		return $matches;
	}

	/**
	 * Check a schema version 1 base64-encoded serialized meta value read-only.
	 *
	 * Objects are instantiated as __PHP_Incomplete_Class by the
	 * allowed_classes=false restriction and are only reported, never written.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Raw meta value from the archive payload.
	 * @return string One of ok, invalid_base64, invalid_serialized,
	 *                serialized_object.
	 */
	private function preflight_check_legacy_meta_value( $value ) {
		if ( ! is_string( $value ) ) {
			return 'invalid_base64';
		}

		$raw = base64_decode( $value, true );
		if ( false === $raw ) {
			return 'invalid_base64';
		}

		$decoded = @unserialize( $raw, array( 'allowed_classes' => false, 'max_depth' => $this->get_max_meta_depth() ) );

		if ( false === $decoded && 'b:0;' !== trim( $raw ) ) {
			return 'invalid_serialized';
		}

		if ( is_object( $decoded ) ) {
			return 'serialized_object';
		}

		return 'ok';
	}

	/**
	 * Reduce planned posts to compact file => action pairs for the plan.
	 *
	 * @since 1.1.0
	 *
	 * @param array $posts Planned post entries.
	 * @return array
	 */
	private function preflight_plan_actions( $posts ) {
		$actions = array();

		foreach ( (array) $posts as $post ) {
			$actions[] = array(
				'file'      => $post['file'],
				'action'    => $post['action'],
				'target_id' => isset( $post['target_id'] ) ? (int) $post['target_id'] : 0,
			);
		}

		return $actions;
	}

	/**
	 * Build the normalized base report with every contract key present.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $format_version    Archive schema version.
	 * @param string $archive_hash      SHA-256 of the outer archive.
	 * @param array  $manifest          Decoded outer manifest.
	 * @param array  $warnings          General warnings.
	 * @param array  $security_warnings Security warnings.
	 * @param array  $omissions         Omission notes.
	 * @return array
	 */
	private function preflight_base_report( $format_version, $archive_hash, $manifest, $warnings, $security_warnings, $omissions ) {
		return array(
			'status'             => 'ready',
			'format_version'     => (int) $format_version,
			'archive_hash'       => (string) $archive_hash,
			'source_environment' => $this->preflight_source_environment( is_array( $manifest ) ? $manifest : array(), (int) $format_version ),
			'target_environment' => $this->preflight_target_environment( null ),
			'native_domains'     => array(),
			'posts'              => array(),
			'conflicts'          => array(),
			'dependencies'       => array(),
			'omissions'          => array_values( (array) $omissions ),
			'security_warnings'  => array_values( (array) $security_warnings ),
			'warnings'           => array_values( (array) $warnings ),
			'estimated_steps'    => 0,
			'plan'               => array(),
			'blocking'           => array(),
		);
	}

	/**
	 * Build a blocked report with a stable blocking reason.
	 *
	 * @since 1.1.0
	 *
	 * @param int         $format_version Archive schema version.
	 * @param string      $archive_hash   SHA-256 of the outer archive.
	 * @param array       $manifest       Decoded outer manifest.
	 * @param array       $blocking       Blocking reason: code, message, extras.
	 * @param array|null  $native_report  Optional native capability report.
	 * @param array       $warnings       Validated warnings to retain.
	 * @param array       $omissions      Validated omission messages to retain.
	 * @return array
	 */
	private function preflight_blocked_report( $format_version, $archive_hash, $manifest, $blocking, $native_report = null, $warnings = array(), $omissions = array() ) {
		$report                 = $this->preflight_base_report( $format_version, $archive_hash, is_array( $manifest ) ? $manifest : array(), $warnings, array(), $omissions );
		$report['status']       = 'blocked';
		$report['blocking']     = array( $blocking );
		$report['target_environment'] = $this->preflight_target_environment( $native_report );

		return $report;
	}

	/**
	 * Finalize the report status from the collected warnings.
	 *
	 * @since 1.1.0
	 *
	 * @param array $report Report built so far.
	 * @return array
	 */
	private function preflight_finalize_status( array $report ) {
		if ( isset( $report['plan'] ) ) $report['plan_hash'] = $this->calculate_plan_hash( $report['plan'] );
		if ( 'blocked' !== $report['status'] && ( ! empty( $report['warnings'] ) || ! empty( $report['security_warnings'] ) || ! empty( $report['omissions'] ) ) ) {
			$report['status'] = 'warning';
		}

		return $report;
	}

	/**
	 * Convert validated typed omission records into the report's string shape.
	 *
	 * @param array $omissions Validated omission records.
	 * @return array
	 */
	private function preflight_format_validated_omissions( $omissions ) {
		$messages = array();
		foreach ( (array) $omissions as $omission ) {
			if ( is_array( $omission ) && isset( $omission['message'] ) && is_string( $omission['message'] ) && '' !== trim( $omission['message'] ) ) {
				$messages[] = trim( $omission['message'] );
			} elseif ( is_string( $omission ) && '' !== trim( $omission ) ) {
				$messages[] = trim( $omission );
			}
		}

		return array_values( $messages );
	}

	private function calculate_plan_hash( $plan ) {
		return hash( 'sha256', serialize( $this->canonicalize_plan_value( $plan ) ) );
	}

	private function canonicalize_plan_value( $value ) {
		if ( ! is_array( $value ) ) return $value;
		if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) { foreach ( $value as $key => $item ) $value[ $key ] = $this->canonicalize_plan_value( $item ); return $value; }
		ksort( $value, SORT_STRING ); foreach ( $value as $key => $item ) $value[ $key ] = $this->canonicalize_plan_value( $item ); return $value;
	}

	/**
	 * Describe the source environment recorded in the manifest.
	 *
	 * @since 1.1.0
	 *
	 * @param array $manifest       Decoded outer manifest.
	 * @param int   $format_version Archive schema version.
	 * @return array
	 */
	private function preflight_source_environment( $manifest, $format_version ) {
		$bricks = null;

		if ( 2 === $format_version && isset( $manifest['bricks']['version'] ) ) {
			$bricks = $manifest['bricks']['version'];
		} elseif ( isset( $manifest['bricks_version'] ) ) {
			$bricks = $manifest['bricks_version'];
		}

		return array(
			'site_url'          => ! empty( $manifest['site_url'] ) ? esc_url_raw( (string) $manifest['site_url'] ) : '',
			'bricks_version'    => $bricks,
			'plugin_version'    => isset( $manifest['plugin_version'] ) ? $manifest['plugin_version'] : null,
			'wordpress_version' => isset( $manifest['wordpress_version'] ) ? $manifest['wordpress_version'] : null,
			'php_version'       => isset( $manifest['php_version'] ) ? $manifest['php_version'] : null,
		);
	}

	/**
	 * Describe the target environment for the report.
	 *
	 * @since 1.1.0
	 *
	 * @param array|null $native_report Optional native capability report.
	 * @return array
	 */
	private function preflight_target_environment( $native_report = null ) {
		return array(
			'site_url'       => function_exists( 'home_url' ) ? (string) home_url() : '',
			'bricks_version' => $this->get_current_bricks_version(),
			'native'         => is_array( $native_report ) ? $native_report : null,
		);
	}

	/**
	 * Read a class constant from an adapter instance when it is defined.
	 *
	 * @since 1.1.0
	 *
	 * @param object $adapter Adapter instance.
	 * @param string $name    Constant name.
	 * @param mixed  $default Default when the constant is missing.
	 * @return mixed
	 */
	private function preflight_adapter_constant( $adapter, $name, $default = null ) {
		$class = get_class( $adapter );

		if ( defined( $class . '::' . $name ) ) {
			return constant( $class . '::' . $name );
		}

		return $default;
	}

	/**
	 * Start an AJAX-driven import session from an uploaded zip file.
	 *
	 * @return array|WP_Error
	 */
	public function start_import_session() {
		$admin = $this->authorize_current_import_admin();
		if ( is_wp_error( $admin ) ) return $admin;
		return $this->start_import_preflight_session();
	}

	/** Stage an uploaded archive without acquiring the mutation lease. */
	public function start_import_preflight_session( array $request = array() ) {
		$user_id = $this->authorize_current_import_admin();
		if ( is_wp_error( $user_id ) ) return $user_id;

		$this->cleanup_expired_import_sessions();
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

		$placeholder = $temp_file;
		$temp_file = $placeholder . '-' . substr( hash( 'sha256', $file['name'] . microtime( true ) ), 0, 16 ) . '.zip';
		if ( ! @rename( $placeholder, $temp_file ) ) { @unlink( $placeholder ); return new WP_Error( 'temp_file_move_failed', __( 'Could not secure the temporary import path.', 'bricks-ie' ) ); }

		$moved = is_uploaded_file( $file['tmp_name'] )
			? @move_uploaded_file( $file['tmp_name'], $temp_file )
			: @copy( $file['tmp_name'], $temp_file );

		if ( ! $moved ) {
			@unlink( $temp_file ); @unlink( $placeholder );
			return new WP_Error( 'temp_file_move_failed', __( 'Could not store the uploaded import file.', 'bricks-ie' ) );
		}

		$report = $this->preflight( $temp_file, $request );
		if ( is_wp_error( $report ) ) {
			@unlink( $temp_file );
			return $report;
		}

		$session_id             = $this->canonicalize_import_session_id( wp_generate_uuid4() );
		$session_token          = $this->new_secret_token();
		if ( is_wp_error( $session_token ) ) { @unlink( $temp_file ); return $session_token; }
		$archive_hash           = hash_file( 'sha256', $temp_file );
		$state = $this->create_staged_session_state( $session_id, $session_token, $user_id, $temp_file, $archive_hash, $report );
		if ( ! $this->register_import_session( $state ) ) {
			$this->unlink_trusted_temp_file( $temp_file, isset( $state['trusted_temp_dir'] ) ? $state['trusted_temp_dir'] : '' );
			return new WP_Error( 'import_session_registry_failed', __( 'The import session could not be registered. Please try again.', 'bricks-ie' ) );
		}
		if ( ! set_transient( $this->get_import_session_key( $session_id ), $state, HOUR_IN_SECONDS ) ) {
			$this->cas_registry_remove( $session_id );
			$this->unlink_trusted_temp_file( $temp_file, isset( $state['trusted_temp_dir'] ) ? $state['trusted_temp_dir'] : '' );
			return new WP_Error( 'import_session_persistence_failed', __( 'The import session could not be persisted. Please try again.', 'bricks-ie' ) );
		}

		$response = $this->format_import_response(
			$state,
			__( 'Archive validated. Review the preflight report and confirm to begin.', 'bricks-ie' )
		);
		$response['session_token'] = $session_token;
		$response['preflight'] = $report;
		$response['status'] = 'awaiting_confirmation';
		return $response;
	}

	private function create_staged_session_state( $session_id, $token, $user_id, $zip_path, $archive_hash, $report ) {
		$session_id = $this->canonicalize_import_session_id( $session_id );
		$trusted_temp_dir = realpath( dirname( $zip_path ) );
		return array(
			'session_id' => $session_id, 'session_token_hash' => hash( 'sha256', $token ), 'state_version' => self::IMPORT_STATE_VERSION,
			'user_id' => (int) $user_id, 'zip_path' => $zip_path, 'archive_hash' => $archive_hash, 'is_temporary' => true,
			'trusted_temp_dir' => false !== $trusted_temp_dir ? $trusted_temp_dir : '',
			'status' => 'awaiting_confirmation', 'format_version' => (int) $report['format_version'], 'preflight' => $report,
			'step' => 'confirmation', 'done' => false, 'post_index' => array(), 'posts_total' => isset( $report['posts'] ) ? count( $report['posts'] ) : 0, 'posts_processed' => 0,
			'posts_imported' => 0, 'option_names' => array(), 'options_total' => 0, 'options_processed' => 0, 'options_imported' => 0,
			'id_map' => array(), 'imported_post_ids' => array(), 'imported_option_names' => array(), 'source_site_url' => isset( $report['source_environment']['site_url'] ) ? $report['source_environment']['site_url'] : '',
			'assets_dir_prepared' => false, 'completed_steps' => array(), 'total_units' => max( 1, (int) $report['estimated_steps'] ),
			'native_identity_maps' => array(), 'v2_result' => $this->get_v2_result_skeleton(),
			'native_source_ids' => array(),
			'allow_sensitive_settings' => ! empty( $report['plan']['allow_sensitive_settings'] ),
			'conflict_mode' => isset( $report['plan']['conflict_mode'] ) ? $report['plan']['conflict_mode'] : 'skip',
			'allow_overwrite' => ! empty( $report['plan']['allow_overwrite'] ),
		);
	}

	/** Confirm a staged session and acquire its mutation lease without writing content. */
	public function confirm_import_session( $session_id, $session_token, array $confirmation ) {
		$admin = $this->authorize_current_import_admin();
		if ( is_wp_error( $admin ) ) return $admin;
		$session_id = $this->canonicalize_import_session_id( $session_id );
		if ( '' === $session_id ) return new WP_Error( 'missing_session', __( 'Missing import session.', 'bricks-ie' ) );
		$state = get_transient( $this->get_import_session_key( $session_id ) );
		if ( ! is_array( $state ) ) return new WP_Error( 'expired_session', __( 'Import session expired.', 'bricks-ie' ) );
		$auth = $this->authorize_staged_session( $state, $session_id, $session_token );
		if ( is_wp_error( $auth ) ) return $auth;
		$confirmation_check = $this->validate_staged_confirmation( $state, $confirmation );
		if ( is_wp_error( $confirmation_check ) ) return $confirmation_check;
		$plan = $state['preflight']['plan'];
		$policy = array( 'conflict_mode' => $plan['conflict_mode'], 'allow_overwrite' => (bool) $plan['allow_overwrite'], 'allow_sensitive_settings' => ! empty( $plan['allow_sensitive_settings'] ), 'import_images' => false );
		$report = $this->preflight( $state['zip_path'], $policy );
		if ( is_wp_error( $report ) || $report['archive_hash'] !== $state['archive_hash'] ) return new WP_Error( 'archive_changed', __( 'The staged archive changed before confirmation.', 'bricks-ie' ) );
		if ( $report['plan_hash'] !== $state['preflight']['plan_hash'] ) return new WP_Error( 'preflight_plan_changed', __( 'The preflight plan changed before confirmation.', 'bricks-ie' ) );

		if ( ! $this->acquire_processing_slot( $state ) ) return new WP_Error( 'import_in_progress', __( 'This import session is currently being processed.', 'bricks-ie' ) );
		$claim_owned = true;
		try {
			$processing_token = $state['_processing_token'];
			$fresh_state = $this->reread_claimed_import_session( $session_id, $session_token, 'awaiting_confirmation', false );
			if ( is_wp_error( $fresh_state ) ) return $fresh_state;
			$fresh_state['_processing_token'] = $processing_token;
			$state = $fresh_state;
			$confirmation_check = $this->validate_staged_confirmation( $state, $confirmation );
			if ( is_wp_error( $confirmation_check ) ) return $confirmation_check;
			if ( $report['archive_hash'] !== $state['archive_hash'] || $report['plan_hash'] !== $state['preflight']['plan_hash'] ) return new WP_Error( 'import_session_changed', __( 'The import session changed while confirmation was pending.', 'bricks-ie' ) );

			$original_state = $state;
			$user_id = (int) $state['user_id'];
			$owner = $state['session_token_hash'];
			$lease = $this->acquire_import_lease( $owner, $session_id, $user_id, $state['archive_hash'] );
			if ( is_wp_error( $lease ) ) return $lease;
			$state['lease_owner_hash'] = $owner;
			$state['status'] = 'confirmed';
			$state['preflight'] = $report;
			if ( 1 === (int) $state['format_version'] ) {
				$exec = $this->create_import_state( $state['zip_path'], ! empty( $plan['allow_sensitive_settings'] ), $plan['conflict_mode'], ! empty( $plan['allow_overwrite'] ) );
				if ( is_wp_error( $exec ) ) { $this->release_import_lease( $state ); return $exec; }
				$state = array_merge( $exec, array( 'session_id' => $session_id, 'session_token_hash' => $state['session_token_hash'], 'state_version' => self::IMPORT_STATE_VERSION, 'user_id' => $user_id, 'archive_hash' => $state['archive_hash'], 'lease_owner_hash' => $owner, 'is_temporary' => true, 'trusted_temp_dir' => isset( $state['trusted_temp_dir'] ) ? $state['trusted_temp_dir'] : '', 'preflight' => $report, 'status' => 'confirmed', 'conflict_mode' => $plan['conflict_mode'], 'allow_overwrite' => ! empty( $plan['allow_overwrite'] ), '_processing_token' => $processing_token ) );
			} else {
				$state['step'] = 'native';
				$state['v2_native_index'] = 0;
				$state['v2_native_order'] = $this->get_v2_native_order();
			}

			$persisted_state = $state;
			unset( $persisted_state['_processing_token'] );
			if ( ! $this->register_import_session( $persisted_state ) ) {
				$this->release_import_lease( $state );
				return new WP_Error( 'import_session_registry_failed', __( 'The confirmed import session could not be registered; it remains available for retry.', 'bricks-ie' ) );
			}
			if ( ! set_transient( $this->get_import_session_key( $session_id ), $persisted_state, HOUR_IN_SECONDS ) ) {
				$this->register_import_session( $original_state );
				$this->release_import_lease( $state );
				return new WP_Error( 'import_session_persistence_failed', __( 'The confirmed import session could not be persisted; it remains available for retry.', 'bricks-ie' ) );
			}
			if ( ! $this->release_processing_slot( $state ) ) return new WP_Error( 'import_in_progress', __( 'The import confirmation claim could not be released safely.', 'bricks-ie' ) );
			$claim_owned = false;
			$response = $this->format_import_response( $persisted_state, __( 'Import confirmed.', 'bricks-ie' ) );
			$response['status'] = 'confirmed';
			$response['preflight'] = $report;
			return $response;
		} finally {
			if ( $claim_owned ) $this->release_processing_slot( $state );
		}
	}

	private function validate_staged_confirmation( $state, $confirmation ) {
		if ( ! is_array( $state ) || ! isset( $state['status'] ) || 'awaiting_confirmation' !== $state['status'] ) return new WP_Error( 'import_session_changed', __( 'The import session is no longer awaiting confirmation.', 'bricks-ie' ) );
		if ( ! isset( $state['archive_hash'], $state['preflight']['plan_hash'], $state['preflight']['plan'], $state['preflight']['status'] ) ) return new WP_Error( 'preflight_confirmation_required', __( 'The staged preflight data is incomplete.', 'bricks-ie' ) );
		if ( ! isset( $confirmation['archive_hash'], $confirmation['plan_hash'] ) || $confirmation['archive_hash'] !== $state['archive_hash'] || $confirmation['plan_hash'] !== $state['preflight']['plan_hash'] ) return new WP_Error( 'preflight_confirmation_required', __( 'The exact preflight archive and plan hashes are required.', 'bricks-ie' ) );
		$plan = $state['preflight']['plan'];
		if ( ! isset( $plan['conflict_mode'], $plan['allow_overwrite'], $confirmation['plan']['conflict_mode'], $confirmation['plan']['allow_overwrite'] ) || $confirmation['plan']['conflict_mode'] !== $plan['conflict_mode'] || (bool) $confirmation['plan']['allow_overwrite'] !== (bool) $plan['allow_overwrite'] ) return new WP_Error( 'preflight_policy_mismatch', __( 'Confirmation policy does not match preflight.', 'bricks-ie' ) );
		if ( 'blocked' === $state['preflight']['status'] ) return new WP_Error( 'preflight_blocked', __( 'The archive is blocked by preflight.', 'bricks-ie' ) );
		if ( empty( $confirmation['backup_acknowledged'] ) ) return new WP_Error( 'backup_acknowledgement_required', __( 'Backup acknowledgement is required.', 'bricks-ie' ) );
		if ( 'warning' === $state['preflight']['status'] && empty( $confirmation['warnings_acknowledged'] ) ) return new WP_Error( 'warnings_acknowledgement_required', __( 'Import warnings must be acknowledged.', 'bricks-ie' ) );
		return true;
	}

	private function authorize_staged_session( $state, $session_id, $token ) {
		$user_id = $this->authorize_current_import_admin();
		if ( is_wp_error( $user_id ) ) return $user_id;
		$session_id = $this->canonicalize_import_session_id( $session_id );
		$state_session_id = is_array( $state ) && isset( $state['session_id'] ) ? $this->canonicalize_import_session_id( $state['session_id'] ) : '';
		if ( ! is_array( $state ) || '' === $session_id || $session_id !== $state_session_id || ! isset( $state['state_version'], $state['user_id'], $state['session_token_hash'] ) || self::IMPORT_STATE_VERSION !== (int) $state['state_version'] || $user_id !== (int) $state['user_id'] || ! is_string( $token ) || ! hash_equals( (string) $state['session_token_hash'], hash( 'sha256', $token ) ) ) return new WP_Error( 'import_unauthorized', __( 'Import session authorization failed.', 'bricks-ie' ) );
		return true;
	}

	/**
	 * Run the next unit of an AJAX import session.
	 *
	 * @param string $session_id Import session ID.
	 * @return array|WP_Error
	 */
	public function run_import_session_step( $session_id, $session_token = '' ) {
		$admin = $this->authorize_current_import_admin();
		if ( is_wp_error( $admin ) ) return $admin;
		$session_id = $this->canonicalize_import_session_id( $session_id );
		if ( '' === $session_id ) {
			return new WP_Error( 'missing_session', __( 'Missing import session.', 'bricks-ie' ) );
		}

		$key   = $this->get_import_session_key( $session_id );
		$state = get_transient( $key );

		if ( ! is_array( $state ) ) {
			return new WP_Error( 'expired_session', __( 'Import session expired. Please start the import again.', 'bricks-ie' ) );
		}

		$auth = $this->authorize_import_session( $state, $session_id, $session_token );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! $this->acquire_processing_slot( $state ) ) {
			return new WP_Error( 'import_in_progress', __( 'This import session is already being processed.', 'bricks-ie' ) );
		}
		$claim_owned = true;
		try {
			$processing_token = $state['_processing_token'];
			$expected_status = isset( $state['status'] ) ? $state['status'] : null;
			$fresh_state = $this->reread_claimed_import_session( $session_id, $session_token, $expected_status, true );
			if ( is_wp_error( $fresh_state ) ) return $fresh_state;
			$fresh_state['_processing_token'] = $processing_token;
			$state = $fresh_state;

			if ( ! $this->extend_mutation_ownership( $state ) ) return new WP_Error( 'import_lease_lost', __( 'Import ownership could not be extended before mutation.', 'bricks-ie' ) );

			@set_time_limit( 0 );
			wp_raise_memory_limit( 'admin' );

			$state['last_activity'] = time();
			if ( 1 === (int) $state['format_version'] && isset( $state['conflict_mode'] ) ) $this->import_conflict_mode = $state['conflict_mode'];
			$result = 2 === (int) $state['format_version'] ? $this->advance_v2_session_step( $state ) : $this->advance_import_state( $state );

			if ( is_wp_error( $result ) ) {
				$claim_owned = false;
				if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
				return $result;
			}

			if ( ! empty( $result['done'] ) ) {
				$claim_owned = false;
				if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
			} else {
				if ( ! $this->renew_import_lease( $state ) ) {
					if ( 2 === (int) $state['format_version'] ) {
						$state['v2_result']['status'] = 'partial';
						$state['v2_result']['warnings'][] = __( 'Import lease was lost; the session is terminal and will not retry.', 'bricks-ie' );
						$state['done'] = true;
						$result = $this->format_v2_session_response( $state, __( 'Import lease lost.', 'bricks-ie' ), true );
						$claim_owned = false;
						if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
						return $result;
					}
					$claim_owned = false;
					if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
					return new WP_Error( 'import_lease_lost', __( 'Import lease was lost.', 'bricks-ie' ) );
				}
				$persisted_state = $state;
				unset( $persisted_state['_processing_token'] );
				if ( ! set_transient( $key, $persisted_state, HOUR_IN_SECONDS ) ) {
					$claim_owned = false;
					if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
					return new WP_Error( 'import_session_persistence_failed', __( 'Import progress could not be saved; the session was closed to prevent a duplicate mutation.', 'bricks-ie' ) );
				}
				if ( ! $this->register_import_session( $persisted_state ) ) {
					$claim_owned = false;
					if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
					return new WP_Error( 'import_session_registry_failed', __( 'Import progress was saved, but the session was closed because its ownership registry could not be updated.', 'bricks-ie' ) );
				}
				if ( ! $this->release_processing_slot( $state ) ) return new WP_Error( 'import_in_progress', __( 'The import processing claim could not be released safely.', 'bricks-ie' ) );
				$claim_owned = false;
			}

			return $result;
		} catch ( Throwable $throwable ) {
			// The mutation boundary may already have executed. Close the session
			// instead of releasing a replayable claim with stale progress.
			$claim_owned = false;
			if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
			return new WP_Error( 'import_step_failed', $throwable->getMessage() );
		} finally {
			if ( $claim_owned ) $this->release_processing_slot( $state );
		}
	}

	private function advance_v2_session_step( &$state ) {
		$this->source_site_url = isset( $state['source_site_url'] ) ? $state['source_site_url'] : '';
		$this->native_identity_maps = isset( $state['native_identity_maps'] ) && is_array( $state['native_identity_maps'] ) ? $state['native_identity_maps'] : array();
		$this->native_source_ids = isset( $state['native_source_ids'] ) && is_array( $state['native_source_ids'] ) ? $state['native_source_ids'] : array();
		$this->id_map = isset( $state['id_map'] ) && is_array( $state['id_map'] ) ? $state['id_map'] : array();
		if ( ! isset( $state['v2_result'] ) ) $state['v2_result'] = $this->get_v2_result_skeleton();
		$result = &$state['v2_result'];
		$report = $state['preflight'];
		if ( 'native' === $state['step'] ) {
			$order = $state['v2_native_order']; $index = (int) $state['v2_native_index'];
			while ( $index < count( $order ) && empty( $report['plan']['native']['items'][ $order[ $index ] ] ) ) $index++;
			if ( $index >= count( $order ) ) { $state['step'] = 'posts'; return $this->format_v2_session_response( $state, 'Native stages complete.' ); }
			$type = $order[ $index ]; $zip = $this->open_import_zip( $state['zip_path'] ); if ( is_wp_error( $zip ) ) return $zip; $bytes = $zip->getFromName( 'bricks/package.zip' ); $zip->close(); $expected = isset( $report['plan']['native']['package_sha256'] ) ? $report['plan']['native']['package_sha256'] : ''; if ( $expected && ( false === $bytes || ! hash_equals( $expected, hash( 'sha256', $bytes ) ) ) ) { $result['status'] = 'partial'; $result['warnings'][] = __( 'Native package hash verification failed.', 'bricks-ie' ); $state['done'] = true; return $this->format_v2_session_response( $state, 'Native package changed; import stopped.', true ); }
			$adapter = $this->get_transfer_adapter();
			if ( ! is_object( $adapter ) || ! is_callable( array( $adapter, 'import_package' ) ) || ! is_callable( array( $adapter, 'list_items' ) ) ) {
				$result['status'] = 'partial';
				if ( ! in_array( $type, $result['failed'], true ) ) $result['failed'][] = $type;
				$result['warnings'][] = __( 'The verified native transfer adapter is unavailable or incomplete.', 'bricks-ie' );
				$state['done'] = true;
				return $this->format_v2_session_response( $state, __( 'Native import is unavailable; no retry will be attempted.', 'bricks-ie' ), true );
			}
			$native = $adapter->import_package( $bytes, array( 'types' => array( $type ), 'items' => array( $type => $report['plan']['native']['items'][ $type ] ) ), array( 'conflict_mode' => $report['plan']['conflict_mode'], 'allow_overwrite' => (bool) $report['plan']['allow_overwrite'], 'allow_sensitive_settings' => ! empty( $report['plan']['allow_sensitive_settings'] ), 'import_images' => false ) );
			if ( is_wp_error( $native ) || ( is_array( $native ) && isset( $native['success'] ) && false === $native['success'] ) ) {
				$result['status'] = 'partial';
				if ( ! in_array( $type, $result['failed'], true ) ) $result['failed'][] = $type;
				$result['native_result'][ $type ] = is_wp_error( $native ) ? $native->get_error_code() : 'native_failed';
				$state['done'] = true;
				return $this->format_v2_session_response( $state, __( 'Native import failed; no retry will be attempted.', 'bricks-ie' ), true );
			}
			if ( ! empty( $state['_processing_token'] ) && ! $this->extend_mutation_ownership( $state ) ) {
				$result['status'] = 'partial';
				$result['warnings'][] = __( 'Import ownership was lost after a native mutation; no retry will be attempted.', 'bricks-ie' );
				$state['done'] = true;
				return $this->format_v2_session_response( $state, __( 'Native import ownership was lost.', 'bricks-ie' ), true );
			}
			if ( ! is_callable( array( $adapter, 'list_items' ) ) ) {
				$result['status'] = 'partial';
				if ( ! in_array( $type, $result['failed'], true ) ) $result['failed'][] = $type;
				$result['warnings'][] = __( 'The native identity-listing adapter method is no longer callable.', 'bricks-ie' );
				$state['done'] = true;
				return $this->format_v2_session_response( $state, __( 'Native identity listing is unavailable.', 'bricks-ie' ), true );
			}
			$listed = $adapter->list_items( array( $type ) ); if ( is_wp_error( $listed ) ) { $result['status'] = 'partial'; $result['failed'][] = $type; $result['native_result'][ $type ] = $listed->get_error_code(); $state['done'] = true; return $this->format_v2_session_response( $state, 'Native identity listing failed.', true ); }
			$source = isset( $report['plan']['native']['descriptors'][ $type ] ) ? $report['plan']['native']['descriptors'][ $type ] : array(); if ( 'global-queries' === $type ) foreach ( $source as $descriptor ) if ( is_array( $descriptor ) && isset( $descriptor['id'] ) ) $state['native_source_ids']['global-queries'][ (string) $descriptor['id'] ] = true; $result['mappings'][ $type ] = $this->derive_native_identity_map( $source, $listed, $type ); $state['native_identity_maps'][ $type ] = $result['mappings'][ $type ]; $this->native_identity_maps[ $type ] = $result['mappings'][ $type ];
			$result['native_result'][ $type ] = $native; if ( ! in_array( $type, $result['completed_steps'], true ) ) $result['completed_steps'][] = $type; $state['v2_native_index'] = $index + 1; return $this->format_v2_session_response( $state, sprintf( __( 'Imported native stage %s.', 'bricks-ie' ), $type ) );
		}
		if ( 'posts' === $state['step'] ) {
			$post_result = $this->import_v2_posts( $state['zip_path'], isset( $report['posts'] ) ? $report['posts'] : array(), $result, isset( $report['plan']['posts'] ) ? $report['plan']['posts'] : array(), $report['plan']['conflict_mode'], ! empty( $report['plan']['allow_overwrite'] ) );
			if ( is_wp_error( $post_result ) ) {
				$result['status'] = empty( $result['completed_steps'] ) ? 'failed' : 'partial';
				if ( ! in_array( 'posts', $result['failed'], true ) ) $result['failed'][] = 'posts';
				$result['warnings'][] = $post_result->get_error_message();
				$state['done'] = true;
				return $this->format_v2_session_response( $state, __( 'Post import failed; no retry will be attempted.', 'bricks-ie' ), true );
			}
			$result = $post_result;
			$state['posts_processed'] = $state['posts_total'];
			$state['posts_imported'] = $result['posts_imported'];
			$state['id_map'] = $this->id_map;
			$result['mappings']['posts'] = $this->id_map;
			$result['id_remaps'] = count( $this->id_map );
			$state['step'] = 'assets';
			return $this->format_v2_session_response( $state, 'Posts imported.' );
		}
		if ( 'assets' === $state['step'] ) {
			$adapter = $this->get_transfer_adapter();
			$css_verified = false;
			if ( ! is_object( $adapter ) || ! is_callable( array( $adapter, 'regenerate_css_files' ) ) ) {
				$result['status'] = 'partial';
				$this->mark_v2_assets_failed( $result );
				$result['warnings'][] = __( 'CSS regeneration is unavailable because no verified callable adapter method exists.', 'bricks-ie' );
			} elseif ( ! empty( $state['_processing_token'] ) && ! $this->extend_mutation_ownership( $state ) ) {
				$result['status'] = 'partial';
				$this->mark_v2_assets_failed( $result );
				$result['warnings'][] = __( 'Import lease was lost before Bricks CSS regeneration.', 'bricks-ie' );
			} else {
				$css = $adapter->regenerate_css_files();
				if ( is_array( $css ) && array_key_exists( 'success', $css ) && true === $css['success'] ) {
					$css_verified = true;
					if ( ! in_array( 'assets', $result['completed_steps'], true ) ) $result['completed_steps'][] = 'assets';
				} else {
					$result['status'] = 'partial';
					$this->mark_v2_assets_failed( $result );
					$result['warnings'][] = is_wp_error( $css ) ? $css->get_error_message() : __( 'CSS regeneration did not return a verified success result.', 'bricks-ie' );
				}
			}
			$state['done'] = true;
			return $this->format_v2_session_response( $state, $css_verified ? __( 'Import complete.', 'bricks-ie' ) : __( 'Import finished without verified CSS regeneration.', 'bricks-ie' ), true );
		}
		return new WP_Error( 'invalid_import_step', __( 'Invalid v2 import step.', 'bricks-ie' ) );
	}

	private function format_v2_session_response( &$state, $message, $done = false ) {
		$state['completed_steps'] = $state['v2_result']['completed_steps']; $state['total_units'] = count( array_filter( isset( $state['preflight']['plan']['native']['types'] ) ? $state['preflight']['plan']['native']['types'] : array() ) ) + 2;
		$response = $this->format_import_response( $state, $message, $done ); $response['status'] = $state['v2_result']['status']; foreach ( array( 'native_result', 'warnings', 'created', 'updated', 'skipped', 'failed', 'mappings', 'completed_steps' ) as $key ) $response[ $key ] = $state['v2_result'][ $key ]; return $response;
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
	 * Prefers the hardened Bricks_IE_Archive_Validator when available and
	 * falls back to the legacy read helpers otherwise. Both paths enforce the
	 * schema version 1 requirement, the exact Bricks version check, and the
	 * posts index shape before any import step can write.
	 *
	 * @since 1.1.0 Hardened: validator result reuse, schema version gate,
	 *              posts index sanitization.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @return array|WP_Error
	 */
	private function create_import_state( $zip_path, $allow_sensitive_settings = false, $conflict_mode = 'skip', $allow_overwrite = false ) {
		$this->id_map                 = array();
		$this->imported_post_ids      = array();
		$this->imported_option_names  = array();
		$this->source_site_url        = '';
		$this->import_conflict_mode   = 'replace' === $conflict_mode ? 'replace' : 'skip';
		$this->allow_overwrite        = (bool) $allow_overwrite && 'replace' === $this->import_conflict_mode;

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'ZipArchive is not available on this server.', 'bricks-ie' ) );
		}

		if ( ! is_string( $zip_path ) || '' === $zip_path || ! file_exists( $zip_path ) || ! is_file( $zip_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Zip file not found.', 'bricks-ie' ) );
		}

		$archive_hash = hash_file( 'sha256', $zip_path );
		if ( false === $archive_hash ) {
			return new WP_Error( 'file_not_readable', __( 'The zip archive cannot be read.', 'bricks-ie' ) );
		}

		$validation = null;
		$validator  = $this->get_archive_validator();
		if ( ! $validator ) return new WP_Error( 'archive_validator_unavailable', __( 'The hardened archive validator is required for imports.', 'bricks-ie' ) );

		if ( $validator ) {
			$validation = $validator->validate( $zip_path );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( is_array( $validation ) ) {
			$schema_version = isset( $validation['schema_version'] ) ? (int) $validation['schema_version'] : 0;

			if ( self::SCHEMA_VERSION_1 !== $schema_version ) {
				return new WP_Error(
					'unsupported_schema_version',
					__( 'This import path supports schema version 1 archives only. Schema version 2 import orchestration is not available yet.', 'bricks-ie' )
				);
			}

			$manifest = isset( $validation['manifest'] ) && is_array( $validation['manifest'] ) ? $validation['manifest'] : array();

			// Exact Bricks version validation preserved from the 1.0.2 importer.
			// The validator itself only requires that a source version exists;
			// the exact comparison against this site happens here, before any
			// import step can write.
			$version_check = $this->check_exact_bricks_version( $manifest );
			if ( is_wp_error( $version_check ) ) {
				return $version_check;
			}

			$post_index = $this->sanitize_post_index( isset( $validation['posts_index'] ) ? $validation['posts_index'] : array() );
			if ( is_wp_error( $post_index ) ) {
				return $post_index;
			}

			$option_names = $this->filter_option_names_from_option_files( isset( $validation['option_files'] ) ? $validation['option_files'] : array() );
		} else {
			// Legacy fallback when Bricks_IE_Archive_Validator is not loaded.
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

			if ( self::SCHEMA_VERSION_1 !== (int) $manifest['version'] ) {
				$zip->close();
				return new WP_Error(
					'unsupported_schema_version',
					__( 'This import path supports schema version 1 archives only. Schema version 2 import orchestration is not available yet.', 'bricks-ie' )
				);
			}

			// Exact Bricks version validation preserved from the 1.0.2 importer,
			// checked before any payload read on the legacy path.
			$version_check = $this->check_exact_bricks_version( $manifest );
			if ( is_wp_error( $version_check ) ) {
				$zip->close();
				return $version_check;
			}

			$post_index = $this->get_post_index_from_zip( $zip );
			if ( is_wp_error( $post_index ) ) {
				$zip->close();
				return $post_index;
			}

			$option_names = $this->get_importable_option_names_from_zip( $zip );
			$zip->close();
		}

		$this->source_site_url = ! empty( $manifest['site_url'] ) ? esc_url_raw( (string) $manifest['site_url'] ) : '';
		$current_hash = hash_file( 'sha256', $zip_path );
		if ( false === $current_hash || ! hash_equals( $archive_hash, $current_hash ) ) {
			return new WP_Error( 'archive_changed', __( 'The archive changed while it was being validated.', 'bricks-ie' ) );
		}

		$total_units = 1 + count( $post_index ) + count( $option_names ) + 5;

		return array(
			'session_id'          => '',
			'is_temporary'        => false,
			'zip_path'            => $zip_path,
			'archive_hash'        => $archive_hash,
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
			'imported_option_names' => array(),
			'source_site_url'     => $this->source_site_url,
			'assets_dir_prepared' => false,
			'completed_steps'     => array( 'validate' ),
			'total_units'         => $total_units,
			'allow_sensitive_settings' => (bool) $allow_sensitive_settings,
			'conflict_mode'       => $this->import_conflict_mode,
			'allow_overwrite'     => $this->allow_overwrite,
		);
	}

	/**
	 * Verify that a staged schema-v1 archive still matches its validated bytes.
	 *
	 * @param array $state Import state.
	 * @return true|WP_Error
	 */
	private function verify_import_archive_hash( $state ) {
		if ( ! is_array( $state ) || empty( $state['zip_path'] ) || empty( $state['archive_hash'] ) || ! is_string( $state['archive_hash'] ) ) {
			return new WP_Error( 'archive_changed', __( 'The validated archive hash is missing from the import state.', 'bricks-ie' ) );
		}
		if ( ! is_string( $state['zip_path'] ) || ! is_file( $state['zip_path'] ) || ! is_readable( $state['zip_path'] ) ) {
			return new WP_Error( 'archive_changed', __( 'The validated archive is no longer readable.', 'bricks-ie' ) );
		}

		$current_hash = hash_file( 'sha256', $state['zip_path'] );
		if ( false === $current_hash || ! hash_equals( $state['archive_hash'], $current_hash ) ) {
			return new WP_Error( 'archive_changed', __( 'The archive changed after validation; import stopped before further writes.', 'bricks-ie' ) );
		}

		return true;
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
		$archive_check = $this->verify_import_archive_hash( $state );
		if ( is_wp_error( $archive_check ) ) return $archive_check;

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
				// Intentional no-op since 1.1.0 (T6): imported code is never
				// auto-signed or auto-approved. The stage is preserved so AJAX
				// progress responses keep their existing shape.
				$this->mark_import_step_completed( $state, 'signatures' );
				$state['step'] = 'cache';
				return $this->format_import_response( $state, $this->get_code_approval_required_message() );

			case 'cache':
				$this->run_scoped_cache_cleanup();
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
	 * @since 1.1.0 Hardened: depth-limited JSON decode and strict entry shape.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @return array|WP_Error
	 */
	private function get_post_index_from_zip( $zip ) {
		$index_raw = $zip->getFromName( 'posts/index.json' );
		if ( false === $index_raw ) {
			return array();
		}

		$index = json_decode( $index_raw, true, $this->get_max_json_depth() );
		if ( ! is_array( $index ) ) {
			return new WP_Error( 'invalid_index', __( 'Invalid posts/index.json in archive.', 'bricks-ie' ) );
		}

		return $this->sanitize_post_index( $index );
	}

	/**
	 * Validate the shape of a decoded posts index fail-closed.
	 *
	 * Mirrors the archive validator: every entry must be an array with a safe
	 * JSON file name, optional string slug/type, no duplicate files, and the
	 * total entry count is bounded. Malformed entries abort the import instead
	 * of being silently skipped as in 1.0.2.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $index Decoded posts index.
	 * @return array|WP_Error Sanitized list of index entries.
	 */
	private function sanitize_post_index( $index ) {
		if ( ! is_array( $index ) ) {
			return new WP_Error( 'invalid_index', __( 'Invalid posts/index.json in archive.', 'bricks-ie' ) );
		}

		$max_entries = $this->get_max_index_entries();
		if ( count( $index ) > $max_entries ) {
			return new WP_Error(
				'too_many_entries',
				sprintf(
					/* translators: 1: number of index entries, 2: limit */
					__( 'The posts index contains %1$d entries, exceeding the limit of %2$d.', 'bricks-ie' ),
					count( $index ),
					$max_entries
				)
			);
		}

		$entries = array();
		$files   = array();

		foreach ( $index as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['file'] ) || ! is_string( $entry['file'] ) || ! preg_match( '/^[A-Za-z0-9_\-]+\.json$/', $entry['file'] ) ) {
				return new WP_Error( 'invalid_index', __( 'Posts index entries must reference a valid JSON file name.', 'bricks-ie' ) );
			}

			if ( isset( $files[ $entry['file'] ] ) ) {
				return new WP_Error( 'invalid_index', sprintf( __( 'Duplicate posts index entry: %s', 'bricks-ie' ), $entry['file'] ) );
			}

		if ( ! isset( $entry['slug'] ) || ! is_string( $entry['slug'] ) ) {
				return new WP_Error( 'invalid_index', sprintf( __( 'Posts index entry %s has an invalid slug.', 'bricks-ie' ), $entry['file'] ) );
			}

			if ( empty( $entry['type'] ) || ! is_string( $entry['type'] ) ) {
				return new WP_Error( 'invalid_index', sprintf( __( 'Posts index entry %s has an invalid type.', 'bricks-ie' ), $entry['file'] ) );
			}

			$base      = substr( $entry['file'], 0, -5 );
			$separator = strpos( $base, '__' );
			if ( false === $separator || 0 === $separator ) {
				return new WP_Error( 'invalid_index', sprintf( __( 'Posts index entry %s has an invalid file name.', 'bricks-ie' ), $entry['file'] ) );
			}

			$file_type = substr( $base, 0, $separator );
			$file_slug = substr( $base, $separator + 2 );
			if ( $file_type !== $entry['type'] || $file_slug !== $entry['slug'] ) {
				return new WP_Error( 'index_file_mismatch', sprintf( __( 'Posts index entry %s does not match its type and slug.', 'bricks-ie' ), $entry['file'] ) );
			}

			$files[ $entry['file'] ] = true;
			$entries[]               = $entry;
		}

		return $entries;
	}

	/**
	 * Derive allowlisted option names from validated options/*.json members.
	 *
	 * Keeps the exact semantics of get_importable_option_names_from_zip():
	 * names are returned in allowlist order and only when the member exists.
	 *
	 * @since 1.1.0
	 *
	 * @param array $option_files Option member names (options/*.json).
	 * @return array Option names present in the archive and in the allowlist.
	 */
	private function filter_option_names_from_option_files( $option_files ) {
		$present = array();

		foreach ( (array) $option_files as $file ) {
			if ( ! is_string( $file ) || 0 !== strpos( $file, 'options/' ) || '.json' !== substr( $file, -5 ) ) {
				continue;
			}

			$present[ substr( $file, strlen( 'options/' ), -5 ) ] = true;
		}

		$option_names = array();

		foreach ( $this->get_option_names() as $name ) {
			if ( isset( $present[ $name ] ) ) {
				$option_names[] = $name;
			}
		}

		return $option_names;
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
	 * Hardened in 1.1.0 (T6): the index entry shape, member size, payload
	 * JSON, index/payload consistency, post type allowlists, and every meta
	 * key/value are validated and decoded before the first write happens. No
	 * archive-controlled key can reach delete_post_meta()/add_post_meta()
	 * outside the bricks_ie_meta_keys allowlist, and no malformed, oversized,
	 * or object-bearing payload can reach the database.
	 *
	 * @since 1.1.0 Hardened mutation path.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @param array      $entry Post index entry.
	 * @return bool|WP_Error True when a post was imported, false when skipped.
	 */
	private function import_post_entry( $zip, $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
			return new WP_Error( 'invalid_index', __( 'Posts index entries must reference a valid JSON file name.', 'bricks-ie' ) );
		}

		if ( ! preg_match( '/^[A-Za-z0-9_\-]+\.json$/', $entry['file'] ) ) {
			return new WP_Error( 'invalid_index', sprintf( __( 'Posts index entries must reference a valid JSON file name: %s', 'bricks-ie' ), $entry['file'] ) );
		}

		if ( ! isset( $entry['slug'] ) || ! is_string( $entry['slug'] ) || empty( $entry['type'] ) || ! is_string( $entry['type'] ) ) {
			return new WP_Error( 'invalid_index', sprintf( __( 'Posts index entry %s must include a type and slug.', 'bricks-ie' ), $entry['file'] ) );
		}

		$file_base = substr( $entry['file'], 0, -5 );
		$separator = strpos( $file_base, '__' );
		if ( false === $separator || 0 === $separator || substr( $file_base, 0, $separator ) !== $entry['type'] || substr( $file_base, $separator + 2 ) !== $entry['slug'] ) {
			return new WP_Error( 'index_file_mismatch', sprintf( __( 'Posts index entry %s does not match its type and slug.', 'bricks-ie' ), $entry['file'] ) );
		}

		$member = 'posts/' . $entry['file'];

		$stat = $zip->statName( $member );
		if ( false !== $stat && isset( $stat['size'] ) && (int) $stat['size'] > $this->get_max_archive_member_size() ) {
			return new WP_Error(
				'entry_too_large',
				sprintf(
					/* translators: 1: archive entry name, 2: entry size in bytes, 3: limit in bytes */
					__( 'Archive entry %1$s is %2$d bytes uncompressed, exceeding the %3$d byte limit.', 'bricks-ie' ),
					$member,
					(int) $stat['size'],
					$this->get_max_archive_member_size()
				)
			);
		}

		$post_raw = $zip->getFromName( $member );
		if ( false === $post_raw ) {
			return new WP_Error( 'missing_post', sprintf( __( 'Missing post file: %s', 'bricks-ie' ), $entry['file'] ) );
		}

		$post_data = json_decode( $post_raw, true, $this->get_max_json_depth() );
		if ( ! is_array( $post_data ) ) {
			return new WP_Error( 'invalid_post', sprintf( __( 'Invalid JSON in posts/%s', 'bricks-ie' ), $entry['file'] ) );
		}

		$type      = isset( $post_data['type'] ) ? $post_data['type'] : ( isset( $entry['type'] ) ? $entry['type'] : '' );
		$slug      = isset( $post_data['slug'] ) ? $post_data['slug'] : ( isset( $entry['slug'] ) ? $entry['slug'] : '' );
		$source_id = isset( $post_data['id'] ) ? (int) $post_data['id'] : 0;

		if ( ! is_string( $type ) || ! is_string( $slug ) || '' === $type || ( '' === $slug && ! in_array( isset( $post_data['status'] ) ? (string) $post_data['status'] : '', array( 'draft', 'pending', 'private' ), true ) ) ) {
			return new WP_Error( 'invalid_post', sprintf( __( 'Post payload in %s is missing a valid type or slug.', 'bricks-ie' ), $member ) );
		}

		// Index/payload consistency, mirroring the archive validator.
		if ( isset( $entry['type'] ) && $type !== (string) $entry['type'] ) {
			return new WP_Error( 'index_payload_mismatch', sprintf( __( 'Post payload %s does not match its posts index entry.', 'bricks-ie' ), $member ) );
		}

		if ( isset( $entry['slug'] ) && $slug !== (string) $entry['slug'] ) {
			return new WP_Error( 'index_payload_mismatch', sprintf( __( 'Post payload %s does not match its posts index entry.', 'bricks-ie' ), $member ) );
		}

		if ( '' === $type || ( '' === $slug && ! in_array( isset( $post_data['status'] ) ? (string) $post_data['status'] : '', array( 'draft', 'pending', 'private' ), true ) ) ) {
			return new WP_Error( 'invalid_post', sprintf( __( 'Post payload in %s is missing a valid type or slug.', 'bricks-ie' ), $member ) );
		}
		if ( '' === $slug ) {
			return false;
		}

		if ( ! post_type_exists( $type ) ) {
			return false;
		}

		// A registered post type is not automatically importable. Reject/skip
		// types outside both mutation allowlists before decoding meta or doing
		// any existence lookup, deletion, or write.
		$allowed_types = array_unique( array_merge( $this->get_create_missing_post_types(), $this->get_update_post_fields_post_types() ) );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return false;
		}

		// Enforce the meta allowlist and decode every value strictly before
		// any post/meta write happens.
		$meta = isset( $post_data['meta'] ) ? $post_data['meta'] : array();

		if ( ! is_array( $meta ) ) {
			return new WP_Error( 'invalid_post', sprintf( __( 'Post meta in %s must be a JSON object.', 'bricks-ie' ), $member ) );
		}

		$meta_allowlist = $this->get_meta_keys();
		$staged_meta    = array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( ! in_array( $key, $meta_allowlist, true ) ) {
				return new WP_Error(
					'forbidden_meta_key',
					sprintf(
						/* translators: 1: meta key, 2: post member name */
						__( 'Post file %2$s carries meta key "%1$s", which is not in the meta allowlist and will not be written.', 'bricks-ie' ),
						$key,
						$member
					)
				);
			}

			if ( array_key_exists( $key, $staged_meta ) ) {
				continue;
			}

			$decoded = $this->decode_legacy_meta_value( $value, $key, $member );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			$staged_meta[ $key ] = $decoded;
		}

		// Only validated data remains below this line; writes start here.
		$existing = $this->find_posts_by_slug_type( $slug, $type );

		if ( count( $existing ) > 1 ) {
			return new WP_Error( 'ambiguous_post_target', sprintf( __( 'Multiple %1$s records use the slug "%2$s"; refusing to select one for import.', 'bricks-ie' ), $type, $slug ) );
		}

		if ( 1 === count( $existing ) ) {
			$post_id = (int) $existing[0]->ID;
			if ( 'replace' !== $this->import_conflict_mode || ! $this->allow_overwrite ) {
				if ( $source_id ) $this->id_map[ $source_id ] = $post_id;
				return false;
			}

			if ( in_array( $type, $this->get_update_post_fields_post_types(), true ) ) {
				wp_update_post( array(
					'ID'          => $post_id,
					'post_title'  => isset( $post_data['title'] ) ? (string) $post_data['title'] : $existing[0]->post_title,
					'post_status' => isset( $post_data['status'] ) ? (string) $post_data['status'] : $existing[0]->post_status,
				) );
			}
		} else {
			if ( ! in_array( $type, $this->get_create_missing_post_types(), true ) ) {
				return false;
			}

			$post_id = wp_insert_post( array(
				'post_name'   => $slug,
				'post_type'   => $type,
				'post_status' => isset( $post_data['status'] ) ? (string) $post_data['status'] : 'publish',
				'post_title'  => isset( $post_data['title'] ) ? (string) $post_data['title'] : '',
			) );
			if ( is_wp_error( $post_id ) ) {
				return new WP_Error( 'insert_failed', sprintf( __( 'Failed to create %s/%s: %s', 'bricks-ie' ), $type, $slug, $post_id->get_error_message() ) );
			}

			$post_id = (int) $post_id;
		}

		if ( $source_id && $source_id !== $post_id ) {
			$this->id_map[ $source_id ] = $post_id;
		}

		$this->imported_post_ids[] = (int) $post_id;

		foreach ( $meta_allowlist as $key ) {
			delete_post_meta( $post_id, $key );
		}

		foreach ( $staged_meta as $key => $value ) {
			add_post_meta( $post_id, $key, $value );
		}

		return true;
	}

	/**
	 * Strictly decode one schema version 1 base64-encoded serialized meta value.
	 *
	 * Hardening applied before any value can be written (T6):
	 * - string input required; strict base64_decode();
	 * - decoded size limit;
	 * - unserialize() with allowed_classes=false;
	 * - malformed serialization rejected, with an explicit exception for the
	 *   valid serialized false value (b:0;), which unserialize() reports as
	 *   false just like malformed input;
	 * - recursive rejection of objects (including __PHP_Incomplete_Class) and
	 *   resources, plus depth and element-count limits.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $value    Raw meta value from the archive payload.
	 * @param string $meta_key Meta key (for error messages).
	 * @param string $member   Post member name (for error messages).
	 * @return mixed|WP_Error Decoded scalar/array value or WP_Error.
	 */
	private function decode_legacy_meta_value( $value, $meta_key, $member ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error(
				'invalid_base64',
				sprintf(
					/* translators: 1: meta key, 2: post member name */
					__( 'Post file %2$s carries a non-string value for meta key "%1$s"; expected base64-encoded serialized data.', 'bricks-ie' ),
					$meta_key,
					$member
				)
			);
		}

		$raw = base64_decode( $value, true );

		if ( false === $raw ) {
			return new WP_Error(
				'invalid_base64',
				sprintf(
					/* translators: 1: meta key, 2: post member name */
					__( 'Post file %2$s carries malformed base64 data for meta key "%1$s".', 'bricks-ie' ),
					$meta_key,
					$member
				)
			);
		}

		$max_size = $this->get_max_decoded_meta_size();
		if ( strlen( $raw ) > $max_size ) {
			return new WP_Error(
				'decoded_value_too_large',
				sprintf(
					/* translators: 1: decoded size in bytes, 2: meta key, 3: post member name, 4: limit in bytes */
					__( 'Post file %3$s carries a decoded value of %1$d bytes for meta key "%2$s", exceeding the %4$d byte limit.', 'bricks-ie' ),
					strlen( $raw ),
					$meta_key,
					$member,
					$max_size
				)
			);
		}

		$decoded = @unserialize( $raw, array( 'allowed_classes' => false, 'max_depth' => $this->get_max_meta_depth() ) );

		// unserialize() returns false both for the valid serialized false
		// value (b:0;) and for malformed input; only b:0; is accepted.
		if ( false === $decoded && 'b:0;' !== trim( $raw ) ) {
			return new WP_Error(
				'invalid_serialized',
				sprintf(
					/* translators: 1: meta key, 2: post member name */
					__( 'Post file %2$s carries malformed serialized data for meta key "%1$s".', 'bricks-ie' ),
					$meta_key,
					$member
				)
			);
		}

		$structure_check = $this->validate_decoded_meta_structure( $decoded, $meta_key, $member );
		if ( is_wp_error( $structure_check ) ) {
			return $structure_check;
		}

		return $decoded;
	}

	/**
	 * Recursively validate a decoded schema 1 meta value.
	 *
	 * Rejects objects (allowed_classes=false maps them to
	 * __PHP_Incomplete_Class, which is still an object), resources, nesting
	 * deeper than the depth limit, and structures with more than the element
	 * limit. The depth limit also bounds reference cycles unserialize() may
	 * produce.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $decoded  Decoded meta value.
	 * @param string $meta_key Meta key (for error messages).
	 * @param string $member   Post member name (for error messages).
	 * @return true|WP_Error
	 */
	private function validate_decoded_meta_structure( $decoded, $meta_key, $member ) {
		$this->meta_structure_error    = '';
		$this->meta_structure_elements = 0;

		$this->walk_decoded_meta_structure( $decoded, 0 );

		if ( '' === $this->meta_structure_error ) {
			return true;
		}

		$code = $this->meta_structure_error;

		switch ( $code ) {
			case 'serialized_object':
				$message = sprintf(
					/* translators: 1: meta key, 2: post member name */
					__( 'Post file %2$s carries a serialized object in meta key "%1$s"; objects are not allowed in schema version 1 imports.', 'bricks-ie' ),
					$meta_key,
					$member
				);
				break;

			case 'serialized_resource':
				$message = sprintf(
					/* translators: 1: meta key, 2: post member name */
					__( 'Post file %2$s carries a serialized resource in meta key "%1$s"; resources are not allowed in schema version 1 imports.', 'bricks-ie' ),
					$meta_key,
					$member
				);
				break;

			case 'meta_structure_too_deep':
				$message = sprintf(
					/* translators: 1: meta key, 2: post member name, 3: depth limit */
					__( 'Post file %2$s carries a structure deeper than %3$d levels in meta key "%1$s".', 'bricks-ie' ),
					$meta_key,
					$member,
					$this->get_max_meta_depth()
				);
				break;

			default:
				$message = sprintf(
					/* translators: 1: meta key, 2: post member name, 3: element limit */
					__( 'Post file %2$s carries more than %3$d array elements in meta key "%1$s".', 'bricks-ie' ),
					$meta_key,
					$member,
					$this->get_max_meta_elements()
				);
				break;
		}

		return new WP_Error( $code, $message );
	}

	/**
	 * Recursive walker behind validate_decoded_meta_structure().
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $data  Current node.
	 * @param int   $depth Current nesting depth.
	 */
	private function walk_decoded_meta_structure( $data, $depth ) {
		if ( '' !== $this->meta_structure_error ) {
			return;
		}

		if ( is_object( $data ) ) {
			$this->meta_structure_error = 'serialized_object';
			return;
		}

		if ( is_resource( $data ) ) {
			$this->meta_structure_error = 'serialized_resource';
			return;
		}

		if ( ! is_array( $data ) ) {
			return;
		}

		if ( $depth >= $this->get_max_meta_depth() ) {
			$this->meta_structure_error = 'meta_structure_too_deep';
			return;
		}

		foreach ( $data as $value ) {
			$this->meta_structure_elements++;

			if ( $this->meta_structure_elements > $this->get_max_meta_elements() ) {
				$this->meta_structure_error = 'meta_structure_too_many_elements';
				return;
			}

			$this->walk_decoded_meta_structure( $value, $depth + 1 );

			if ( '' !== $this->meta_structure_error ) {
				return;
			}
		}
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
	 * Hardened in 1.1.0 (T6): the option allowlist and the member name shape
	 * are enforced again right here before any read or write, even though
	 * callers already intersect with the allowlist. No archive key can reach
	 * update_option() outside bricks_ie_get_option_names().
	 *
	 * @since 1.1.0 Hardened mutation path.
	 *
	 * @param ZipArchive $zip Open zip archive.
	 * @param string     $name Option name.
	 * @return int|WP_Error
	 */
	private function import_option_name( $zip, $name ) {
		if ( ! is_string( $name ) || '' === $name || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $name ) || ! in_array( $name, $this->get_option_names(), true ) ) {
			return new WP_Error(
				'forbidden_option',
				sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" is not in the import allowlist and will not be written.', 'bricks-ie' ),
					is_string( $name ) ? $name : ''
				)
			);
		}

		$member = 'options/' . $name . '.json';

		$stat = $zip->statName( $member );
		if ( false === $stat ) {
			return 0;
		}

		if ( isset( $stat['size'] ) && (int) $stat['size'] > $this->get_max_archive_member_size() ) {
			return new WP_Error(
				'entry_too_large',
				sprintf(
					/* translators: 1: archive entry name, 2: entry size in bytes, 3: limit in bytes */
					__( 'Archive entry %1$s is %2$d bytes uncompressed, exceeding the %3$d byte limit.', 'bricks-ie' ),
					$member,
					(int) $stat['size'],
					$this->get_max_archive_member_size()
				)
			);
		}

		$content = $zip->getFromName( $member );
		if ( false === $content ) {
			return 0;
		}

		$value = json_decode( $content, true, $this->get_max_json_depth() );
		if ( null === $value && json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', sprintf( __( 'Invalid JSON in options/%s.json: %s', 'bricks-ie' ), $name, json_last_error_msg() ) );
		}
		if ( 'bricks_global_settings' === $name ) {
			if ( ! is_array( $value ) ) return new WP_Error( 'invalid_global_settings', __( 'The bricks_global_settings payload must be an array.', 'bricks-ie' ) );
			if ( ! $this->allow_sensitive_settings ) {
				$value = $this->strip_legacy_sensitive_settings( $value );
			}
		}

		$missing  = new stdClass();
		$existing = get_option( $name, $missing );
		if ( $missing !== $existing && ( 'replace' !== $this->import_conflict_mode || ! $this->allow_overwrite ) ) {
			return 0;
		}

		update_option( $name, $value, false );
		$this->imported_option_names[] = $name;

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
	 * Get the maximum uncompressed bytes for one post/option archive member.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function get_max_archive_member_size() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_max_archive_member_size', self::MAX_ARCHIVE_MEMBER_SIZE ) );
	}

	/**
	 * Get the maximum decoded bytes for one schema 1 meta value.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function get_max_decoded_meta_size() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_max_decoded_meta_size', self::MAX_DECODED_META_SIZE ) );
	}

	/**
	 * Get the maximum nesting depth for decoded schema 1 meta structures.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function get_max_meta_depth() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_max_meta_depth', self::MAX_META_DEPTH ) );
	}

	/**
	 * Get the maximum total array elements for one decoded schema 1 meta value.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function get_max_meta_elements() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_max_meta_elements', self::MAX_META_ELEMENTS ) );
	}

	/**
	 * Get the maximum number of posts index entries.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function get_max_index_entries() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_max_index_entries', self::MAX_INDEX_ENTRIES ) );
	}

	/**
	 * Get the maximum JSON nesting depth for archive member decoding.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	private function get_max_json_depth() {
		return max( 1, (int) apply_filters( 'bricks_ie_import_max_json_depth', self::MAX_JSON_DEPTH ) );
	}

	private function get_legacy_sensitive_settings_keys() {
		$fallback = array(
			'adobeFontsProjectId', 'apiKeyUnsplash', 'apiKeyGoogleMaps', 'apiKeyGoogleRecaptcha',
			'apiSecretKeyGoogleRecaptcha', 'apiKeyHCaptcha', 'apiSecretKeyHCaptcha', 'apiKeyTurnstile',
			'apiSecretKeyTurnstile', 'apiKeyMailchimp', 'apiKeySendgrid', 'facebookAppId',
			'instagramAccessToken', 'executeCodeEnabled', 'customCss', 'customScriptsHeader',
			'customScriptsBodyHeader', 'customScriptsBodyFooter', 'myTemplatesPassword',
			'remoteTemplatesPassword',
			// Conservative aliases used by older Bricks releases.
			'apiKey', 'apiKeys', 'apiSecretKey', 'googleMapsAPIKey', 'recaptchaSiteKey',
			'recaptchaSecretKey', 'customCode', 'customCSS', 'customJS', 'codeExecution',
			'executeCode', 'allowCodeExecution', 'codeExecutionEnabled',
		);
		if ( function_exists( 'bricks_ie_get_legacy_sensitive_settings_keys' ) ) {
			$keys = bricks_ie_get_legacy_sensitive_settings_keys();
			return $this->mandatory_legacy_sensitive_keys( apply_filters( 'bricks_ie_legacy_sensitive_settings_keys', $keys ) );
		}
		return $this->mandatory_legacy_sensitive_keys( apply_filters( 'bricks_ie_legacy_sensitive_settings_keys', $fallback ) );
	}

	private function mandatory_legacy_sensitive_keys( $keys ) {
		$mandatory = array( 'remoteTemplatesPassword', 'myTemplatesPassword', 'password', 'pass' );
		return array_values( array_unique( array_merge( is_array( $keys ) ? $keys : array(), $mandatory ) ) );
	}

	private function legacy_settings_contain_nested_secrets( $value, $under_remote = false ) {
		if ( ! is_array( $value ) ) return false;
		foreach ( $value as $key => $child ) {
			$key = (string) $key;
			if ( $under_remote && in_array( $key, array( 'password', 'pass', 'remoteTemplatesPassword' ), true ) ) return true;
			if ( $this->legacy_settings_contain_nested_secrets( $child, $under_remote || 'remoteTemplates' === $key ) ) return true;
		}
		return false;
	}

	private function strip_legacy_sensitive_settings( $value, $under_remote = false ) {
		if ( ! is_array( $value ) ) return $value;
		$result = array();
		foreach ( $value as $key => $child ) {
			$key_string = (string) $key;
			if ( in_array( $key_string, $this->get_legacy_sensitive_settings_keys(), true ) || ( $under_remote && in_array( $key_string, array( 'password', 'pass', 'remoteTemplatesPassword' ), true ) ) ) continue;
			$result[ $key ] = $this->strip_legacy_sensitive_settings( $child, $under_remote || 'remoteTemplates' === $key_string );
		}
		return $result;
	}

	/**
	 * Get the transient key for an import session.
	 *
	 * @param string $session_id Import session ID.
	 * @return string
	 */
	private function get_import_session_key( $session_id ) {
		return 'bricks_ie_import_' . $this->canonicalize_import_session_id( $session_id );
	}

	private function canonicalize_import_session_id( $session_id ) {
		return sanitize_key( (string) $session_id );
	}

	private function new_secret_token() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'secure_token_failed', __( 'A secure import token could not be generated.', 'bricks-ie' ) );
		}
	}

	/**
	 * Read an option directly from the options table, bypassing object caches.
	 *
	 * $found is null when the database contract is unavailable, false when no
	 * row exists, and true when a row was read successfully.
	 */
	private function read_option_from_database( $name, &$found = null ) {
		global $wpdb;
		$found = null;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) || ! isset( $wpdb->options ) ) return false;
		$sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name );
		if ( ! is_string( $sql ) || '' === $sql ) return false;
		$raw = $wpdb->get_var( $sql );
		if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) return false;
		if ( null === $raw ) {
			$found = false;
			return false;
		}
		$found = true;
		if ( function_exists( 'maybe_unserialize' ) ) return maybe_unserialize( $raw );
		if ( ! is_string( $raw ) ) return $raw;
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
		return false !== $value || 'b:0;' === $raw ? $value : $raw;
	}

	private function serialize_option_value( $value ) {
		if ( function_exists( 'maybe_serialize' ) ) return maybe_serialize( $value );
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	private function invalidate_option_cache( $name ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) return;
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function acquire_import_lease( $owner_hash, $session_id, $user_id, $archive_hash ) {
		$session_id = $this->canonicalize_import_session_id( $session_id );
		if ( '' === $session_id ) return new WP_Error( 'missing_session', __( 'Missing import session.', 'bricks-ie' ) );
		$now  = time();
		$lock = array( 'owner_token_hash' => $owner_hash, 'session_id' => $session_id, 'user_id' => (int) $user_id, 'archive_hash' => $archive_hash, 'acquired_at' => $now, 'expires_at' => $now + self::IMPORT_LEASE_SECONDS, 'recover_after' => $now + self::IMPORT_STALE_RECOVERY_SECONDS );
		if ( add_option( self::IMPORT_LOCK_OPTION, $lock, '', 'no' ) ) return true;

		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$current = $this->read_option_from_database( self::IMPORT_LOCK_OPTION, $found );
			if ( true !== $found || ! is_array( $current ) || empty( $current['expires_at'] ) ) break;
			$recover_after = isset( $current['recover_after'] ) ? (int) $current['recover_after'] : (int) $current['expires_at'] + self::IMPORT_STALE_RECOVERY_SECONDS;
			if ( $recover_after >= $now ) break;
			if ( $this->compare_swap_option( self::IMPORT_LOCK_OPTION, $current, $lock ) ) return true;
		}
		return new WP_Error( 'import_busy', __( 'Another import is currently in progress.', 'bricks-ie' ) );
	}

	private function renew_import_lease( $state, $duration = self::IMPORT_LEASE_SECONDS ) {
		if ( empty( $state['lease_owner_hash'] ) ) return false;
		$session_id = isset( $state['session_id'] ) ? $this->canonicalize_import_session_id( $state['session_id'] ) : '';
		if ( '' === $session_id ) return false;
		$duration = max( self::IMPORT_LEASE_SECONDS, (int) $duration );
		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$lock = $this->read_option_from_database( self::IMPORT_LOCK_OPTION, $found );
			if ( true !== $found || ! is_array( $lock ) || ! isset( $lock['owner_token_hash'], $lock['session_id'] ) || $lock['owner_token_hash'] !== $state['lease_owner_hash'] || $this->canonicalize_import_session_id( $lock['session_id'] ) !== $session_id ) return false;
			$old_lock = $lock;
			$now = time();
			$lock['expires_at'] = max( isset( $lock['expires_at'] ) ? (int) $lock['expires_at'] + 1 : 0, $now + $duration );
			$lock['recover_after'] = max( isset( $lock['recover_after'] ) ? (int) $lock['recover_after'] : 0, $lock['expires_at'], $now + self::IMPORT_STALE_RECOVERY_SECONDS );
			if ( $this->compare_swap_option( self::IMPORT_LOCK_OPTION, $old_lock, $lock ) ) return true;
		}
		return false;
	}

	private function release_import_lease( $state ) {
		if ( empty( $state['lease_owner_hash'] ) ) return false;
		$session_id = isset( $state['session_id'] ) ? $this->canonicalize_import_session_id( $state['session_id'] ) : '';
		if ( '' === $session_id ) return false;
		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$lock = $this->read_option_from_database( self::IMPORT_LOCK_OPTION, $found );
			if ( true !== $found || ! is_array( $lock ) || ! isset( $lock['owner_token_hash'], $lock['session_id'] ) || $lock['owner_token_hash'] !== $state['lease_owner_hash'] || $this->canonicalize_import_session_id( $lock['session_id'] ) !== $session_id ) return false;
			if ( $this->compare_delete_option( self::IMPORT_LOCK_OPTION, $lock ) ) return true;
		}
		return false;
	}

	private function compare_swap_option( $name, $old, $new ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! isset( $wpdb->options ) ) return false;
		$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $this->serialize_option_value( $new ), $name, $this->serialize_option_value( $old ) );
		$result = is_string( $sql ) && 1 === (int) $wpdb->query( $sql );
		if ( $result ) $this->invalidate_option_cache( $name );
		return $result;
	}

	private function compare_delete_option( $name, $old ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! isset( $wpdb->options ) ) return false;
		$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $this->serialize_option_value( $old ) );
		$result = is_string( $sql ) && 1 === (int) $wpdb->query( $sql );
		if ( $result ) $this->invalidate_option_cache( $name );
		return $result;
	}

	private function authorize_import_session( $state, $session_id, $session_token ) {
		$user_id = $this->authorize_current_import_admin();
		if ( is_wp_error( $user_id ) ) return $user_id;
		$session_id = $this->canonicalize_import_session_id( $session_id );
		$state_session_id = is_array( $state ) && isset( $state['session_id'] ) ? $this->canonicalize_import_session_id( $state['session_id'] ) : '';
		if ( ! is_array( $state ) || ! isset( $state['state_version'], $state['user_id'], $state['session_token_hash'] ) || (int) $state['state_version'] !== self::IMPORT_STATE_VERSION || '' === $session_id || $session_id !== $state_session_id || $user_id !== (int) $state['user_id'] || ! is_string( $session_token ) || ! hash_equals( (string) $state['session_token_hash'], hash( 'sha256', $session_token ) ) ) {
			return new WP_Error( 'import_unauthorized', __( 'Import session authorization failed.', 'bricks-ie' ) );
		}
		$archive_check = $this->verify_import_archive_hash( $state );
		if ( is_wp_error( $archive_check ) ) return $archive_check;
		if ( ! $this->renew_import_lease( $state ) ) {
			return new WP_Error( 'import_lease_lost', __( 'Import session lease is no longer valid.', 'bricks-ie' ) );
		}
		return true;
	}

	private function reread_claimed_import_session( $session_id, $session_token, $expected_status = null, $require_lease = false ) {
		$state = get_transient( $this->get_import_session_key( $session_id ) );
		$auth = $this->authorize_staged_session( $state, $session_id, $session_token );
		if ( is_wp_error( $auth ) ) return $auth;
		if ( null !== $expected_status && ( ! isset( $state['status'] ) || $state['status'] !== $expected_status ) ) return new WP_Error( 'import_session_changed', __( 'The import session changed before this transition acquired ownership.', 'bricks-ie' ) );

		$found = null;
		$registry = $this->read_option_from_database( self::IMPORT_REGISTRY_OPTION, $found );
		if ( null === $found ) return new WP_Error( 'import_session_state_unavailable', __( 'Import session ownership could not be verified against the database.', 'bricks-ie' ) );
		if ( false === $found || ! is_array( $registry ) || ! isset( $registry[ $session_id ] ) || ! is_array( $registry[ $session_id ] ) ) return new WP_Error( 'expired_session', __( 'Import session expired or was cancelled.', 'bricks-ie' ) );
		$entry = $registry[ $session_id ];
		if ( ! isset( $entry['status'], $entry['archive_hash'], $entry['session_token_hash'] ) || ! isset( $state['status'], $state['archive_hash'], $state['session_token_hash'] ) || $entry['status'] !== $state['status'] || $entry['archive_hash'] !== $state['archive_hash'] || $entry['session_token_hash'] !== $state['session_token_hash'] || ( isset( $entry['state'] ) && 'cleaning' === $entry['state'] ) ) return new WP_Error( 'import_session_changed', __( 'The persisted import session ownership no longer matches this request.', 'bricks-ie' ) );
		if ( $require_lease ) {
			$auth = $this->authorize_import_session( $state, $session_id, $session_token );
			if ( is_wp_error( $auth ) ) return $auth;
		}
		return $state;
	}

	private function acquire_processing_slot( &$state ) {
		$name = 'bricks_ie_import_processing_' . $this->canonicalize_import_session_id( $state['session_id'] );
		$token = $this->new_secret_token();
		if ( is_wp_error( $token ) ) return false;
		$now = time();
		$slot = array( 'owner' => $token, 'session_id' => $this->canonicalize_import_session_id( $state['session_id'] ), 'state' => 'processing', 'acquired_at' => $now, 'expires_at' => $now + self::IMPORT_LEASE_SECONDS, 'recover_after' => $now + self::IMPORT_STALE_RECOVERY_SECONDS );
		if ( add_option( $name, $slot, '', 'no' ) ) { $state['_processing_token'] = $token; return true; }
		return false;
	}

	private function renew_processing_slot( $state, $duration = self::IMPORT_MUTATION_GUARD_SECONDS ) {
		if ( empty( $state['_processing_token'] ) || empty( $state['session_id'] ) ) return false;
		$name = 'bricks_ie_import_processing_' . $this->canonicalize_import_session_id( $state['session_id'] );
		$duration = max( self::IMPORT_LEASE_SECONDS, (int) $duration );
		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$current = $this->read_option_from_database( $name, $found );
			if ( true !== $found || ! is_array( $current ) || ! isset( $current['owner'] ) || $current['owner'] !== $state['_processing_token'] ) return false;
			$next = $current;
			$now = time();
			$next['expires_at'] = max( isset( $current['expires_at'] ) ? (int) $current['expires_at'] + 1 : 0, $now + $duration );
			$next['recover_after'] = max( isset( $current['recover_after'] ) ? (int) $current['recover_after'] : 0, $next['expires_at'], $now + self::IMPORT_STALE_RECOVERY_SECONDS );
			if ( $this->compare_swap_option( $name, $current, $next ) ) return true;
		}
		return false;
	}

	private function extend_mutation_ownership( $state ) {
		if ( ! $this->renew_import_lease( $state, self::IMPORT_MUTATION_GUARD_SECONDS ) ) return false;
		return $this->renew_processing_slot( $state, self::IMPORT_MUTATION_GUARD_SECONDS );
	}

	private function release_processing_slot( $state ) {
		$name = 'bricks_ie_import_processing_' . $this->canonicalize_import_session_id( $state['session_id'] );
		if ( empty( $state['_processing_token'] ) ) return false;
		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$current = $this->read_option_from_database( $name, $found );
			if ( true !== $found || ! is_array( $current ) || ! isset( $current['owner'] ) || $current['owner'] !== $state['_processing_token'] ) return false;
			if ( $this->compare_delete_option( $name, $current ) ) return true;
		}
		return false;
	}

	private function transition_processing_slot_to_cleaning( $name, $current, &$cleaning = null ) {
		if ( ! is_array( $current ) || empty( $current['owner'] ) || ! isset( $current['expires_at'] ) ) return false;
		$cleaning = $current;
		$cleaning['state'] = 'cleaning';
		$cleaning['expires_at'] = max( (int) $current['expires_at'] + 1, time() + self::IMPORT_LEASE_SECONDS );
		$cleaning['recover_after'] = time() + self::IMPORT_STALE_RECOVERY_SECONDS;
		return $this->compare_swap_option( $name, $current, $cleaning );
	}

	private function unlink_trusted_temp_file( $path, $trusted_dir ) {
		if ( ! is_string( $path ) || '' === $path || ! is_string( $trusted_dir ) || '' === $trusted_dir || is_link( $trusted_dir ) || ! is_dir( $trusted_dir ) || ! file_exists( dirname( $path ) ) ) return false;
		$dir = realpath( $trusted_dir );
		$parent = realpath( dirname( $path ) );
		if ( false === $dir || false === $parent || $parent !== $dir ) return false;
		if ( is_link( $path ) ) return @unlink( $path );
		if ( ! file_exists( $path ) || ! is_file( $path ) ) return false;
		return @unlink( $path );
	}

	private function register_import_session( $state ) {
		$session_id = isset( $state['session_id'] ) ? $this->canonicalize_import_session_id( $state['session_id'] ) : '';
		if ( '' === $session_id ) return false;
		$found = null;
		$lock = $this->read_option_from_database( self::IMPORT_LOCK_OPTION, $found );
		if ( null === $found ) return false;
		$expiry = time() + HOUR_IN_SECONDS;
		if ( true === $found && is_array( $lock ) && isset( $lock['expires_at'] ) && ( ! isset( $state['lease_owner_hash'] ) || ( isset( $lock['owner_token_hash'] ) && $lock['owner_token_hash'] === $state['lease_owner_hash'] ) ) ) {
			$expiry = max( (int) $lock['expires_at'], isset( $lock['recover_after'] ) ? (int) $lock['recover_after'] : 0 );
		}
		$entry = array(
			'zip_path' => $state['zip_path'],
			'trusted_temp_dir' => isset( $state['trusted_temp_dir'] ) ? $state['trusted_temp_dir'] : '',
			'is_temporary' => ! empty( $state['is_temporary'] ),
			'expires_at' => max( $expiry, time() + 30 ),
			'lease_owner_hash' => isset( $state['lease_owner_hash'] ) ? $state['lease_owner_hash'] : '',
			'archive_hash' => isset( $state['archive_hash'] ) ? $state['archive_hash'] : '',
			'session_token_hash' => isset( $state['session_token_hash'] ) ? $state['session_token_hash'] : '',
			'status' => isset( $state['status'] ) ? $state['status'] : '',
		);
		return $this->cas_registry_add_or_update( $session_id, $entry );
	}

	private function cas_registry_add_or_update( $id, $entry ) {
		$id = $this->canonicalize_import_session_id( $id );
		if ( '' === $id ) return false;
		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$raw = $this->read_option_from_database( self::IMPORT_REGISTRY_OPTION, $found );
			if ( null === $found ) return false;
			if ( false === $found ) {
				if ( add_option( self::IMPORT_REGISTRY_OPTION, array( $id => $entry ), '', 'no' ) ) return true;
				continue;
			}
			if ( ! is_array( $raw ) ) return false;
			$old = $raw;
			$new = $old;
			$new[ $id ] = $entry;
			if ( $new === $old ) return true;
			if ( $this->compare_swap_option( self::IMPORT_REGISTRY_OPTION, $old, $new ) ) return true;
		}
		return false;
	}

	private function cas_registry_remove( $id ) {
		$id = $this->canonicalize_import_session_id( $id );
		if ( '' === $id ) return false;
		for ( $attempt = 0; $attempt < self::OPTION_CAS_ATTEMPTS; $attempt++ ) {
			$found = null;
			$raw = $this->read_option_from_database( self::IMPORT_REGISTRY_OPTION, $found );
			if ( null === $found ) return false;
			if ( false === $found ) return true;
			if ( ! is_array( $raw ) ) return false;
			if ( ! array_key_exists( $id, $raw ) ) return true;
			$old = $raw;
			$new = $old;
			unset( $new[ $id ] );
			if ( empty( $new ) ) {
				if ( $this->compare_delete_option( self::IMPORT_REGISTRY_OPTION, $old ) ) return true;
			} elseif ( $this->compare_swap_option( self::IMPORT_REGISTRY_OPTION, $old, $new ) ) return true;
		}
		return false;
	}

	private function mark_import_session_cleaning( $state, $cleaning = false, $fallback_entry = array() ) {
		$id = isset( $state['session_id'] ) ? $this->canonicalize_import_session_id( $state['session_id'] ) : '';
		if ( '' === $id ) return true;
		$found = null;
		$registry = $this->read_option_from_database( self::IMPORT_REGISTRY_OPTION, $found );
		if ( null === $found ) return false;
		$entry = true === $found && is_array( $registry ) && isset( $registry[ $id ] ) && is_array( $registry[ $id ] ) ? $registry[ $id ] : ( is_array( $fallback_entry ) ? $fallback_entry : array() );
		$expiry = is_array( $cleaning ) && isset( $cleaning['expires_at'] ) ? (int) $cleaning['expires_at'] : time() + self::IMPORT_LEASE_SECONDS;
		$entry = array_merge( array(
			'zip_path' => isset( $state['zip_path'] ) ? $state['zip_path'] : '',
			'trusted_temp_dir' => isset( $state['trusted_temp_dir'] ) ? $state['trusted_temp_dir'] : '',
			'is_temporary' => ! empty( $state['is_temporary'] ),
			'lease_owner_hash' => isset( $state['lease_owner_hash'] ) ? $state['lease_owner_hash'] : '',
		), $entry, array( 'state' => 'cleaning', 'expires_at' => $expiry ) );
		return $this->cas_registry_add_or_update( $id, $entry );
	}

	/** Safely recover expired staged sessions; callable from cron without a user. */
	public function cleanup_expired_import_sessions() {
		$found = null;
		$registry = $this->read_option_from_database( self::IMPORT_REGISTRY_OPTION, $found );
		if ( null === $found || ( true === $found && ! is_array( $registry ) ) ) return false;
		if ( false === $found ) return true;
		$now = time();
		$complete = true;
		foreach ( $registry as $id => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['expires_at'] ) || (int) $entry['expires_at'] >= $now ) continue;
			$id = $this->canonicalize_import_session_id( $id );
			if ( '' === $id ) { $complete = false; continue; }
			$processing_name = 'bricks_ie_import_processing_' . $id;
			$processing_found = null;
			$processing = $this->read_option_from_database( $processing_name, $processing_found );
			if ( null === $processing_found ) { $complete = false; continue; }
			$cleanup_state = array_merge( $entry, array( 'session_id' => $id ) );
			if ( ! array_key_exists( 'is_temporary', $cleanup_state ) ) $cleanup_state['is_temporary'] = ! empty( $entry['zip_path'] );

			if ( true === $processing_found ) {
				if ( ! is_array( $processing ) || empty( $processing['owner'] ) ) { $complete = false; continue; }
				$recover_after = isset( $processing['recover_after'] ) ? (int) $processing['recover_after'] : ( isset( $processing['expires_at'] ) ? (int) $processing['expires_at'] + self::IMPORT_STALE_RECOVERY_SECONDS : $now + self::IMPORT_STALE_RECOVERY_SECONDS );
				if ( $recover_after >= $now ) {
					if ( ! $this->cas_registry_add_or_update( $id, array_merge( $entry, array( 'expires_at' => max( (int) $entry['expires_at'], $recover_after ) ) ) ) ) $complete = false;
					continue;
				}
				$cleanup_state['_processing_token'] = $processing['owner'];
			} elseif ( ! $this->acquire_processing_slot( $cleanup_state ) ) {
				$complete = false;
				continue;
			}

			if ( ! $this->cleanup_import_state( $cleanup_state ) ) $complete = false;
		}
		return $complete;
	}

	/** Owner-aware cancellation hook for future AJAX wiring. */
	public function cancel_import_session( $session_id, $session_token = '' ) {
		$admin = $this->authorize_current_import_admin();
		if ( is_wp_error( $admin ) ) return $admin;
		$session_id = $this->canonicalize_import_session_id( $session_id );
		if ( '' === $session_id ) return new WP_Error( 'missing_session', __( 'Missing import session.', 'bricks-ie' ) );
		$this->cleanup_expired_import_sessions();
		$state = get_transient( $this->get_import_session_key( $session_id ) );
		$initial_status = is_array( $state ) && isset( $state['status'] ) ? $state['status'] : null;
		$auth  = 'awaiting_confirmation' === $initial_status ? $this->authorize_staged_session( $state, $session_id, $session_token ) : $this->authorize_import_session( $state, $session_id, $session_token );
		if ( is_wp_error( $auth ) ) return $auth;
		if ( ! $this->acquire_processing_slot( $state ) ) return new WP_Error( 'import_in_progress', __( 'This import session is currently being processed.', 'bricks-ie' ) );
		$claim_owned = true;
		try {
			$processing_token = $state['_processing_token'];
			$fresh_state = $this->reread_claimed_import_session( $session_id, $session_token, $initial_status, 'awaiting_confirmation' !== $initial_status );
			if ( is_wp_error( $fresh_state ) ) return $fresh_state;
			$fresh_state['_processing_token'] = $processing_token;
			$state = $fresh_state;
			$claim_owned = false;
			if ( ! $this->cleanup_import_state( $state ) ) return new WP_Error( 'import_cleanup_failed', __( 'Import cleanup is incomplete; the session remains tracked for recovery.', 'bricks-ie' ) );
			return true;
		} finally {
			if ( $claim_owned ) $this->release_processing_slot( $state );
		}
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
		$this->allow_sensitive_settings = ! empty( $state['allow_sensitive_settings'] );
		$this->import_conflict_mode = isset( $state['conflict_mode'] ) && 'replace' === $state['conflict_mode'] ? 'replace' : 'skip';
		$this->allow_overwrite = ! empty( $state['allow_overwrite'] ) && 'replace' === $this->import_conflict_mode;
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

		$this->imported_option_names = array();
		if ( ! empty( $state['imported_option_names'] ) && is_array( $state['imported_option_names'] ) ) {
			foreach ( $state['imported_option_names'] as $name ) {
				if ( is_string( $name ) && in_array( $name, $this->get_option_names(), true ) ) $this->imported_option_names[] = $name;
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
		$state['imported_option_names'] = array_values( array_unique( $this->imported_option_names ) );
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
			'steps'           => 2 === (int) ( isset( $state['format_version'] ) ? $state['format_version'] : 1 ) ? $this->get_v2_step_labels( $state ) : $this->get_import_step_labels(),
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

	private function get_v2_step_labels( $state ) {
		$steps = array(); foreach ( (array) ( isset( $state['v2_native_order'] ) ? $state['v2_native_order'] : array() ) as $type ) $steps[] = array( 'key' => $type, 'label' => ucfirst( str_replace( '-', ' ', $type ) ) );
		$steps[] = array( 'key' => 'posts', 'label' => __( 'Import pages', 'bricks-ie' ) ); $steps[] = array( 'key' => 'assets', 'label' => __( 'Regenerate Bricks assets', 'bricks-ie' ) ); return $steps;
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
			array( 'key' => 'signatures', 'label' => __( 'Code approval required', 'bricks-ie' ) ),
			array( 'key' => 'cache', 'label' => __( 'Cache cleanup', 'bricks-ie' ) ),
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
	 * @return bool Whether cleanup was completed by the authorized owner.
	 */
	private function cleanup_import_state( $state ) {
		$current = false;
		$cleaning = false;
		$name = '';
		if ( ! empty( $state['session_id'] ) ) {
			$name = 'bricks_ie_import_processing_' . sanitize_key( $state['session_id'] );
			$found = null;
			$current = $this->read_option_from_database( $name, $found );
			if ( null === $found ) return false;
			if ( true === $found && is_array( $current ) ) {
				if ( empty( $state['_processing_token'] ) || empty( $current['owner'] ) || $current['owner'] !== $state['_processing_token'] ) return false;
				if ( ! $this->transition_processing_slot_to_cleaning( $name, $current, $cleaning ) ) return false;
			} elseif ( true === $found || ! empty( $state['_processing_token'] ) ) {
				return false;
			}
		}
		if ( ! $this->mark_import_session_cleaning( $state, $cleaning ) ) return false;
		if ( ! empty( $state['is_temporary'] ) && ! empty( $state['zip_path'] ) && ( file_exists( $state['zip_path'] ) || is_link( $state['zip_path'] ) ) ) {
			if ( ! $this->unlink_trusted_temp_file( $state['zip_path'], isset( $state['trusted_temp_dir'] ) ? $state['trusted_temp_dir'] : '' ) ) {
				if ( is_array( $cleaning ) ) {
					$old_cleaning = $cleaning;
					$new_cleaning = $old_cleaning;
					$new_cleaning['expires_at'] = time() + self::IMPORT_LEASE_SECONDS;
					$this->compare_swap_option( $name, $old_cleaning, $new_cleaning );
				}
				if ( ! empty( $state['session_id'] ) ) {
					$registry_found = null;
					$registry = $this->read_option_from_database( self::IMPORT_REGISTRY_OPTION, $registry_found );
					if ( true === $registry_found && is_array( $registry ) && isset( $registry[ $state['session_id'] ] ) ) {
						$expiry = is_array( $cleaning ) && isset( $new_cleaning['expires_at'] ) ? $new_cleaning['expires_at'] : time() + self::IMPORT_LEASE_SECONDS;
						$this->cas_registry_add_or_update( $state['session_id'], array_merge( $registry[ $state['session_id'] ], array( 'state' => 'cleaning', 'expires_at' => $expiry ) ) );
					}
				}
				return false;
			}
		}

		if ( ! empty( $state['session_id'] ) ) {
			delete_transient( $this->get_import_session_key( $state['session_id'] ) );
		}

		if ( ! empty( $state['lease_owner_hash'] ) && ! empty( $state['session_id'] ) ) {
			$lock_found = null;
			$lock = $this->read_option_from_database( self::IMPORT_LOCK_OPTION, $lock_found );
			if ( null === $lock_found ) return false;
			if ( true === $lock_found ) {
				if ( ! is_array( $lock ) || ! isset( $lock['owner_token_hash'], $lock['session_id'] ) ) return false;
				if ( $lock['owner_token_hash'] === $state['lease_owner_hash'] && $this->canonicalize_import_session_id( $lock['session_id'] ) === $this->canonicalize_import_session_id( $state['session_id'] ) && ! $this->release_import_lease( $state ) ) return false;
			}
		}
		if ( is_array( $current ) && ! $this->release_processing_slot( array_merge( $state, array( '_processing_token' => isset( $cleaning['owner'] ) ? $cleaning['owner'] : '' ) ) ) ) return false;
		if ( ! empty( $state['session_id'] ) && ! $this->cas_registry_remove( $state['session_id'] ) ) return false;
		return true;
	}

	/**
	 * Remap source post IDs to target post IDs in all imported data.
	 */
	private function remap_post_ids() {
		if ( empty( $this->id_map ) ) {
			return;
		}

		// Skip mappings remain available as reference targets, but only posts
		// written by this import may have their metadata rewritten.
		$post_ids = array_values( array_unique( array_map( 'intval', $this->imported_post_ids ) ) );
		foreach ( $post_ids as $target_id ) {
			foreach ( $this->get_meta_keys() as $key ) {
				$value = get_post_meta( $target_id, $key, true );
				if ( '' === $value ) {
					continue;
				}

				$new_value = $this->replace_typed_post_references( $value );
				if ( $new_value !== $value ) {
					delete_post_meta( $target_id, $key );
					add_post_meta( $target_id, $key, $new_value );
				}
			}
		}

		// Remap in options.
		foreach ( array_values( array_unique( $this->imported_option_names ) ) as $name ) {
			$value = get_option( $name );
			if ( false === $value ) {
				continue;
			}

			$new_value = $this->replace_typed_post_references( $value );
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
		// Kept for compatibility with older integrations that reflected this
		// helper. Mutation paths deliberately never call it; typed traversal is
		// required so element IDs, counts, CSS and arbitrary numeric values stay
		// unchanged.
		$old_map       = $this->id_map;
		$this->id_map  = is_array( $id_map ) ? $id_map : array();
		$result        = $this->replace_typed_post_references( $data );
		$this->id_map  = $old_map;

		return $result;
	}

	/**
	 * Replace only documented post/template reference fields.
	 *
	 * @param mixed $data Value to traverse.
	 * @param array $path Parent keys, from the root to the current value.
	 * @return mixed
	 */
	private function replace_typed_post_references( $data, $path = array() ) {
		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$key_string = is_string( $key ) ? $key : '';
				$next_path  = array_merge( $path, array( $key_string ) );
				if ( $this->is_post_reference_key( $key_string, $path ) ) {
					$result[ $key ] = $this->map_typed_post_reference_value( $value );
				} elseif ( $this->is_condition_reference_key( $key_string, $path ) ) {
					$result[ $key ] = $this->map_typed_post_reference_value( $value );
				} else {
					$result[ $key ] = $this->replace_typed_post_references( $value, $next_path );
				}
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			$result = clone $data;
			foreach ( get_object_vars( $result ) as $key => $value ) {
				$result->$key = $this->replace_typed_post_references( $value, array_merge( $path, array( (string) $key ) ) );
			}
			return $result;
		}

		return $data;
	}

	/** @return bool */
	private function is_post_reference_key( $key, $path ) {
		if ( in_array( $key, array( 'postId', 'templateId', 'infoBoxId', 'infoBoxTemplateId', 'updatePostId', 'templatePreviewId', 'previewTemplateId', 'previewPostId' ), true ) ) {
			return true;
		}

		if ( 'no_results_template' === $key && in_array( 'query', $path, true ) ) {
			return true;
		}

		return 'template' === $key && in_array( 'settings', $path, true );
	}

	/** @return bool */
	private function is_condition_reference_key( $key, $path ) {
		$in_conditions = in_array( '_conditions', $path, true ) || in_array( 'conditions', $path, true );
		return $in_conditions && in_array( $key, array( 'id', 'ids', 'conditionId', 'conditionIds' ), true );
	}

	/** @return mixed */
	private function map_typed_post_reference_value( $value ) {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$result[ $key ] = $this->map_typed_post_reference_value( $item );
			}
			return $result;
		}

		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return $value;
		}

		$source_id = (int) $value;
		if ( ! isset( $this->id_map[ $source_id ] ) ) {
			return $value;
		}

		$mapped = (int) $this->id_map[ $source_id ];
		return is_string( $value ) ? (string) $mapped : $mapped;
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

		foreach ( array_values( array_unique( $this->imported_option_names ) ) as $name ) {
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
	private function recursive_normalize_imported_media( $data, $path = array(), $in_media = false ) {
		if ( is_array( $data ) ) {
			$is_media = $this->is_recognized_media_array( $data ) || $in_media;
			if ( $is_media && $this->is_recognized_media_array( $data ) ) {
				$data = $this->normalize_attachment_media_array( $data );
			}
			$result = array();

			foreach ( $data as $key => $value ) {
				$result[ $key ] = $this->recursive_normalize_imported_media( $value, array_merge( $path, array( (string) $key ) ), $is_media );
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

		if ( is_string( $data ) && $in_media && ! empty( $path ) && in_array( end( $path ), array( 'url', 'full' ), true ) ) {
			return $this->replace_source_site_url( $data );
		}

		return $data;
	}

	/** @return bool */
	private function is_recognized_media_array( $data ) {
		return is_array( $data ) && isset( $data['id'] ) && ( isset( $data['url'] ) || isset( $data['full'] ) || isset( $data['filename'] ) );
	}

	/**
	 * Conservatively identify v2 references for a future typed remapping pass.
	 * This scanner reports only explicitly recognized Bricks reference fields;
	 * it does not mutate values.
	 *
	 * @param mixed $data Candidate decoded v2 data.
	 * @param array $path Current key path.
	 * @return array List of key paths requiring a typed resolver.
	 */
	private function scan_typed_reference_candidates( $data, $path = array() ) {
		$found = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$key = (string) $key;
				$next = array_merge( $path, array( $key ) );
				if ( in_array( $key, array( '_cssGlobalClasses', 'cid', 'globalQueryId', 'templateId', 'fontId', 'iconId' ), true ) ) {
					$found[] = implode( '.', $next );
				}
				$found = array_merge( $found, $this->scan_typed_reference_candidates( $value, $next ) );
			}
		}
		return array_values( array_unique( $found ) );
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
		if ( ! function_exists( 'get_post_type' ) ) {
			return 0;
		}

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

		$source = function_exists( 'untrailingslashit' ) ? untrailingslashit( $this->source_site_url ) : rtrim( $this->source_site_url, '/' );
		$target_url = function_exists( 'home_url' ) ? home_url() : '';
		$target = function_exists( 'untrailingslashit' ) ? untrailingslashit( $target_url ) : rtrim( $target_url, '/' );

		if ( '' === $source || $source === $target ) {
			return $value;
		}

		$search = array( $source );
		if ( function_exists( 'set_url_scheme' ) ) {
			$search[] = set_url_scheme( $source, 'http' );
			$search[] = set_url_scheme( $source, 'https' );
		}
		$search = array_unique( array_filter( $search ) );

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
	 * User-facing warning for the preserved "signatures" progress stage.
	 *
	 * The 1.0.2 importer automatically re-signed imported code through
	 * Bricks\Admin::crawl_and_update_code_signatures() and switched to the
	 * first administrator in headless contexts to make that possible. Both
	 * behaviors were removed in 1.1.0 (T6): imported executable code must
	 * remain unapproved until an administrator explicitly reviews and approves
	 * it, and the importer never impersonates another user. The stage itself
	 * stays in the progress model as a no-op warning so existing AJAX clients
	 * keep receiving the response shape they expect.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	private function get_code_approval_required_message() {
		return __( 'Code approval required: imported code remains unapproved until an administrator explicitly reviews and approves it.', 'bricks-ie' );
	}

	/**
	 * Scoped cache cleanup step (intentionally conservative in this release).
	 *
	 * The 1.0.2 importer recursively deleted every .html file below
	 * WP_CONTENT_DIR/cache/ and called wp_cache_flush(). Both behaviors were
	 * removed in 1.1.0 (T6): the recursive sweep destroyed cache files owned
	 * by unrelated plugins, and wp_cache_flush() invalidates the entire
	 * object cache. This step is therefore a documented no-op for now:
	 * WordPress already invalidates option and post caches for every write
	 * performed during import, and Bricks CSS files are regenerated in the
	 * assets step. Targeted invalidation of caches known to be affected by
	 * imported Bricks domains belongs to a later work package.
	 *
	 * @since 1.1.0
	 */
	private function run_scoped_cache_cleanup() {
		// Intentionally a no-op in this release. See the docblock above: no
		// recursive cache-file deletion and no broad wp_cache_flush() here.
	}
}
