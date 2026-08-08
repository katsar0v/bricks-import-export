<?php
/** Import lease contention and owner-safety tests. */

namespace {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() { return 42; }
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	}
	if ( ! class_exists( 'Bricks_IE_Test_WPDB' ) ) {
		class Bricks_IE_Test_WPDB {
			public $options = 'wp_options';
			public $query_result = 1;
			public $queries = array();
			public $prepared_args = array();
			public $query_hook;
			public $select_hook;
			public $last_error = '';
			public $database_options = null;
			public function prepare( $sql ) { $args = func_get_args(); array_shift( $args ); $this->prepared_args = $args; return $sql; }
			public function set_database_options( array $options ) { $this->database_options = $options; }
			public function get_database_options() { return null === $this->database_options ? $GLOBALS['bricks_ie_exporter_test']['options'] : $this->database_options; }
			private function serialize_value( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value; }
			private function unserialize_value( $value ) { $decoded = @unserialize( $value, array( 'allowed_classes' => false ) ); return false !== $decoded || 'b:0;' === $value ? $decoded : $value; }
			private function read_database_value( $name, &$exists ) {
				$store = $this->get_database_options();
				$exists = array_key_exists( $name, $store );
				return $exists ? $store[ $name ] : null;
			}
			private function write_database_value( $name, $value ) {
				if ( null === $this->database_options ) $GLOBALS['bricks_ie_exporter_test']['options'][ $name ] = $value;
				else $this->database_options[ $name ] = $value;
			}
			private function delete_database_value( $name ) {
				if ( null === $this->database_options ) unset( $GLOBALS['bricks_ie_exporter_test']['options'][ $name ] );
				else unset( $this->database_options[ $name ] );
			}
			public function get_var( $sql ) {
				$this->queries[] = $sql;
				$args = $this->prepared_args;
				if ( is_callable( $this->select_hook ) ) call_user_func( $this->select_hook, $sql, $args );
				if ( false === strpos( $sql, 'SELECT' ) || empty( $args ) ) return null;
				$exists = false;
				$value = $this->read_database_value( $args[0], $exists );
				return $exists ? $this->serialize_value( $value ) : null;
			}
			public function query( $sql ) {
				$this->queries[] = $sql;
				$args = $this->prepared_args;
				if ( is_callable( $this->query_hook ) ) call_user_func( $this->query_hook, $sql, $args );
				if ( 1 !== (int) $this->query_result ) return $this->query_result;
				if ( false !== strpos( $sql, 'UPDATE' ) && count( $args ) >= 3 ) {
					$exists = false;
					$current = $this->read_database_value( $args[1], $exists );
					if ( ! $exists || $this->serialize_value( $current ) !== $args[2] ) return 0;
					$this->write_database_value( $args[1], $this->unserialize_value( $args[0] ) );
					return 1;
				}
				if ( false !== strpos( $sql, 'DELETE' ) && count( $args ) >= 2 ) {
					$exists = false;
					$current = $this->read_database_value( $args[0], $exists );
					if ( ! $exists || $this->serialize_value( $current ) !== $args[1] ) return 0;
					$this->delete_database_value( $args[0] );
					return 1;
				}
				return 1;
			}
		}
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( $key, $group = '' ) { $GLOBALS['bricks_ie_cache_deletes'][] = array( $key, $group ); return true; }
	}
	if ( ! class_exists( 'Bricks_IE_Importer' ) ) require_once __DIR__ . '/test-importer-preflight.php';

	bricks_ie_test( 'import lock: atomic acquisition contends and only conservatively stale takeover uses CAS', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_cache_deletes'] = array();
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$importer = new Bricks_IE_Importer();
		$method = new ReflectionMethod( $importer, 'acquire_import_lease' );
		$method->setAccessible( true );
		bricks_ie_assert_same( true, $method->invoke( $importer, 'owner-a', 'session-a', 42, 'hash-a' ) );
		$GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ]['expires_at'] = time() - Bricks_IE_Importer::IMPORT_STALE_RECOVERY_SECONDS - 1;
		$GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ]['recover_after'] = time() - 1;
		bricks_ie_assert_same( true, $method->invoke( $importer, 'owner-b', 'session-b', 42, 'hash-b' ) );
		$cas = new ReflectionMethod( $importer, 'compare_swap_option' ); $cas->setAccessible( true );
		$GLOBALS['bricks_ie_exporter_test']['options']['lock'] = array( 'old' => 1 );
		bricks_ie_assert_same( true, $cas->invoke( $importer, 'lock', array( 'old' => 1 ), array( 'new' => 2 ) ), 'Conditional SQL CAS should be used for takeover.' );
	} );

	bricks_ie_test( 'import lock: an expired processing slot is never taken over by a step', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$id = 'expired-slot';
		$name = 'bricks_ie_import_processing_' . $id;
		$GLOBALS['bricks_ie_exporter_test']['options'][ $name ] = array( 'owner' => 'crashed-owner', 'expires_at' => time() - 3600, 'recover_after' => time() - 1 );
		$state = array( 'session_id' => $id );
		$method = new ReflectionMethod( new Bricks_IE_Importer(), 'acquire_processing_slot' );
		$method->setAccessible( true );
		bricks_ie_assert_same( false, $method->invokeArgs( new Bricks_IE_Importer(), array( &$state ) ) );
		bricks_ie_assert_same( 'crashed-owner', $GLOBALS['bricks_ie_exporter_test']['options'][ $name ]['owner'] );
	} );

	bricks_ie_test( 'import lock: stale owner cannot release newer owner and renewal is owner-bound', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_cache_deletes'] = array();
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$importer = new Bricks_IE_Importer();
		$acquire = new ReflectionMethod( $importer, 'acquire_import_lease' ); $acquire->setAccessible( true );
		$release = new ReflectionMethod( $importer, 'release_import_lease' ); $release->setAccessible( true );
		$renew = new ReflectionMethod( $importer, 'renew_import_lease' ); $renew->setAccessible( true );
		$acquire->invoke( $importer, 'owner-new', 'session-new', 42, 'hash-new' );
		$stale = array( 'lease_owner_hash' => 'owner-old', 'session_id' => 'session-old' );
		bricks_ie_assert_same( false, $release->invoke( $importer, $stale ) );
		bricks_ie_assert_same( false, $renew->invoke( $importer, array( 'lease_owner_hash' => 'owner-old', 'session_id' => 'session-old' ) ) );
	} );

	bricks_ie_test( 'import lock: same-second renewals monotonically extend the lease and invalidate option cache', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_cache_deletes'] = array();
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$importer = new Bricks_IE_Importer();
		$acquire = new ReflectionMethod( $importer, 'acquire_import_lease' ); $acquire->setAccessible( true );
		$renew = new ReflectionMethod( $importer, 'renew_import_lease' ); $renew->setAccessible( true );
		$release = new ReflectionMethod( $importer, 'release_import_lease' ); $release->setAccessible( true );
		bricks_ie_assert_same( true, $acquire->invoke( $importer, 'renew-owner', 'renew-session', 42, 'renew-hash' ) );
		$state = array( 'lease_owner_hash' => 'renew-owner', 'session_id' => 'renew-session' );
		$first = $GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ]['expires_at'];
		bricks_ie_assert_same( true, $renew->invoke( $importer, $state ) );
		$second = $GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ]['expires_at'];
		bricks_ie_assert( $second > $first, 'renewal must advance even within one second' );
		bricks_ie_assert_same( true, $renew->invoke( $importer, $state ) );
		$third = $GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ]['expires_at'];
		bricks_ie_assert( $third > $second );
		bricks_ie_assert( count( $GLOBALS['bricks_ie_cache_deletes'] ) >= 2 );
		bricks_ie_assert_same( true, $release->invoke( $importer, $state ) );
		bricks_ie_assert( count( $GLOBALS['bricks_ie_cache_deletes'] ) >= 3 );
	} );

	bricks_ie_test( 'import lock: stale option-cache owners cannot acquire, renew, release, or clean database ownership', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_cache_deletes'] = array();
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$now = time();
		$cache_lock = array( 'owner_token_hash' => 'cache-owner', 'session_id' => 'cache-session', 'expires_at' => $now - 10, 'recover_after' => $now - 1 );
		$db_lock = array( 'owner_token_hash' => 'db-owner', 'session_id' => 'db-session', 'expires_at' => $now + 600, 'recover_after' => $now + 3600 );
		$cache_slot = array( 'owner' => 'cache-slot-owner', 'session_id' => 'db-session', 'expires_at' => $now - 10 );
		$db_slot = array( 'owner' => 'db-slot-owner', 'session_id' => 'db-session', 'expires_at' => $now + 600, 'recover_after' => $now + 3600 );
		$slot_name = 'bricks_ie_import_processing_db-session';
		$GLOBALS['bricks_ie_exporter_test']['options'] = array( Bricks_IE_Importer::IMPORT_LOCK_OPTION => $cache_lock, $slot_name => $cache_slot );
		$GLOBALS['wpdb']->set_database_options( array( Bricks_IE_Importer::IMPORT_LOCK_OPTION => $db_lock, $slot_name => $db_slot ) );

		$importer = new Bricks_IE_Importer();
		$acquire = new ReflectionMethod( $importer, 'acquire_import_lease' ); $acquire->setAccessible( true );
		$renew = new ReflectionMethod( $importer, 'renew_import_lease' ); $renew->setAccessible( true );
		$release = new ReflectionMethod( $importer, 'release_import_lease' ); $release->setAccessible( true );
		$release_slot = new ReflectionMethod( $importer, 'release_processing_slot' ); $release_slot->setAccessible( true );
		$cleanup = new ReflectionMethod( $importer, 'cleanup_import_state' ); $cleanup->setAccessible( true );

		bricks_ie_assert_instance_of( 'WP_Error', $acquire->invoke( $importer, 'attacker', 'attacker-session', 42, 'hash' ) );
		bricks_ie_assert_same( false, $renew->invoke( $importer, array( 'lease_owner_hash' => 'cache-owner', 'session_id' => 'cache-session' ) ) );
		bricks_ie_assert_same( false, $release->invoke( $importer, array( 'lease_owner_hash' => 'cache-owner', 'session_id' => 'cache-session' ) ) );
		bricks_ie_assert_same( false, $release_slot->invoke( $importer, array( 'session_id' => 'db-session', '_processing_token' => 'cache-slot-owner' ) ) );
		bricks_ie_assert_same( false, $cleanup->invoke( $importer, array( 'session_id' => 'db-session', '_processing_token' => 'cache-slot-owner' ) ) );
		$db = $GLOBALS['wpdb']->get_database_options();
		bricks_ie_assert_same( 'db-owner', $db[ Bricks_IE_Importer::IMPORT_LOCK_OPTION ]['owner_token_hash'] );
		bricks_ie_assert_same( 'db-slot-owner', $db[ $slot_name ]['owner'] );

		bricks_ie_assert_same( true, $renew->invoke( $importer, array( 'lease_owner_hash' => 'db-owner', 'session_id' => 'db-session' ) ) );
		bricks_ie_assert_same( true, $release_slot->invoke( $importer, array( 'session_id' => 'db-session', '_processing_token' => 'db-slot-owner' ) ) );
		bricks_ie_assert_same( true, $release->invoke( $importer, array( 'lease_owner_hash' => 'db-owner', 'session_id' => 'db-session' ) ) );
		$db = $GLOBALS['wpdb']->get_database_options();
		bricks_ie_assert( ! isset( $db[ Bricks_IE_Importer::IMPORT_LOCK_OPTION ] ) && ! isset( $db[ $slot_name ] ) );
	} );

	bricks_ie_test( 'import lock: compare-swap and compare-delete fail closed when the cache-aware CAS fails', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_cache_deletes'] = array();
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$GLOBALS['wpdb']->query_result = 0;
		$importer = new Bricks_IE_Importer();
		$swap = new ReflectionMethod( $importer, 'compare_swap_option' ); $swap->setAccessible( true );
		$delete = new ReflectionMethod( $importer, 'compare_delete_option' ); $delete->setAccessible( true );
		bricks_ie_assert_same( false, $swap->invoke( $importer, 'cache-option', array( 'a' => 1 ), array( 'a' => 2 ) ) );
		bricks_ie_assert_same( false, $delete->invoke( $importer, 'cache-option', array( 'a' => 2 ) ) );
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_cache_deletes'] );
	} );
}
