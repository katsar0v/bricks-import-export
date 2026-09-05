<?php
/** Session authorization, duplicate-step and cleanup contract tests. */

namespace {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() { return isset( $GLOBALS['bricks_ie_session_user'] ) ? (int) $GLOBALS['bricks_ie_session_user'] : 42; }
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	}
	if ( ! function_exists( 'wp_tempnam' ) ) {
		function wp_tempnam( $name = '' ) { $path = tempnam( sys_get_temp_dir(), 'bricks-ie-' ); return $path; }
	}
	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4() { return 'session-' . bin2hex( random_bytes( 6 ) ); }
	}
	if ( ! function_exists( 'wp_raise_memory_limit' ) ) {
		function wp_raise_memory_limit( $context = '' ) { return true; }
	}
	if ( ! class_exists( 'Bricks_IE_Importer' ) ) require_once __DIR__ . '/test-importer-preflight.php';

	bricks_ie_test( 'import session: wrong user, token, and archive hash are rejected', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$importer = new Bricks_IE_Importer();
		$method = new ReflectionMethod( $importer, 'authorize_import_session' ); $method->setAccessible( true );
		$state = array( 'state_version' => Bricks_IE_Importer::IMPORT_STATE_VERSION, 'user_id' => 42, 'session_token_hash' => hash( 'sha256', 'token' ), 'zip_path' => __FILE__, 'archive_hash' => hash_file( 'sha256', __FILE__ ), 'lease_owner_hash' => 'owner', 'session_id' => 'session' );
		bricks_ie_assert_instance_of( 'WP_Error', $method->invoke( $importer, $state, 'session', 'wrong' ) );
		$GLOBALS['bricks_ie_session_user'] = 99;
		bricks_ie_assert_instance_of( 'WP_Error', $method->invoke( $importer, $state, 'session', 'token' ) );
	} );

	bricks_ie_test( 'import session: cleanup removes owned transient, registry, temp file, and lock', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'owned.zip'; file_put_contents( $file, 'owned' );
		$importer = new Bricks_IE_Importer();
		$method = new ReflectionMethod( $importer, 'cleanup_import_state' ); $method->setAccessible( true );
		$state = array( 'session_id' => 'owned-session', 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, 'lease_owner_hash' => 'owner' );
		$method->invoke( $importer, $state );
		bricks_ie_assert( ! file_exists( $file ), 'Owned temporary archive should be removed.' );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_pf_write_log'] ), 'Cleanup writes should be observable by the spy.' );
	} );

	bricks_ie_test( 'import session: stale processing owner cannot delete newer slot, transient, registry, temp zip, or lease', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'newer.zip'; file_put_contents( $file, 'newer' );
		$id = 'stale-owner';
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_' . $id] = array( 'owner' => 'new-owner', 'expires_at' => time() + 3600 );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( $id => array( 'zip_path' => $file, 'expires_at' => time() + 3600, 'lease_owner_hash' => 'new-lease' ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_lock'] = array( 'owner_token_hash' => 'new-lease', 'session_id' => $id, 'expires_at' => time() + 3600 );
		set_transient( 'bricks_ie_import_' . $id, array( 'status' => 'processing' ), 3600 );
		$importer = new Bricks_IE_Importer();
		$method = new ReflectionMethod( $importer, 'cleanup_import_state' ); $method->setAccessible( true );
		$result = $method->invoke( $importer, array( 'session_id' => $id, 'zip_path' => $file, 'is_temporary' => true, '_processing_token' => 'old-owner', 'lease_owner_hash' => 'old-lease' ) );
		bricks_ie_assert_same( false, $result );
		bricks_ie_assert( file_exists( $file ) );
		bricks_ie_assert( is_array( get_transient( 'bricks_ie_import_' . $id ) ) );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'][ $id ] ) );
		bricks_ie_assert_same( 'new-owner', $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_' . $id]['owner'] );
		bricks_ie_assert_same( 'new-lease', $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_lock']['owner_token_hash'] );
	} );

	bricks_ie_test( 'import session: active cancellation returns import_in_progress', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$id = 'active-cancel'; $token = 'cancel-token';
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_' . $id] = array( 'owner' => 'active-owner', 'expires_at' => time() + 3600 );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_lock'] = array( 'owner_token_hash' => 'active-lease', 'session_id' => $id, 'expires_at' => time() + 3600 );
		set_transient( 'bricks_ie_import_' . $id, array( 'state_version' => Bricks_IE_Importer::IMPORT_STATE_VERSION, 'session_id' => $id, 'user_id' => 42, 'session_token_hash' => hash( 'sha256', $token ), 'lease_owner_hash' => 'active-lease', 'status' => 'processing', 'zip_path' => __FILE__, 'archive_hash' => hash_file( 'sha256', __FILE__ ) ), 3600 );
		$result = ( new Bricks_IE_Importer() )->cancel_import_session( $id, $token );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'import_in_progress', $result->get_error_code() );
	} );

	bricks_ie_test( 'import session: awaiting-confirmation cancellation without a slot cleans successfully', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'awaiting.zip'; file_put_contents( $file, 'awaiting' );
		$id = 'awaiting-cancel'; $token = 'awaiting-token';
		$state = array( 'state_version' => Bricks_IE_Importer::IMPORT_STATE_VERSION, 'session_id' => $id, 'user_id' => 42, 'session_token_hash' => hash( 'sha256', $token ), 'status' => 'awaiting_confirmation', 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, 'archive_hash' => hash_file( 'sha256', $file ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( $id => array( 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, 'expires_at' => time() + 3600, 'lease_owner_hash' => '', 'archive_hash' => $state['archive_hash'], 'session_token_hash' => $state['session_token_hash'], 'status' => 'awaiting_confirmation' ) );
		set_transient( 'bricks_ie_import_' . $id, $state, 3600 );
		$result = ( new Bricks_IE_Importer() )->cancel_import_session( $id, $token );
		bricks_ie_assert_same( true, $result );
		bricks_ie_assert( ! file_exists( $file ) );
		bricks_ie_assert_same( false, get_transient( 'bricks_ie_import_' . $id ) );
	} );

	bricks_ie_test( 'import session: cleanup transitions the owned slot to cleaning before destructive writes', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'cleaning.zip'; file_put_contents( $file, 'cleaning' );
		$id = 'cleaning-order'; $token = 'cleaning-owner';
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_' . $id] = array( 'owner' => $token, 'expires_at' => time() + 10 );
		set_transient( 'bricks_ie_import_' . $id, array( 'status' => 'processing' ), 3600 );
		$importer = new Bricks_IE_Importer(); $method = new ReflectionMethod( $importer, 'cleanup_import_state' ); $method->setAccessible( true );
		bricks_ie_assert_same( true, $method->invoke( $importer, array( 'session_id' => $id, 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, '_processing_token' => $token ) ) );
		$mutations = array_values( array_filter( $GLOBALS['wpdb']->queries, function ( $query ) { return false !== strpos( $query, 'UPDATE' ) || false !== strpos( $query, 'DELETE' ); } ) );
		bricks_ie_assert( ! empty( $mutations ) && false !== strpos( $mutations[0], 'UPDATE' ), 'CAS transition must be the first database mutation before cleanup writes' );
	} );

	bricks_ie_test( 'import session: failed cleaning CAS preserves transient, file, registry, and lease', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB(); $GLOBALS['wpdb']->query_result = 0;
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'cas-failure.zip'; file_put_contents( $file, 'cas-failure' );
		$id = 'cas-failure'; $token = 'cas-owner';
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_' . $id] = array( 'owner' => $token, 'expires_at' => time() + 10 );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( $id => array( 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'state' => 'cleaning', 'lease_owner_hash' => 'lease' ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_lock'] = array( 'owner_token_hash' => 'lease', 'session_id' => $id, 'expires_at' => time() + 3600 );
		set_transient( 'bricks_ie_import_' . $id, array( 'status' => 'processing' ), 3600 );
		$importer = new Bricks_IE_Importer(); $method = new ReflectionMethod( $importer, 'cleanup_import_state' ); $method->setAccessible( true );
		bricks_ie_assert_same( false, $method->invoke( $importer, array( 'session_id' => $id, 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, '_processing_token' => $token, 'lease_owner_hash' => 'lease' ) ) );
		bricks_ie_assert( file_exists( $file ) );
		bricks_ie_assert( false !== get_transient( 'bricks_ie_import_' . $id ) );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'][ $id ] ) );
		bricks_ie_assert_same( 'cleaning', $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'][ $id ]['state'], 'recovery remains tracked as cleaning' );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_lock']['owner_token_hash'] ) );
	} );

	bricks_ie_test( 'import session: trusted unlink removes an in-directory symlink pathname but never its target; directory symlinks fail', function () {
		$importer = new Bricks_IE_Importer(); $method = new ReflectionMethod( $importer, 'unlink_trusted_temp_file' ); $method->setAccessible( true );
		$dir = bricks_ie_test_temp_dir(); $target = $dir . DIRECTORY_SEPARATOR . 'target.txt'; $link = $dir . DIRECTORY_SEPARATOR . 'link.txt'; file_put_contents( $target, 'keep' );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $link ) ) return;
		bricks_ie_assert_same( true, $method->invoke( $importer, $link, $dir ) );
		bricks_ie_assert( file_exists( $target ) && ! file_exists( $link ) );
		$outside = bricks_ie_test_temp_dir(); $dir_link = $dir . DIRECTORY_SEPARATOR . 'trusted-link';
		if ( @symlink( $outside, $dir_link ) ) {
			$file = $outside . DIRECTORY_SEPARATOR . 'outside.txt'; file_put_contents( $file, 'keep' );
			bricks_ie_assert_same( false, $method->invoke( $importer, $file, $dir_link ) );
			bricks_ie_assert( file_exists( $file ) );
			@unlink( $dir_link );
		}
	} );

	bricks_ie_test( 'import session: registry CAS preserves a concurrent second session and removes the last entry safely', function () {
		bricks_ie_pf_reset(); $GLOBALS['bricks_ie_pf_spy_mode'] = true; $GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$importer = new Bricks_IE_Importer(); $add = new ReflectionMethod( $importer, 'cas_registry_add_or_update' ); $add->setAccessible( true ); $remove = new ReflectionMethod( $importer, 'cas_registry_remove' ); $remove->setAccessible( true );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( 'first' => array( 'zip_path' => 'one' ) );
		// Exhaust the bounded retry loop, then model the concurrent writer's
		// successful update and ensure the second session is retained.
		$GLOBALS['wpdb']->query_result = 0;
		bricks_ie_assert_same( false, $add->invoke( $importer, 'lost', array( 'zip_path' => 'lost' ) ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['second'] = array( 'zip_path' => 'two' );
		$GLOBALS['wpdb']->query_result = 1;
		bricks_ie_assert_same( true, $add->invoke( $importer, 'third', array( 'zip_path' => 'three' ) ), 'concurrent CAS retry failed' );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['second'] ) && isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['third'] ), var_export( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'], true ) );
		$before = count( array_filter( $GLOBALS['wpdb']->queries, function ( $query ) { return false !== strpos( $query, 'UPDATE' ); } ) );
		bricks_ie_assert_same( true, $add->invoke( $importer, 'third', $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['third'] ) );
		$after = count( array_filter( $GLOBALS['wpdb']->queries, function ( $query ) { return false !== strpos( $query, 'UPDATE' ); } ) );
		bricks_ie_assert_same( $before, $after, 'identical update must not depend on affected rows' );
		bricks_ie_assert_same( true, $remove->invoke( $importer, 'first' ), 'remove first failed' );
		bricks_ie_assert_same( true, $remove->invoke( $importer, 'second' ), 'remove second failed' );
		bricks_ie_assert_same( true, $remove->invoke( $importer, 'third' ), 'remove last failed' );
		bricks_ie_assert_same( false, get_option( 'bricks_ie_import_sessions', false ), var_export( get_option( 'bricks_ie_import_sessions', false ), true ) );
	} );

	bricks_ie_test( 'import session: registry CAS ignores stale object-cache values', function () {
		bricks_ie_pf_reset(); $GLOBALS['bricks_ie_pf_spy_mode'] = true; $GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$cache_registry = array( 'cache-only' => array( 'zip_path' => 'cache' ) );
		$db_registry = array( 'db-owned' => array( 'zip_path' => 'database' ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = $cache_registry;
		$GLOBALS['wpdb']->set_database_options( array( 'bricks_ie_import_sessions' => $db_registry ) );
		$importer = new Bricks_IE_Importer();
		$add = new ReflectionMethod( $importer, 'cas_registry_add_or_update' ); $add->setAccessible( true );
		$remove = new ReflectionMethod( $importer, 'cas_registry_remove' ); $remove->setAccessible( true );
		bricks_ie_assert_same( true, $add->invoke( $importer, 'third', array( 'zip_path' => 'three' ) ) );
		bricks_ie_assert_same( true, $remove->invoke( $importer, 'cache-only' ) );
		bricks_ie_assert_same( true, $remove->invoke( $importer, 'third' ) );
		$db = $GLOBALS['wpdb']->get_database_options();
		bricks_ie_assert( isset( $db['bricks_ie_import_sessions']['db-owned'] ) );
		bricks_ie_assert( ! isset( $db['bricks_ie_import_sessions']['cache-only'] ) && ! isset( $db['bricks_ie_import_sessions']['third'] ) );
	} );

	bricks_ie_test( 'import session: non-expired cleaning slots remain, expired cleaning recovery is idempotent, and failed unlink stays tracked', function () {
		bricks_ie_pf_reset(); $GLOBALS['bricks_ie_pf_spy_mode'] = true; $GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$importer = new Bricks_IE_Importer(); $method = new ReflectionMethod( $importer, 'cleanup_expired_import_sessions' ); $method->setAccessible( true );
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'expired.zip'; file_put_contents( $file, 'expired' );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( 'clean' => array( 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'state' => 'cleaning', 'expires_at' => time() - 10, 'lease_owner_hash' => 'lease' ), 'active' => array( 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'state' => 'cleaning', 'expires_at' => time() + 100 ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_clean'] = array( 'owner' => 'owner', 'state' => 'cleaning', 'expires_at' => time() + 100 );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_active'] = array( 'owner' => 'active', 'state' => 'cleaning', 'expires_at' => time() + 100 );
		set_transient( 'bricks_ie_import_clean', array( 'status' => 'processing' ), 3600 );
		$method->invoke( $importer );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['clean'] ), 'active cleaning recovery must remain tracked' );
		bricks_ie_assert( isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['active'] ), 'non-expired cleaning slot must remain tracked' );
		// An expired cleaning slot with a symlink pathname is reclaimed safely;
		// its target remains untouched and the cleanup is idempotent.
		$bad_dir = bricks_ie_test_temp_dir(); $target = $bad_dir . DIRECTORY_SEPARATOR . 'target'; $link = $bad_dir . DIRECTORY_SEPARATOR . 'link'; file_put_contents( $target, 'keep' );
		if ( function_exists( 'symlink' ) && @symlink( $target, $link ) ) {
			$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['bad'] = array( 'zip_path' => $link, 'trusted_temp_dir' => $bad_dir, 'state' => 'cleaning', 'expires_at' => time() - 10, 'lease_owner_hash' => '' );
			$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_processing_bad'] = array( 'owner' => 'bad', 'state' => 'cleaning', 'expires_at' => time() - Bricks_IE_Importer::IMPORT_STALE_RECOVERY_SECONDS - 10, 'recover_after' => time() - 10 );
			$method->invoke( $importer );
			bricks_ie_assert( ! isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['bad'] ) && ! file_exists( $link ) && file_exists( $target ), 'bad recovery: ' . var_export( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'], true ) );
			$method->invoke( $importer );
			bricks_ie_assert( ! isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['bad'] ) );
		}
		$dangling = $bad_dir . DIRECTORY_SEPARATOR . 'dangling';
		if ( function_exists( 'symlink' ) && @symlink( $bad_dir . DIRECTORY_SEPARATOR . 'missing-target', $dangling ) ) {
			$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['dangling'] = array( 'zip_path' => $dangling, 'trusted_temp_dir' => $bad_dir, 'state' => 'cleaning', 'expires_at' => time() - 10, 'lease_owner_hash' => '' );
			$method->invoke( $importer );
			bricks_ie_assert( ! isset( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions']['dangling'] ), 'dangling pathname cleanup is idempotent: ' . var_export( $GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'], true ) );
			@unlink( $dangling );
		}
	} );

	bricks_ie_test( 'import session: initial staged persistence failure removes registry entry and staged file', function () {
		bricks_ie_pf_reset(); $GLOBALS['bricks_ie_pf_spy_mode'] = true; $GLOBALS['bricks_ie_test_fail_set_transient'] = true;
		$source = bricks_ie_pf_v2_archive( array( 'name' => 'upload-source.zip', 'posts' => array() ) );
		$_FILES['bricks_ie_import_file'] = array( 'name' => 'upload.zip', 'error' => UPLOAD_ERR_OK, 'tmp_name' => $source );
		$result = ( new Bricks_IE_Importer() )->start_import_preflight_session();
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'import_session_persistence_failed', $result->get_error_code() );
		bricks_ie_assert_same( false, get_option( 'bricks_ie_import_sessions', false ) );
		unset( $_FILES['bricks_ie_import_file'] );
	} );

	bricks_ie_test( 'import session: every public staged lifecycle entry point requires manage_options', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = false;
		$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;
		$importer = new Bricks_IE_Importer();
		$calls = array(
			function () use ( $importer ) { return $importer->start_import_session(); },
			function () use ( $importer ) { return $importer->start_import_preflight_session(); },
			function () use ( $importer ) { return $importer->confirm_import_session( 'missing', 'token', array() ); },
			function () use ( $importer ) { return $importer->run_import_session_step( 'missing', 'token' ); },
			function () use ( $importer ) { return $importer->cancel_import_session( 'missing', 'token' ); },
		);
		foreach ( $calls as $call ) {
			$result = $call();
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'import_auth_required', $result->get_error_code() );
		}
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'], 'Authorization must run before lookup, cleanup, staging, or mutation.' );
	} );

	bricks_ie_test( 'import session: public cancellation canonicalizes the ID before lookup and authorization', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$dir = bricks_ie_test_temp_dir();
		$file = $dir . DIRECTORY_SEPARATOR . 'canonical.zip';
		file_put_contents( $file, 'canonical' );
		$id = 'canonical-id';
		$token = 'canonical-token';
		$state = array( 'state_version' => Bricks_IE_Importer::IMPORT_STATE_VERSION, 'session_id' => $id, 'user_id' => 42, 'session_token_hash' => hash( 'sha256', $token ), 'status' => 'awaiting_confirmation', 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, 'archive_hash' => hash_file( 'sha256', $file ) );
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( $id => array( 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, 'expires_at' => time() + 3600, 'lease_owner_hash' => '', 'archive_hash' => $state['archive_hash'], 'session_token_hash' => $state['session_token_hash'], 'status' => 'awaiting_confirmation' ) );
		set_transient( 'bricks_ie_import_' . $id, $state, 3600 );
		$result = ( new Bricks_IE_Importer() )->cancel_import_session( 'CANONICAL-ID%%%', $token );
		bricks_ie_assert_same( true, $result );
		bricks_ie_assert( ! file_exists( $file ) );
		bricks_ie_assert_same( false, get_transient( 'bricks_ie_import_' . $id ) );
	} );

	bricks_ie_test( 'import session: sidecar omissions require warning acknowledgement at confirmation', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$zip = bricks_ie_pf_v2_archive( array(
			'name'      => 'sidecar-acknowledgement.zip',
			'omissions' => array( array( 'id' => 'omitted_domain', 'message' => 'An exporter domain was omitted.' ) ),
		) );
		$importer = new Bricks_IE_Importer();
		$report = $importer->preflight( $zip );
		bricks_ie_assert_same( 'warning', $report['status'] );
		$id = 'sidecar-ack';
		$token = 'sidecar-token';
		$create = new ReflectionMethod( $importer, 'create_staged_session_state' );
		$create->setAccessible( true );
		$state = $create->invoke( $importer, $id, $token, 42, $zip, $report['archive_hash'], $report );
		set_transient( 'bricks_ie_import_' . $id, $state, 3600 );
		$GLOBALS['bricks_ie_pf_write_log'] = array();

		$result = $importer->confirm_import_session( $id, $token, array(
			'archive_hash'        => $report['archive_hash'],
			'plan_hash'           => $report['plan_hash'],
			'plan'                => $report['plan'],
			'backup_acknowledged' => true,
		) );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'warnings_acknowledgement_required', $result->get_error_code() );
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'], 'Acknowledgement must be required before lease or content writes.' );
	} );

	bricks_ie_test( 'import session: stale awaiting-confirmation cache cannot resurrect a cancelled registry session', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$zip = bricks_ie_pf_v2_archive( array(
			'name' => 'cancelled-stale-confirm.zip',
			'posts' => array( array( 'id' => 1, 'slug' => 'stale-confirm', 'type' => 'page', 'status' => 'publish', 'title' => 'Stale confirm', 'meta' => array() ) ),
		) );
		$importer = new Bricks_IE_Importer();
		$report = $importer->preflight( $zip );
		$id = 'stale-confirm'; $token = 'stale-confirm-token';
		$create = new ReflectionMethod( $importer, 'create_staged_session_state' ); $create->setAccessible( true );
		$state = $create->invoke( $importer, $id, $token, 42, $zip, $report['archive_hash'], $report );
		set_transient( 'bricks_ie_import_' . $id, $state, 3600 );
		// Cancellation has authoritatively removed the registry, while a stale
		// object-cache copy of the transient still reports awaiting confirmation.
		$result = $importer->confirm_import_session( $id, $token, array( 'archive_hash' => $report['archive_hash'], 'plan_hash' => $report['plan_hash'], 'plan' => $report['plan'], 'backup_acknowledged' => true, 'warnings_acknowledged' => true ) );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'expired_session', $result->get_error_code() );
		bricks_ie_assert_same( 'awaiting_confirmation', get_transient( 'bricks_ie_import_' . $id )['status'] );
		bricks_ie_assert_same( false, get_option( 'bricks_ie_import_processing_' . $id, false ) );
		bricks_ie_assert_same( false, get_option( Bricks_IE_Importer::IMPORT_LOCK_OPTION, false ) );
	} );

	bricks_ie_test( 'import session: confirmation contends on the cancellation claim and persists only after owning it', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$zip = bricks_ie_pf_v2_archive( array(
			'name' => 'confirm-claim.zip',
			'posts' => array( array( 'id' => 1, 'slug' => 'confirm-claim', 'type' => 'page', 'status' => 'publish', 'title' => 'Confirm claim', 'meta' => array() ) ),
		) );
		$importer = new Bricks_IE_Importer();
		$report = $importer->preflight( $zip );
		$id = 'confirm-claim'; $token = 'confirm-claim-token';
		$create = new ReflectionMethod( $importer, 'create_staged_session_state' ); $create->setAccessible( true );
		$register = new ReflectionMethod( $importer, 'register_import_session' ); $register->setAccessible( true );
		$state = $create->invoke( $importer, $id, $token, 42, $zip, $report['archive_hash'], $report );
		bricks_ie_assert_same( true, $register->invoke( $importer, $state ) );
		set_transient( 'bricks_ie_import_' . $id, $state, 3600 );
		$slot_name = 'bricks_ie_import_processing_' . $id;
		$GLOBALS['bricks_ie_exporter_test']['options'][ $slot_name ] = array( 'owner' => 'cancellation-owner', 'session_id' => $id, 'state' => 'processing', 'expires_at' => time() + 120, 'recover_after' => time() + 86400 );
		$confirmation = array( 'archive_hash' => $report['archive_hash'], 'plan_hash' => $report['plan_hash'], 'plan' => $report['plan'], 'backup_acknowledged' => true, 'warnings_acknowledged' => true );
		$blocked = $importer->confirm_import_session( $id, $token, $confirmation );
		bricks_ie_assert_instance_of( 'WP_Error', $blocked );
		bricks_ie_assert_same( 'import_in_progress', $blocked->get_error_code() );
		bricks_ie_assert_same( 'awaiting_confirmation', get_transient( 'bricks_ie_import_' . $id )['status'] );
		bricks_ie_assert_same( false, get_option( Bricks_IE_Importer::IMPORT_LOCK_OPTION, false ) );

		unset( $GLOBALS['bricks_ie_exporter_test']['options'][ $slot_name ] );
		$confirmed = $importer->confirm_import_session( $id, $token, $confirmation );
		bricks_ie_assert_same( 'confirmed', $confirmed['status'] );
		bricks_ie_assert_same( 'confirmed', get_transient( 'bricks_ie_import_' . $id )['status'] );
		bricks_ie_assert_same( 'confirmed', $GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_REGISTRY_OPTION ][ $id ]['status'] );
		bricks_ie_assert_same( false, get_option( $slot_name, false ) );
		bricks_ie_assert_same( true, $importer->cancel_import_session( $id, $token ) );
	} );

	bricks_ie_test( 'import session: public expired cleanup runs without a logged-in user', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$dir = bricks_ie_test_temp_dir(); $file = $dir . DIRECTORY_SEPARATOR . 'cron-cleanup.zip'; file_put_contents( $file, 'expired' );
		$id = 'cron-cleanup';
		$GLOBALS['bricks_ie_exporter_test']['options']['bricks_ie_import_sessions'] = array( $id => array( 'zip_path' => $file, 'trusted_temp_dir' => $dir, 'is_temporary' => true, 'expires_at' => time() - 10, 'lease_owner_hash' => '' ) );
		set_transient( 'bricks_ie_import_' . $id, array( 'status' => 'awaiting_confirmation' ), 3600 );
		$GLOBALS['bricks_ie_session_user'] = 0;
		$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = false;
		bricks_ie_assert_same( true, ( new Bricks_IE_Importer() )->cleanup_expired_import_sessions() );
		bricks_ie_assert( ! file_exists( $file ) );
		bricks_ie_assert_same( false, get_transient( 'bricks_ie_import_' . $id ) );
	} );
}
