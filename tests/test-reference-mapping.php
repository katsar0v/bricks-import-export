<?php
/** Conservative typed reference and media normalization tests. */

namespace {
	if ( ! class_exists( 'Bricks_IE_Importer' ) ) {
		require_once __DIR__ . '/test-importer-preflight.php';
	}

	bricks_ie_test(
		'reference mapping: only documented post fields are remapped',
		function () {
			$importer = new Bricks_IE_Importer();
			$map     = new ReflectionMethod( $importer, 'recursive_replace_ids' );
			$map->setAccessible( true );
			$value = array(
				'postId'        => 7,
				'templateId'    => '8',
				'query'         => array( 'no_results_template' => 9 ),
				'settings'      => array( 'template' => 10 ),
				'_conditions'   => array( array( 'ids' => array( 7, 11 ) ) ),
				'id'            => 7,
				'parent'        => 7,
				'children'      => array( 7 ),
				'css'           => '7px #7',
				'dynamic'       => '{post_id:7}',
				'code'          => 'return 7;',
				'external_url'  => 'https://source.example/7',
				'arbitrary_int' => 7,
			);

			$actual = $map->invoke( $importer, $value, array( 7 => 107, 8 => 108, 9 => 109, 10 => 110, 11 => 111 ) );

			bricks_ie_assert_same( 107, $actual['postId'] );
			bricks_ie_assert_same( '108', $actual['templateId'] );
			bricks_ie_assert_same( 109, $actual['query']['no_results_template'] );
			bricks_ie_assert_same( 110, $actual['settings']['template'] );
			bricks_ie_assert_same( array( 107, 111 ), $actual['_conditions'][0]['ids'] );
			bricks_ie_assert_same( 7, $actual['id'] );
			bricks_ie_assert_same( 7, $actual['parent'] );
			bricks_ie_assert_same( array( 7 ), $actual['children'] );
			bricks_ie_assert_same( '7px #7', $actual['css'] );
			bricks_ie_assert_same( '{post_id:7}', $actual['dynamic'] );
			bricks_ie_assert_same( 'return 7;', $actual['code'] );
			bricks_ie_assert_same( 'https://source.example/7', $actual['external_url'] );
			bricks_ie_assert_same( 7, $actual['arbitrary_int'] );
		}
	);

	bricks_ie_test(
		'reference mapping: source URLs change only in recognized media fields',
		function () {
			$importer = new Bricks_IE_Importer();
			$property = new ReflectionProperty( $importer, 'source_site_url' );
			$property->setAccessible( true );
			$property->setValue( $importer, 'https://origin.example' );
			$method = new ReflectionMethod( $importer, 'recursive_normalize_imported_media' );
			$method->setAccessible( true );
			$value = array(
				'media'        => array( 'id' => 20, 'url' => 'https://origin.example/uploads/a.jpg', 'full' => 'https://origin.example/uploads/a.jpg', 'filename' => 'a.jpg' ),
				'external_url' => 'https://origin.example/keep-this',
				'text'         => 'https://origin.example/keep-this-too',
			);

			$actual = $method->invoke( $importer, $value );
			bricks_ie_assert_same( 'https://source.example/uploads/a.jpg', $actual['media']['url'] );
			bricks_ie_assert_same( 'https://source.example/uploads/a.jpg', $actual['media']['full'] );
			bricks_ie_assert_same( 'https://origin.example/keep-this', $actual['external_url'] );
			bricks_ie_assert_same( 'https://origin.example/keep-this-too', $actual['text'] );
		}
	);

	bricks_ie_test(
		'reference mapping: v2 scanner reports only conservative future reference candidates',
		function () {
			$importer = new Bricks_IE_Importer();
			$method   = new ReflectionMethod( $importer, 'scan_typed_reference_candidates' );
			$method->setAccessible( true );
			$found = $method->invoke( $importer, array(
				'_cssGlobalClasses' => array( 'foo' ),
				'cid'               => 'abc123',
				'queryId'           => 'q1',
				'fontId'            => 'font-1',
				'plain'             => array( 'id' => 7 ),
			) );

			bricks_ie_assert( in_array( '_cssGlobalClasses', $found, true ) );
			bricks_ie_assert( in_array( 'cid', $found, true ) );
			bricks_ie_assert( ! in_array( 'queryId', $found, true ) );
			bricks_ie_assert( in_array( 'fontId', $found, true ) );
			bricks_ie_assert( ! in_array( 'plain.id', $found, true ) );
		}
	);

	bricks_ie_test( 'reference mapping: duplicate template labels use category/type and remain ambiguous without it', function () {
		$importer = new Bricks_IE_Importer();
		$method = new ReflectionMethod( $importer, 'derive_native_identity_map' ); $method->setAccessible( true );
		$source = array( array( 'id' => 'source-header', 'label' => 'Shared', 'category' => 'header' ), array( 'id' => 'source-ambiguous', 'label' => 'Shared' ) );
		$listed = array( 'types' => array( 'templates' => array( array( 'id' => 'target-header', 'label' => 'Shared', 'category' => 'header' ), array( 'id' => 'target-footer', 'label' => 'Shared', 'category' => 'footer' ) ) ) );
		$map = $method->invoke( $importer, $source, $listed, 'templates' );
		bricks_ie_assert_same( array( 'source-header' => 'target-header' ), $map );
	} );
}
