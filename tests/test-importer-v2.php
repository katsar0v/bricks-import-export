<?php
/** Schema-v2 mutation gate and result-contract tests. */

namespace {
	if ( ! function_exists( 'bricks_ie_pf_v2_archive' ) ) require_once __DIR__ . '/test-importer-preflight.php';

	bricks_ie_test( 'importer v2: mutation requires backup acknowledgement and does not write', function () {
		bricks_ie_pf_reset();
		$zip = bricks_ie_pf_v2_archive( array( 'name' => 'v2-gated.zip' ) );
		$result = ( new Bricks_IE_Importer() )->import_from_zip( $zip );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'backup_acknowledgement_required', $result->get_error_code() );
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
	} );

	bricks_ie_test( 'importer v2: result contract includes additive native and post status fields', function () {
		$shape = new ReflectionMethod( 'Bricks_IE_Importer', 'get_v2_result_skeleton' );
		$shape->setAccessible( true );
		$result = $shape->invoke( new Bricks_IE_Importer() );
		foreach ( array( 'posts_imported', 'options_imported', 'id_remaps', 'native_result', 'completed_steps', 'mappings' ) as $key ) bricks_ie_assert( array_key_exists( $key, $result ), 'v2 result contract is missing ' . $key );
		$method = new ReflectionMethod( 'Bricks_IE_Importer', 'get_v2_native_order' );
		$method->setAccessible( true );
		bricks_ie_assert_same( array( 'settings', 'breakpoints', 'color-palettes', 'theme-styles', 'classes', 'variables', 'custom-fonts', 'icon-manager', 'global-queries', 'components', 'templates', 'custom-capabilities' ), $method->invoke( new Bricks_IE_Importer() ) );
	} );

	bricks_ie_test( 'importer v2: admin outcome report preserves native and content statuses', function () {
		$method = new ReflectionMethod( 'Bricks_IE_Importer', 'get_v2_outcome_report' );
		$method->setAccessible( true );
		$report = $method->invoke(
			new Bricks_IE_Importer(),
			array(
				'native_result' => array(
					'templates' => array(
						'success' => true,
						'results' => array(
							'templates' => array(
								'items' => array(
									array( 'label' => 'New template', 'status' => 'imported' ),
									array( 'label' => 'Existing template', 'status' => 'replaced' ),
									array( 'label' => 'Skipped template', 'status' => 'skipped' ),
								),
							),
						),
					),
					'settings' => 'native_failed',
				),
				'created' => array( 'new-page' ),
				'updated' => array( 'changed-page' ),
				'skipped' => array( 'old-page' ),
				'failed'  => array( 'settings', 'new-page', 'assets' ),
			)
		);

		bricks_ie_assert_same( 1, $report['counts']['native_imported'] );
		bricks_ie_assert_same( 1, $report['counts']['native_replaced'] );
		bricks_ie_assert_same( 1, $report['counts']['native_skipped'] );
		bricks_ie_assert_same( 1, $report['counts']['content_created'] );
		bricks_ie_assert_same( 1, $report['counts']['content_updated'] );
		bricks_ie_assert_same( 1, $report['counts']['content_skipped'] );
		bricks_ie_assert_same( 3, $report['counts']['failed'] );
		bricks_ie_assert_same( 9, count( $report['items'] ) );
		bricks_ie_assert_same( 'replaced', $report['items'][1]['status'] );
		bricks_ie_assert_same( 'content', $report['items'][7]['scope'] );
		bricks_ie_assert_same( 'failed', $report['items'][7]['status'] );
	} );

	bricks_ie_test( 'importer: public import rejects user 0 and non-admin before archive validation', function () {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_session_user'] = 0;
		$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = false;
		$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;
		$result = ( new Bricks_IE_Importer() )->import_from_zip( '/path/that-must-not-be-opened.zip' );
		bricks_ie_assert_instance_of( 'WP_Error', $result );
		bricks_ie_assert_same( 'import_auth_required', $result->get_error_code() );
		bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_preflight_test']['write_calls'] );
		$GLOBALS['bricks_ie_session_user'] = 42;
	} );
}
