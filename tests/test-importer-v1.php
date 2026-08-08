<?php
/** Isolated schema-v1 mutation-path regression tests for T6. */

namespace {
	if ( ! function_exists( 'bricks_ie_pf_v1_archive' ) ) {
		require_once __DIR__ . '/test-importer-preflight.php';
	}

	bricks_ie_test(
		'importer v1: malformed base64 fails before any write',
		function () {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$zip = bricks_ie_pf_v1_archive( array(
				'name'  => 'v1-bad-base64.zip',
				'posts' => array(
					array(
						'id' => 1, 'slug' => 'bad-base64', 'type' => 'page', 'status' => 'publish', 'title' => 'Bad',
						'meta' => array( '_bricks_page_content_2' => 'not-base64!' ),
					),
				),
			) );

			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'invalid_base64', $result->get_error_code() );
			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'] );
		}
	);

	bricks_ie_test(
		'importer v1: serialized objects fail before any write',
		function () {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$zip = bricks_ie_pf_v1_archive( array(
				'name'  => 'v1-object.zip',
				'posts' => array(
					array(
						'id' => 2, 'slug' => 'object-payload', 'type' => 'page', 'status' => 'publish', 'title' => 'Object',
						'meta' => array( '_bricks_page_content_2' => base64_encode( serialize( new stdClass() ) ) ),
					),
				),
			) );

			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'serialized_object', $result->get_error_code() );
			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'] );
		}
	);

	bricks_ie_test(
		'importer v1: unauthorized keys never write and valid result keys stay stable',
		function () {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$zip = bricks_ie_pf_v1_archive( array(
				'name' => 'v1-allowlists.zip',
				'options' => array(
					'bricks_global_settings' => array( 'safe' => true ),
					'not_allowed_option'     => array( 'secret' => true ),
				),
				'posts' => array(
					array(
						'id' => 3, 'slug' => 'allowlisted', 'type' => 'page', 'status' => 'publish', 'title' => 'Allowed',
						'meta' => array(
							'_bricks_page_content_2' => base64_encode( serialize( array( 'ok' => true ) ) ),
						),
					),
				),
			) );

			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
			bricks_ie_assert( is_array( $result ), 'Valid v1 import should return its public result array.' );
			bricks_ie_assert_same( array( 'posts_imported', 'options_imported', 'id_remaps' ), array_keys( $result ) );
			bricks_ie_assert_same( 1, $result['posts_imported'] );
			bricks_ie_assert_same( 1, $result['options_imported'] );
			foreach ( $GLOBALS['bricks_ie_pf_write_log'] as $call ) {
				if ( 'update_option' === $call['name'] ) {
					bricks_ie_assert_same( 'bricks_global_settings', $call['args'][0] );
				}
				if ( in_array( $call['name'], array( 'add_post_meta', 'delete_post_meta' ), true ) ) {
					bricks_ie_assert( '_not_allowed' !== $call['args'][1], 'Forbidden meta key must never be written or deleted.' );
				}
			}
			}
	);

	bricks_ie_test(
		'importer v1: unauthorized meta fails before any write',
		function () {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$zip = bricks_ie_pf_v1_archive( array(
				'name'  => 'v1-forbidden-meta.zip',
				'posts' => array(
					array(
						'id' => 4, 'slug' => 'forbidden-meta', 'type' => 'page', 'status' => 'publish', 'title' => 'Forbidden',
						'meta' => array( '_not_allowed' => base64_encode( serialize( 'secret' ) ) ),
					),
				),
			) );

			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
			bricks_ie_assert_instance_of( 'WP_Error', $result );
			bricks_ie_assert_same( 'forbidden_meta_key', $result->get_error_code() );
			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'] );
		}
	);

	bricks_ie_test(
		'importer v1: signatures are not approved and cache cleanup is not broad',
		function () {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$cache_dir = bricks_ie_test_temp_dir();
			$cache_file = $cache_dir . DIRECTORY_SEPARATOR . 'unrelated.html';
			file_put_contents( $cache_file, 'keep me' );

			$importer = new Bricks_IE_Importer();
			$method   = new ReflectionMethod( $importer, 'run_scoped_cache_cleanup' );
			$method->setAccessible( true );
			$method->invoke( $importer );

			bricks_ie_assert( file_exists( $cache_file ), 'Unrelated cache HTML must not be deleted.' );
			bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'], 'Cache cleanup must not flush or write.' );
			bricks_ie_assert( false === strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-bricks-importer.php' ), "call_user_func( array( '\\Bricks\\Admin'" ), 'Automatic signature approval must never run.' );
		}
	);

	bricks_ie_test( 'importer v1: Bricks 2.4 sensitive global settings are stripped by default and retained when authorized', function () {
		$settings = array(
			'ordinary' => 'keep',
			'apiKeyGoogleMaps' => 'secret-map',
			'customCode' => 'secret-code',
			'executeCodeEnabled' => true,
			'myTemplatesPassword' => 'secret-password',
			'remoteTemplatesPassword' => 'secret-remote-password',
			'remoteTemplates' => array(
				array( 'url' => 'https://remote.example', 'password' => 'secret-nested-password', 'pass' => 'secret-nested-pass', 'name' => 'keep' ),
			),
			'pass' => 'secret-top-level-pass',
		);
		foreach ( array( false, true ) as $allow ) {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$zip = bricks_ie_pf_v1_archive( array( 'name' => $allow ? 'v1-sensitive-allow.zip' : 'v1-sensitive-strip.zip', 'options' => array( 'bricks_global_settings' => $settings ) ) );
			$request = $allow ? array( 'allow_sensitive_settings' => true ) : array();
			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip, $request );
			bricks_ie_assert( is_array( $result ) );
			$written = $GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'];
			bricks_ie_assert_same( 'keep', $written['ordinary'] );
			foreach ( array( 'apiKeyGoogleMaps', 'customCode', 'executeCodeEnabled', 'myTemplatesPassword', 'remoteTemplatesPassword', 'pass' ) as $key ) {
				bricks_ie_assert_same( $allow, array_key_exists( $key, $written ), $key . ' sensitive key policy' );
			}
			bricks_ie_assert_same( $allow, isset( $written['remoteTemplates'][0]['password'] ), 'Nested remote template password policy' );
			bricks_ie_assert_same( $allow, isset( $written['remoteTemplates'][0]['pass'] ), 'Nested remote template pass policy' );
			bricks_ie_assert_same( 'keep', $written['remoteTemplates'][0]['name'] );
		}
	} );

	bricks_ie_test( 'importer v1: legacy draft, pending, and private posts with empty slugs are skipped without post mutation', function () {
		foreach ( array( 'draft', 'pending', 'private' ) as $index => $status ) {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$zip = bricks_ie_pf_v1_archive( array( 'name' => 'v1-empty-slug-' . $status . '.zip', 'options' => array(), 'posts' => array( array(
				'id' => 20 + $index,
				'slug' => '',
				'type' => 'page',
				'status' => $status,
				'title' => ucfirst( $status ),
				'meta' => array( '_bricks_page_content_2' => base64_encode( serialize( array( 'status' => $status ) ) ) ),
			) ) ) );

			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
			bricks_ie_assert( is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Expected an import result.' );
			bricks_ie_assert_same( 0, $result['posts_imported'] );
			foreach ( $GLOBALS['bricks_ie_pf_write_log'] as $call ) {
				bricks_ie_assert( ! in_array( $call['name'], array( 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'add_post_meta', 'update_post_meta', 'delete_post_meta' ), true ), 'Empty-slug ' . $status . ' records must not mutate posts.' );
			}
		}
	} );

	bricks_ie_test( 'importer v1: non-array bricks_global_settings fails before update_option', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$zip = bricks_ie_pf_v1_archive( array( 'name' => 'v1-invalid-settings.zip', 'options' => array( 'bricks_global_settings' => 'not-an-array' ) ) );
		$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'invalid_global_settings', $result->get_error_code() );
		bricks_ie_assert_same( array(), array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return 'update_option' === $call['name']; } ) );
	} );

	bricks_ie_test( 'importer v1: both unserialize paths expose a positive max depth contract', function () {
		$importer = new Bricks_IE_Importer();
		$method = new ReflectionMethod( $importer, 'get_max_meta_depth' );
		$method->setAccessible( true );
		bricks_ie_assert( $method->invoke( $importer ) > 0 );
		$decode = new ReflectionMethod( $importer, 'decode_legacy_meta_value' );
		$decode->setAccessible( true );
		$deep = 'a';
		for ( $i = 0; $i < 300; $i++ ) $deep = array( $deep );
		$result = $decode->invoke( $importer, base64_encode( serialize( $deep ) ), '_bricks_page_content_2', 'posts/deep.json' );
		bricks_ie_assert_instance_of( 'WP_Error', $result, 'deep serialized input must be bounded' );
	} );

	bricks_ie_test( 'importer v1: direct replace requires explicit overwrite authorization', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$zip = bricks_ie_pf_v1_archive( array( 'name' => 'v1-direct-replace-auth.zip' ) );
		$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip, array( 'conflict_mode' => 'replace' ) );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'bricks_ie_overwrite_requires_authorization', $result->get_error_code() );
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'], 'Rejected direct replace must fail before importer writes.' );
	} );

	bricks_ie_test( 'importer v1: skip mappings remap imported references without touching skipped post meta', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$existing = (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'existing', 'post_title' => 'Existing', 'post_status' => 'publish' );
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( $existing );
		$GLOBALS['bricks_ie_exporter_test']['post_meta'][77] = array(
			'_bricks_page_content_2' => array( 'postId' => 2, 'marker' => 'keep' ),
		);
		$zip = bricks_ie_pf_v1_archive( array(
			'name'    => 'v1-skip-reference-map.zip',
			'options' => array(),
			'posts'   => array(
				array( 'id' => 1, 'slug' => 'existing', 'type' => 'page', 'status' => 'publish', 'title' => 'Archive Existing', 'meta' => array( '_bricks_page_content_2' => base64_encode( serialize( array( 'archive' => true ) ) ) ) ),
				array( 'id' => 2, 'slug' => 'created', 'type' => 'page', 'status' => 'publish', 'title' => 'Created', 'meta' => array( '_bricks_page_content_2' => base64_encode( serialize( array( 'postId' => 1 ) ) ) ) ),
			),
		) );

		$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
		bricks_ie_assert( is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Expected an import result.' );
		bricks_ie_assert_same( 1, $result['posts_imported'] );
		bricks_ie_assert_same( array( 'postId' => 2, 'marker' => 'keep' ), $GLOBALS['bricks_ie_exporter_test']['post_meta'][77]['_bricks_page_content_2'] );
		bricks_ie_assert_same( 77, $GLOBALS['bricks_ie_exporter_test']['post_meta'][101]['_bricks_page_content_2']['postId'], 'Imported post references should still resolve through the skip mapping.' );
		foreach ( $GLOBALS['bricks_ie_pf_write_log'] as $call ) {
			if ( in_array( $call['name'], array( 'add_post_meta', 'update_post_meta', 'delete_post_meta' ), true ) ) bricks_ie_assert( 77 !== (int) $call['args'][0], 'Skipped post metadata must remain immutable.' );
		}
	} );

	bricks_ie_test( 'importer v1: existing options skip by default and replace only when authorized', function () {
		foreach ( array( false, true ) as $replace ) {
			bricks_ie_pf_reset();
			$GLOBALS['bricks_ie_pf_spy_mode'] = true;
			$GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'] = array( 'postId' => 7, 'marker' => 'existing' );
			$zip = bricks_ie_pf_v1_archive( array(
				'name'    => $replace ? 'v1-option-replace.zip' : 'v1-option-skip.zip',
				'options' => array( 'bricks_global_settings' => array( 'postId' => 7, 'marker' => 'archive' ) ),
			) );
			$request = $replace ? array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) : array();
			$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip, $request );
			bricks_ie_assert( is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Expected an import result.' );
			bricks_ie_assert_same( $replace ? 1 : 0, $result['options_imported'] );
			$value = $GLOBALS['bricks_ie_exporter_test']['options']['bricks_global_settings'];
			bricks_ie_assert_same( $replace ? 'archive' : 'existing', $value['marker'] );
			bricks_ie_assert_same( $replace ? 101 : 7, $value['postId'], 'Only an option actually imported may be remapped.' );
		}
	} );

	bricks_ie_test( 'importer v1: archive byte changes stop the next stage before writes', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$zip = bricks_ie_pf_v1_archive( array( 'name' => 'v1-hash-change.zip' ) );
		$importer = new Bricks_IE_Importer();
		$create = new ReflectionMethod( $importer, 'create_import_state' );
		$create->setAccessible( true );
		$state = $create->invoke( $importer, $zip );
		bricks_ie_assert( is_array( $state ) );

		$archive = new ZipArchive();
		bricks_ie_assert_same( true, $archive->open( $zip ) );
		bricks_ie_assert_same( true, $archive->setArchiveComment( 'changed-after-validation' ) );
		bricks_ie_assert_same( true, $archive->close() );
		$GLOBALS['bricks_ie_pf_write_log'] = array();

		$advance = new ReflectionMethod( $importer, 'advance_import_state' );
		$advance->setAccessible( true );
		$args = array( &$state, 1 );
		$result = $advance->invokeArgs( $importer, $args );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'archive_changed', $result->get_error_code() );
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'] );
	} );
}
