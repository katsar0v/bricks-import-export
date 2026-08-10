<?php
/**
 * Behavioural schema-v2 importer tests.
 *
 * These tests deliberately exercise the public importer entry points with a
 * real outer ZIP, a real archive validator, an injected stateful transfer
 * adapter, and process-local WordPress stores.  They do not inspect source
 * text or invoke private implementation details.
 */

namespace {
	if ( ! function_exists( 'bricks_ie_pf_v2_archive' ) ) {
		require_once __DIR__ . '/test-importer-preflight.php';
	}

	if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			$items = isset( $GLOBALS['bricks_ie_pf_transients'] ) ? $GLOBALS['bricks_ie_pf_transients'] : array();
			return array_key_exists( $key, $items ) ? $items[ $key ] : false;
		}
	}
	if ( ! function_exists( 'get_post_type' ) ) {
		function get_post_type( $id ) { return 'attachment' === ( isset( $GLOBALS['bricks_ie_v2_media'][ $id ]['type'] ) ? $GLOBALS['bricks_ie_v2_media'][ $id ]['type'] : '' ) ? 'attachment' : ''; }
	}
	if ( ! function_exists( 'wp_get_attachment_url' ) ) {
		function wp_get_attachment_url( $id ) { return isset( $GLOBALS['bricks_ie_v2_media'][ $id ]['full'] ) ? $GLOBALS['bricks_ie_v2_media'][ $id ]['full'] : false; }
	}
	if ( ! function_exists( 'wp_attachment_is_image' ) ) {
		function wp_attachment_is_image( $id ) { return ! empty( $GLOBALS['bricks_ie_v2_media'][ $id ]['image'] ); }
	}
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
		function wp_get_attachment_image_url( $id, $size ) { return isset( $GLOBALS['bricks_ie_v2_media'][ $id ][ $size ] ) ? $GLOBALS['bricks_ie_v2_media'][ $id ][ $size ] : wp_get_attachment_url( $id ); }
	}
	if ( ! function_exists( 'attachment_url_to_postid' ) ) {
		function attachment_url_to_postid( $url ) { return isset( $GLOBALS['bricks_ie_v2_media_by_url'][ $url ] ) ? $GLOBALS['bricks_ie_v2_media_by_url'][ $url ] : 0; }
	}
	if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
	if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); } }
	if ( ! function_exists( 'set_url_scheme' ) ) { function set_url_scheme( $url, $scheme = null ) { return preg_replace( '#^[a-z]+://#i', $scheme . '://', $url ); } }
	if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); } }

	if ( ! class_exists( 'Bricks_IE_V2_Stateful_Validator' ) ) {
		class Bricks_IE_V2_Stateful_Validator {
			public $calls = 0;
			private $delegate;
			public function __construct() { $this->delegate = new Bricks_IE_Archive_Validator(); }
			public function validate( $path ) { $this->calls++; return $this->delegate->validate( $path ); }
		}
	}
	if ( ! class_exists( 'Bricks_IE_V2_Stateful_Adapter' ) ) {
		class Bricks_IE_V2_Stateful_Adapter {
			const EXPECTED_SCHEMA = 'bricks/unified-global-transfer';
			const EXPECTED_VERSION = 1;
			const KNOWN_TYPE_IDS = array( 'settings', 'breakpoints', 'color-palettes', 'theme-styles', 'classes', 'variables', 'custom-fonts', 'icon-manager', 'global-queries', 'components', 'templates', 'custom-capabilities' );
			const SENSITIVE_SETTINGS_IDS = array( 'api-keys' );
			const CODE_BEARING_TYPES = array( 'theme-styles', 'global-queries', 'components', 'custom-capabilities' );
			public $calls = array();
			public $import_results = array();
			public $list_results = array();
			public $css_result = array( 'success' => true );
			public $css_callback;
			public $inspect;
			public function detect_capabilities() { $this->calls[] = array( 'detect_capabilities' ); return array( 'available' => true, 'schema' => self::EXPECTED_SCHEMA, 'version' => 1, 'errors' => array() ); }
			public function inspect_package( $bytes ) { $this->calls[] = array( 'inspect_package', strlen( $bytes ) ); return $this->inspect; }
			public function import_package( $bytes, $selection, $policy ) {
				$type = $selection['types'][0];
				$this->calls[] = array( 'import_package', $type, $policy );
				return array_key_exists( $type, $this->import_results ) ? $this->import_results[ $type ] : array( 'success' => true, 'type' => $type );
			}
			public function list_items( $types ) { $type = $types[0]; $this->calls[] = array( 'list_items', $type ); return isset( $this->list_results[ $type ] ) ? $this->list_results[ $type ] : array( 'types' => array() ); }
			public function regenerate_css_files() { $this->calls[] = array( 'regenerate_css_files' ); if ( is_callable( $this->css_callback ) ) call_user_func( $this->css_callback ); return $this->css_result; }
		}
	}
	if ( ! class_exists( 'Bricks_IE_V2_Incomplete_Adapter' ) ) {
		class Bricks_IE_V2_Incomplete_Adapter {}
	}

	function bricks_ie_v2_reset_store() {
		bricks_ie_pf_reset();
		$GLOBALS['bricks_ie_pf_spy_mode'] = true;
		$GLOBALS['bricks_ie_exporter_test']['options'] = array();
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array();
		$GLOBALS['bricks_ie_exporter_test']['post_meta'] = array();
		$GLOBALS['bricks_ie_v2_media'] = array();
		$GLOBALS['bricks_ie_v2_media_by_url'] = array();
		$GLOBALS['bricks_ie_session_user'] = 42;
	}

	function bricks_ie_v2_native_types( array $overrides = array() ) {
		$ids = array( 'settings', 'breakpoints', 'color-palettes', 'theme-styles', 'classes', 'variables', 'custom-fonts', 'icon-manager', 'global-queries', 'components', 'templates', 'custom-capabilities' );
		$out = array();
		foreach ( $ids as $id ) $out[ $id ] = array( 'id' => $id, 'items' => array( array( 'id' => $id . '-source', 'label' => ucfirst( $id ) ) ) );
		return array_replace_recursive( $out, $overrides );
	}

	function bricks_ie_v2_fixture( array $posts, array $types = array(), $name = 'integration-v2.zip' ) {
		return bricks_ie_pf_v2_archive( array( 'name' => $name, 'posts' => $posts, 'native_types' => $types ) );
	}

	function bricks_ie_v2_post( $id, $slug, array $content = array(), $type = 'page' ) {
		return array( 'id' => $id, 'slug' => $slug, 'type' => $type, 'status' => 'publish', 'title' => ucfirst( $slug ), 'meta' => array( '_bricks_page_content_2' => $content ) );
	}

	function bricks_ie_v2_importer( $validator, $adapter ) { return new Bricks_IE_Importer( array( 'archive_validator' => $validator, 'transfer_adapter' => $adapter ) ); }

	function bricks_ie_v2_staged_state( $importer, $zip, $report, $step ) {
		$shape = new ReflectionMethod( $importer, 'get_v2_result_skeleton' );
		$shape->setAccessible( true );
		return array(
			'session_id' => 'staged-v2', 'state_version' => Bricks_IE_Importer::IMPORT_STATE_VERSION, 'user_id' => 42,
			'zip_path' => $zip, 'archive_hash' => hash_file( 'sha256', $zip ), 'format_version' => 2, 'status' => 'confirmed',
			'source_site_url' => '', 'step' => $step, 'done' => false, 'preflight' => $report,
			'v2_native_index' => 0, 'v2_native_order' => array( 'settings', 'breakpoints', 'color-palettes', 'theme-styles', 'classes', 'variables', 'custom-fonts', 'icon-manager', 'global-queries', 'components', 'templates', 'custom-capabilities' ),
			'v2_result' => $shape->invoke( $importer ), 'native_identity_maps' => array(), 'native_source_ids' => array(), 'id_map' => array(),
			'posts_processed' => 0, 'options_processed' => 0, 'posts_total' => isset( $report['posts'] ) ? count( $report['posts'] ) : 0,
			'options_total' => 0, 'posts_imported' => 0, 'options_imported' => 0, 'completed_steps' => array(), 'total_units' => 2,
		);
	}

	function bricks_ie_v2_confirm( $importer, $zip, array $request = array() ) {
		$policy = array_merge( array( 'backup_acknowledged' => true ), $request );
		$report = $importer->preflight( $zip, $policy );
		bricks_ie_assert( is_array( $report ), 'Fixture preflight must produce a report.' );
		$policy['preflight'] = $report;
		return array( $report, $policy );
	}

	bricks_ie_test( 'v2 integration: exact preflight archive hash is required', function () {
		bricks_ie_v2_reset_store(); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'hash-page' ) ), array(), 'exact-hash.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter );
		$report = $importer->preflight( $zip, array() ); $result = $importer->import_from_zip( $zip, array( 'backup_acknowledged' => true, 'preflight' => array( 'plan' => $report['plan'], 'archive_hash' => str_repeat( '0', 64 ) ) ) );
		bricks_ie_assert_instance_of( 'WP_Error', $result ); bricks_ie_assert_same( 'preflight_confirmation_required', $result->get_error_code() ); bricks_ie_assert_same( array(), $GLOBALS['bricks_ie_pf_write_log'] );
	} );

	bricks_ie_test( 'v2 integration: skip policy never authorizes native replacement', function () {
		bricks_ie_v2_reset_store(); $types = bricks_ie_v2_native_types( array( 'classes' => array( 'items' => array( array( 'id' => 'classes-source', 'label' => 'Class', 'conflict' => array( 'message' => 'collision' ) ) ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array(), $types, 'skip-policy.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter );
		list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'skip', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert( is_array( $result ) ); foreach ( $adapter->calls as $call ) if ( 'import_package' === $call[0] ) bricks_ie_assert_same( 'skip', $call[2]['conflict_mode'] );
	} );

	bricks_ie_test( 'v2 integration: replace reaches native only when selected and authorized', function () {
		bricks_ie_v2_reset_store(); $types = bricks_ie_v2_native_types( array( 'classes' => array( 'items' => array( array( 'id' => 'classes-source', 'label' => 'Class', 'conflict' => array( 'message' => 'collision' ) ) ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array(), $types, 'replace-policy.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter );
		list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request ); bricks_ie_assert( is_array( $result ) ); $calls = array_filter( $adapter->calls, function ( $call ) { return 'import_package' === $call[0]; } ); bricks_ie_assert( count( $calls ) > 0 ); foreach ( $calls as $call ) bricks_ie_assert_same( 'replace', $call[2]['conflict_mode'] );
	} );

	bricks_ie_test( 'v2 integration: template image intent reuses a matching attachment and reaches Bricks', function () {
		bricks_ie_v2_reset_store();
		$GLOBALS['bricks_ie_v2_media'][2298] = array(
			'type'  => 'attachment',
			'image' => true,
			'full'  => 'https://target.example/wp-content/uploads/Logo-dark.svg',
		);
		$GLOBALS['bricks_ie_v2_media'][2299] = array(
			'type'  => 'attachment',
			'image' => true,
			'full'  => 'https://target.example/wp-content/uploads/unrelated.svg',
		);
		$types = array(
			'templates' => array(
				'id'    => 'templates',
				'label' => 'Templates',
				'items' => array(
					array( 'id' => '2289', 'label' => 'Header Template', 'category' => 'header', 'path' => 'structure/templates/header.json' ),
				),
			),
		);
		$template = array(
			'title' => 'Header Template',
			'header' => array(
				array(
					'name' => 'logo',
					'settings' => array(
						'logo' => array(
							'id' => 2298,
							'filename' => 'Logo-dark.svg',
							'size' => 'full',
							'full' => 'https://source.example/wp-content/uploads/Logo-dark.svg',
							'url' => 'https://source.example/wp-content/uploads/Logo-dark.svg',
						),
					),
				),
				array(
					'name' => 'image',
					'settings' => array(
						'image' => array(
							'id' => 2299,
							'filename' => 'different.svg',
							'url' => 'https://source.example/wp-content/uploads/different.svg',
						),
					),
				),
			),
		);
		$zip = bricks_ie_pf_v2_archive( array(
			'name'         => 'template-images.zip',
			'posts'        => array(),
			'native_types' => $types,
			'native_files' => array( 'structure/templates/header.json' => json_encode( $template ) ),
		) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) );
		$adapter->list_results['templates'] = array( 'types' => array( array( 'id' => '2289', 'label' => 'Header Template', 'category' => 'header' ) ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'import_images' => true ) );
		$result = $importer->import_from_zip( $zip, $request );

		bricks_ie_assert_same( 'completed', $result['status'] );
		bricks_ie_assert_same( 1, $result['media_reused'] );
		bricks_ie_assert_same( 'https://source.example/wp-content/uploads/Logo-dark.svg', $GLOBALS['bricks_ie_exporter_test']['post_meta'][2298]['_bricks_image_origin_url'] );
		bricks_ie_assert( ! isset( $GLOBALS['bricks_ie_exporter_test']['post_meta'][2299]['_bricks_image_origin_url'] ), 'A coincidental numeric ID must not reuse an unrelated attachment.' );
		$imports = array_values( array_filter( $adapter->calls, function ( $call ) { return 'import_package' === $call[0] && 'templates' === $call[1]; } ) );
		bricks_ie_assert_same( 1, count( $imports ) );
		bricks_ie_assert_same( true, $imports[0][2]['import_images'] );
	} );

	bricks_ie_test( 'v2 integration: native stages use canonical order and stop after failure', function () {
		bricks_ie_v2_reset_store(); $types = bricks_ie_v2_native_types(); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->import_results['classes'] = array( 'success' => false ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array(), $types, 'native-failure.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert_same( 'partial', $result['status'] ); $imports = array_values( array_filter( $adapter->calls, function ( $call ) { return 'import_package' === $call[0]; } ) ); bricks_ie_assert_same( 'settings', $imports[0][1] ); bricks_ie_assert_same( 'breakpoints', $imports[1][1] ); bricks_ie_assert_same( 'color-palettes', $imports[2][1] ); bricks_ie_assert_same( 'theme-styles', $imports[3][1] ); bricks_ie_assert_same( 'classes', $imports[4][1] ); bricks_ie_assert_same( 5, count( $imports ) );
	} );

	bricks_ie_test( 'v2 integration: native WP_Error is partial and is not retried', function () {
		bricks_ie_v2_reset_store(); $types = bricks_ie_v2_native_types(); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->import_results['settings'] = new WP_Error( 'native_boom', 'boom' ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array(), $types, 'native-wp-error.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request ); bricks_ie_assert_same( 'partial', $result['status'] ); bricks_ie_assert_same( 'native_boom', $result['native_result']['settings'] ); $count = count( array_filter( $adapter->calls, function ( $call ) { return 'import_package' === $call[0] && 'settings' === $call[1]; } ) ); bricks_ie_assert_same( 1, $count );
	} );

	bricks_ie_test( 'v2 integration: CSS regeneration is called and failure marks partial', function () {
		bricks_ie_v2_reset_store(); $types = array( 'classes' => array( 'id' => 'classes', 'items' => array( array( 'id' => 'c', 'label' => 'C' ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->css_result = new WP_Error( 'css_failed', 'css failed' ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array(), $types, 'css-failure.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request ); bricks_ie_assert_same( 'partial', $result['status'] ); bricks_ie_assert_same( 1, count( array_filter( $adapter->calls, function ( $call ) { return 'regenerate_css_files' === $call[0]; } ) ) ); bricks_ie_assert_same( 1, count( array_filter( $result['failed'], function ( $step ) { return 'assets' === $step; } ) ) ); bricks_ie_assert( ! in_array( 'assets', $result['completed_steps'], true ) );
	} );

	bricks_ie_test( 'v2 integration: staged post WP_Error is terminal and skips CSS', function () {
		bricks_ie_v2_reset_store();
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'broken-post' ) ), array(), 'staged-post-error.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		$report = $importer->preflight( $zip );
		$member = 'katsarov/posts/' . $report['posts'][0]['file'];
		$archive = new ZipArchive(); bricks_ie_assert_same( true, $archive->open( $zip ) ); $archive->deleteName( $member ); $archive->addFromString( $member, '{' ); $archive->close();
		$state = bricks_ie_v2_staged_state( $importer, $zip, $report, 'posts' );
		$method = new ReflectionMethod( $importer, 'advance_v2_session_step' ); $method->setAccessible( true );
		$response = $method->invokeArgs( $importer, array( &$state ) );
		bricks_ie_assert_same( true, $response['done'] );
		bricks_ie_assert( in_array( $response['status'], array( 'partial', 'failed' ), true ) );
		bricks_ie_assert_same( 'posts', $state['step'] );
		bricks_ie_assert( ! in_array( 'posts', $response['completed_steps'], true ) && ! in_array( 'assets', $response['completed_steps'], true ) );
		bricks_ie_assert_same( 0, count( array_filter( $adapter->calls, function ( $call ) { return 'regenerate_css_files' === $call[0]; } ) ) );
	} );

	bricks_ie_test( 'v2 integration: staged successful posts record completion exactly once', function () {
		bricks_ie_v2_reset_store();
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'single-post-completion' ) ), array(), 'staged-post-completion.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		$report = $importer->preflight( $zip );
		$state = bricks_ie_v2_staged_state( $importer, $zip, $report, 'posts' );
		$method = new ReflectionMethod( $importer, 'advance_v2_session_step' ); $method->setAccessible( true );
		$response = $method->invokeArgs( $importer, array( &$state ) );
		bricks_ie_assert_same( 'assets', $state['step'] );
		bricks_ie_assert_same( 1, count( array_filter( $response['completed_steps'], function ( $step ) { return 'posts' === $step; } ) ) );
	} );

	bricks_ie_test( 'v2 integration: staged CSS success false and malformed results are terminal partial without assets completion', function () {
		foreach ( array( array( 'success' => false ), 'malformed-css-result', new WP_Error( 'staged_css_failed', 'staged css failed' ) ) as $index => $css_result ) {
			bricks_ie_v2_reset_store();
			$adapter = new Bricks_IE_V2_Stateful_Adapter();
			$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
			$adapter->css_result = $css_result;
			$validator = new Bricks_IE_V2_Stateful_Validator();
			$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'css-result-' . $index ) ), array(), 'staged-css-result-' . $index . '.zip' );
			$importer = bricks_ie_v2_importer( $validator, $adapter );
			$report = $importer->preflight( $zip );
			$state = bricks_ie_v2_staged_state( $importer, $zip, $report, 'assets' );
			$method = new ReflectionMethod( $importer, 'advance_v2_session_step' ); $method->setAccessible( true );
			$response = $method->invokeArgs( $importer, array( &$state ) );
			bricks_ie_assert_same( true, $response['done'] );
			bricks_ie_assert_same( 'partial', $response['status'] );
			bricks_ie_assert_same( 1, count( array_filter( $response['failed'], function ( $step ) { return 'assets' === $step; } ) ) );
			bricks_ie_assert( ! in_array( 'assets', $response['completed_steps'], true ) );
		}
	} );

	bricks_ie_test( 'v2 integration: staged native call with an incomplete adapter terminates partial without fatal error', function () {
		bricks_ie_v2_reset_store();
		$types = array( 'classes' => array( 'id' => 'classes', 'items' => array( array( 'id' => 'class-source', 'label' => 'Class' ) ) ) );
		$good_adapter = new Bricks_IE_V2_Stateful_Adapter();
		$good_adapter->inspect = array( 'manifest' => array( 'schema' => $good_adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array(), $types, 'staged-incomplete-adapter.zip' );
		$planner = bricks_ie_v2_importer( $validator, $good_adapter );
		$report = $planner->preflight( $zip );
		$importer = new Bricks_IE_Importer( array( 'archive_validator' => $validator, 'transfer_adapter' => new Bricks_IE_V2_Incomplete_Adapter() ) );
		$state = bricks_ie_v2_staged_state( $importer, $zip, $report, 'native' );
		$state['v2_native_index'] = 4;
		$method = new ReflectionMethod( $importer, 'advance_v2_session_step' ); $method->setAccessible( true );
		$response = $method->invokeArgs( $importer, array( &$state ) );
		bricks_ie_assert_same( true, $response['done'] );
		bricks_ie_assert_same( 'partial', $response['status'] );
		bricks_ie_assert( in_array( 'classes', $response['failed'], true ) );
	} );

	bricks_ie_test( 'v2 integration: staged CSS with an incomplete adapter is partial without assets completion', function () {
		bricks_ie_v2_reset_store();
		$good_adapter = new Bricks_IE_V2_Stateful_Adapter();
		$good_adapter->inspect = array( 'manifest' => array( 'schema' => $good_adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'missing-css-adapter' ) ), array(), 'staged-missing-css-adapter.zip' );
		$planner = bricks_ie_v2_importer( $validator, $good_adapter );
		$report = $planner->preflight( $zip );
		$importer = new Bricks_IE_Importer( array( 'archive_validator' => $validator, 'transfer_adapter' => new Bricks_IE_V2_Incomplete_Adapter() ) );
		$state = bricks_ie_v2_staged_state( $importer, $zip, $report, 'assets' );
		$method = new ReflectionMethod( $importer, 'advance_v2_session_step' ); $method->setAccessible( true );
		$response = $method->invokeArgs( $importer, array( &$state ) );
		bricks_ie_assert_same( true, $response['done'] );
		bricks_ie_assert_same( 'partial', $response['status'] );
		bricks_ie_assert_same( 1, count( array_filter( $response['failed'], function ( $step ) { return 'assets' === $step; } ) ) );
		bricks_ie_assert( ! in_array( 'assets', $response['completed_steps'], true ) );
	} );

	bricks_ie_test( 'v2 integration: two created pages remap postId and template fields in second pass', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_pf_next_post_id'] = 501; $types = array(); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $posts = array( bricks_ie_v2_post( 101, 'first', array( 'postId' => 102, 'templateId' => 102, 'count' => 102 ) ), bricks_ie_v2_post( 102, 'second', array( 'postId' => 101, 'templateId' => 101 ) ) ); $zip = bricks_ie_v2_fixture( $posts, $types, 'two-pages.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request ); bricks_ie_assert_same( 2, $result['posts_imported'], 'Two pages should be imported: ' . var_export( $result, true ) ); $meta = $GLOBALS['bricks_ie_exporter_test']['post_meta']; bricks_ie_assert( isset( $meta[501] ) || isset( $meta[502] ) );
	} );

	bricks_ie_test( 'v2 integration: existing page in skip mode produces zero core and meta writes', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'existing', 'post_title' => 'Existing', 'post_status' => 'publish' ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'existing', array( 'x' => 1 ) ) ), array(), 'existing-skip.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); $shape = new ReflectionMethod( $importer, 'get_v2_result_skeleton' ); $shape->setAccessible( true ); $method = new ReflectionMethod( $importer, 'import_v2_posts' ); $method->setAccessible( true ); $result = $method->invoke( $importer, $zip, array( array( 'file' => 'page__existing.json', 'slug' => 'existing', 'type' => 'page' ) ), $shape->invoke( $importer ), array( array( 'file' => 'page__existing.json', 'action' => 'skip', 'target_id' => 77 ) ), 'skip', false ); $writes = array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return in_array( $call['name'], array( 'wp_insert_post', 'wp_update_post', 'add_post_meta', 'update_post_meta', 'delete_post_meta' ), true ); } ); bricks_ie_assert_same( 'completed', $result['status'] ); bricks_ie_assert( in_array( 'existing', $result['skipped'], true ) ); bricks_ie_assert( ! in_array( 'existing', $result['failed'], true ) ); bricks_ie_assert_same( 0, count( $writes ) );
	} );

	bricks_ie_test( 'v2 integration: planned create skips when a target appears after preflight', function () {
		bricks_ie_v2_reset_store();
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'stale-create', array( 'postId' => 1 ) ) ), array(), 'stale-create.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		$report = $importer->preflight( $zip );
		bricks_ie_assert_same( 'create', $report['plan']['posts'][0]['action'] );

		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 88, 'post_type' => 'page', 'post_name' => 'stale-create', 'post_title' => 'Appeared', 'post_status' => 'publish' ) );
		$result_shape = new ReflectionMethod( $importer, 'get_v2_result_skeleton' );
		$result_shape->setAccessible( true );
		$result = $result_shape->invoke( $importer );
		$method = new ReflectionMethod( $importer, 'import_v2_posts' );
		$method->setAccessible( true );
		$result = $method->invoke( $importer, $zip, $report['posts'], $result, $report['plan']['posts'], 'skip', false );

		bricks_ie_assert( in_array( 'stale-create', $result['skipped'], true ) );
		bricks_ie_assert_same( 'partial', $result['status'] );
		bricks_ie_assert_same( 1, count( array_keys( $result['failed'], 'stale-create', true ) ) );
		bricks_ie_assert_same( 0, $result['posts_imported'] );
		$writes = array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return in_array( $call['name'], array( 'wp_insert_post', 'wp_update_post', 'update_post_meta', 'add_post_meta', 'delete_post_meta' ), true ); } );
		bricks_ie_assert_same( 0, count( $writes ), 'A stale create must not redirect metadata to the newly appeared target.' );
	} );

	bricks_ie_test( 'v2 integration: planned update skips when its confirmed target disappears', function () {
		bricks_ie_v2_reset_store();
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'stale-update', 'post_title' => 'Old', 'post_status' => 'draft' ) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'stale-update', array( 'postId' => 1 ) ) ), array(), 'stale-update.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		$report = $importer->preflight( $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) );
		bricks_ie_assert_same( 'update', $report['plan']['posts'][0]['action'] );
		bricks_ie_assert_same( 77, $report['plan']['posts'][0]['target_id'] );

		$GLOBALS['bricks_ie_exporter_test']['posts'] = array();
		$result_shape = new ReflectionMethod( $importer, 'get_v2_result_skeleton' );
		$result_shape->setAccessible( true );
		$result = $result_shape->invoke( $importer );
		$method = new ReflectionMethod( $importer, 'import_v2_posts' );
		$method->setAccessible( true );
		$result = $method->invoke( $importer, $zip, $report['posts'], $result, $report['plan']['posts'], 'replace', true );

		bricks_ie_assert( in_array( 'stale-update', $result['skipped'], true ) );
		bricks_ie_assert_same( 'partial', $result['status'] );
		bricks_ie_assert_same( 1, count( array_keys( $result['failed'], 'stale-update', true ) ) );
		bricks_ie_assert_same( 0, $result['posts_imported'] );
		$writes = array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return in_array( $call['name'], array( 'wp_insert_post', 'wp_update_post', 'update_post_meta', 'add_post_meta', 'delete_post_meta' ), true ); } );
		bricks_ie_assert_same( 0, count( $writes ), 'A missing update target must never become a create.' );
	} );

	bricks_ie_test( 'v2 integration: replace updates approved core and meta fields only', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'existing', 'post_title' => 'Old', 'post_status' => 'draft' ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'existing', array( 'ok' => true ) ) ), array(), 'existing-replace.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request ); $names = array_map( function ( $call ) { return $call['name']; }, $GLOBALS['bricks_ie_pf_write_log'] ); bricks_ie_assert( in_array( 'wp_update_post', $names, true ) ); bricks_ie_assert( in_array( 'update_post_meta', $names, true ) ); foreach ( $GLOBALS['bricks_ie_pf_write_log'] as $call ) if ( 'wp_update_post' === $call['name'] ) bricks_ie_assert_same( array( 'ID', 'post_title', 'post_status' ), array_keys( $call['args'][0] ) );
	} );

	bricks_ie_test( 'v2 integration: authorized replace reconciles allowlisted meta and preserves unrelated target meta', function () {
		bricks_ie_v2_reset_store();
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'meta-replace', 'post_title' => 'Old', 'post_status' => 'draft' ) );
		$GLOBALS['bricks_ie_exporter_test']['post_meta'][77] = array( '_bricks_page_content_2' => array( 'old' => true ), '_bricks_page_settings' => array( 'stale' => true ), '_unrelated_meta' => 'keep' );
		$adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'meta-replace', array( 'new' => true ) ) ), array(), 'meta-replace.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request );
		$stored = $GLOBALS['bricks_ie_exporter_test']['post_meta'][77];
		bricks_ie_assert_same( 'completed', $result['status'] ); bricks_ie_assert_same( 1, $result['posts_imported'] ); bricks_ie_assert_same( array( 'meta-replace' ), $result['updated'] ); bricks_ie_assert_same( array(), $result['failed'] );
		bricks_ie_assert_same( array( 'new' => true ), $stored['_bricks_page_content_2'] ); bricks_ie_assert( ! array_key_exists( '_bricks_page_settings', $stored ) ); bricks_ie_assert_same( 'keep', $stored['_unrelated_meta'] );
	} );

	bricks_ie_test( 'v2 integration: update meta false is accepted when the expected value is stored', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'false-success', 'post_title' => 'Old', 'post_status' => 'publish' ) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'false-success', array( 'accepted' => true ) ) ), array(), 'false-success.zip' );
		$GLOBALS['bricks_ie_pf_meta_write_controls']['update_post_meta'] = array( 'return' => false ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert_same( 'completed', $result['status'] ); bricks_ie_assert_same( 1, $result['posts_imported'] ); bricks_ie_assert_same( array( 'false-success' ), $result['updated'] ); bricks_ie_assert_same( array(), $result['failed'] );
	} );

	bricks_ie_test( 'v2 integration: unchanged failed meta write is partial and does not claim success', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'meta-write-failure', 'post_title' => 'Old', 'post_status' => 'publish' ) ); $GLOBALS['bricks_ie_exporter_test']['post_meta'][77] = array( '_bricks_page_content_2' => array( 'old' => true ) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'meta-write-failure', array( 'new' => true ) ) ), array(), 'meta-write-failure.zip' );
		$GLOBALS['bricks_ie_pf_meta_write_controls']['update_post_meta'] = array( 'return' => false, 'unchanged' => true ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert_same( 'partial', $result['status'] ); bricks_ie_assert_same( 0, $result['posts_imported'] ); bricks_ie_assert_same( 1, count( array_keys( $result['failed'], 'meta-write-failure', true ) ) ); bricks_ie_assert_same( array( 'meta-write-failure' ), $result['updated'] ); bricks_ie_pf_assert_contains_substring( $result['warnings'], 'metadata write failed for key "_bricks_page_content_2"', 'Metadata write warning should name the key.' );
	} );

	bricks_ie_test( 'v2 integration: created shell remains mapped when metadata write fails', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_pf_next_post_id'] = 501;
		$adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 9, 'created-meta-failure', array( 'new' => true ) ) ), array(), 'created-meta-failure.zip' );
		$GLOBALS['bricks_ie_pf_meta_write_controls']['update_post_meta'] = array( 'return' => false, 'unchanged' => true ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert_same( 'partial', $result['status'] ); bricks_ie_assert_same( 0, $result['posts_imported'] ); bricks_ie_assert_same( array( 'created-meta-failure' ), $result['created'] ); bricks_ie_assert_same( array( 'created-meta-failure' ), $result['failed'] ); bricks_ie_assert_same( array( 9 => 501 ), $result['mappings']['posts'] );
	} );

	bricks_ie_test( 'v2 integration: unchanged failed stale-meta delete is partial and leaves stale data', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'meta-delete-failure', 'post_title' => 'Old', 'post_status' => 'publish' ) ); $GLOBALS['bricks_ie_exporter_test']['post_meta'][77] = array( '_bricks_page_content_2' => array( 'old' => true ), '_bricks_page_settings' => array( 'stale' => true ) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'meta-delete-failure', array( 'new' => true ) ) ), array(), 'meta-delete-failure.zip' );
		$GLOBALS['bricks_ie_pf_meta_write_controls']['delete_post_meta'] = array( 'return' => false, 'unchanged' => true ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) ); $result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert_same( 'partial', $result['status'] ); bricks_ie_assert_same( 0, $result['posts_imported'] ); bricks_ie_assert_same( 1, count( array_keys( $result['failed'], 'meta-delete-failure', true ) ) ); bricks_ie_assert_same( array( 'meta-delete-failure' ), $result['updated'] ); bricks_ie_pf_assert_contains_substring( $result['warnings'], 'metadata delete failed for key "_bricks_page_settings"', 'Metadata delete warning should name the key.' ); bricks_ie_assert( array_key_exists( '_bricks_page_settings', $GLOBALS['bricks_ie_exporter_test']['post_meta'][77] ) );
	} );

	bricks_ie_test( 'v2 integration: update errors are reported and never queue metadata or mappings', function () {
		bricks_ie_v2_reset_store();
		$GLOBALS['bricks_ie_exporter_test']['posts'] = array( (object) array( 'ID' => 77, 'post_type' => 'page', 'post_name' => 'update-error', 'post_title' => 'Old', 'post_status' => 'draft' ) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 5, 'update-error', array( 'postId' => 5 ) ) ), array(), 'update-error.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'conflict_mode' => 'replace', 'allow_overwrite' => true ) );
		$GLOBALS['bricks_ie_pf_wp_update_post_result'] = new WP_Error( 'update_boom', 'Configured update failure.' );
		$result = $importer->import_from_zip( $zip, $request );

		bricks_ie_assert_same( 'partial', $result['status'] );
		bricks_ie_assert( in_array( 'update-error', $result['failed'], true ) );
		bricks_ie_assert_same( 0, $result['posts_imported'] );
		bricks_ie_assert( empty( $result['mappings']['posts'] ), 'A failed update ID must not enter the post map.' );
		$updates = array_values( array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return 'wp_update_post' === $call['name']; } ) );
		bricks_ie_assert_same( 1, count( $updates ) );
		bricks_ie_assert_same( true, $updates[0]['args'][1], 'wp_update_post must request WP_Error results.' );
		$meta_writes = array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return in_array( $call['name'], array( 'update_post_meta', 'add_post_meta', 'delete_post_meta' ), true ); } );
		bricks_ie_assert_same( 0, count( $meta_writes ) );
	} );

	bricks_ie_test( 'v2 integration: unresolved native class prevents page core and meta mutation', function () {
		bricks_ie_v2_reset_store(); $types = array( 'classes' => array( 'id' => 'classes', 'items' => array( array( 'id' => 'source', 'label' => 'Source' ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->list_results['classes'] = array( 'types' => array( array( 'id' => 'different', 'label' => 'Different' ) ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'unresolved', array( '_cssGlobalClasses' => array( 'source' ) ) ) ), $types, 'unresolved-class.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request ); $writes = array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return in_array( $call['name'], array( 'wp_insert_post', 'wp_update_post', 'update_post_meta' ), true ); } ); bricks_ie_assert_same( 0, count( $writes ) ); bricks_ie_assert_same( 'partial', $result['status'] ); bricks_ie_assert_same( 1, count( array_keys( $result['failed'], 'unresolved', true ) ) ); bricks_ie_assert( in_array( 'unresolved', $result['skipped'], true ) );
	} );

	bricks_ie_test( 'v2 integration: query IDs, element IDs, CSS numbers, tags, and external URLs stay untouched', function () {
		bricks_ie_v2_reset_store(); $types = array( 'global-queries' => array( 'id' => 'global-queries', 'items' => array( array( 'id' => 'local-query', 'label' => 'Local' ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->list_results['global-queries'] = array( 'types' => array( array( 'id' => 'local-query', 'label' => 'Local' ) ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $content = array( 'id' => 'abc123', 'queryId' => 'local-query', 'css' => 42, 'tag' => '{post_title}', 'url' => 'https://external.example/101' ); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 101, 'untouched', $content ) ), $types, 'untouched.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $importer->import_from_zip( $zip, $request ); $stored = $GLOBALS['bricks_ie_exporter_test']['post_meta']; $value = reset( $stored[101] ); bricks_ie_assert_same( $content, $value );
	} );

	bricks_ie_test( 'v2 integration: recognized media URLs normalize only through attachment stubs', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_v2_media'][9] = array( 'type' => 'attachment', 'image' => true, 'full' => 'https://target.example/wp-content/uploads/photo.jpg', 'thumbnail' => 'https://target.example/thumb.jpg' ); $GLOBALS['bricks_ie_v2_media_by_url']['https://source.example/wp-content/uploads/photo.jpg'] = 9; $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $media = array( 'id' => 999, 'url' => 'https://source.example/wp-content/uploads/photo.jpg', 'filename' => 'old.jpg' ); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'media', array( 'image' => $media, 'url' => 'https://source.example/not-media.jpg' ) ) ), array(), 'media.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $importer->import_from_zip( $zip, $request ); $stored = $GLOBALS['bricks_ie_exporter_test']['post_meta']; $value = reset( $stored[101] ); bricks_ie_assert_same( 9, $value['image']['id'] ); bricks_ie_assert_same( 'https://source.example/not-media.jpg', $value['url'] );
	} );

	bricks_ie_test( 'v2 integration: class identity maps use one unique native descriptor', function () {
		bricks_ie_v2_reset_store(); $types = array( 'classes' => array( 'id' => 'classes', 'items' => array( array( 'id' => 'source-class', 'label' => 'Card' ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->list_results['classes'] = array( 'types' => array( array( 'id' => 'target-class', 'label' => 'Card' ) ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'mapped-class', array( '_cssGlobalClasses' => array( 'source-class' ) ) ) ), $types, 'mapped-class.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request ); bricks_ie_assert_same( array( 'source-class' => 'target-class' ), $result['mappings']['classes'] ); bricks_ie_assert_same( 1, $result['posts_imported'] );
	} );

	bricks_ie_test( 'v2 integration: missing dynamic CPT is skipped without core or meta writes', function () {
		bricks_ie_v2_reset_store(); $GLOBALS['bricks_ie_preflight_test']['post_types'] = array( 'page', 'bricks_template' ); $types = array( 'classes' => array( 'id' => 'classes', 'items' => array( array( 'id' => 'c', 'label' => 'C' ) ) ) ); $adapter = new Bricks_IE_V2_Stateful_Adapter(); $adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) ); $adapter->list_results['classes'] = array( 'types' => array( array( 'id' => 'c', 'label' => 'C' ) ) ); $validator = new Bricks_IE_V2_Stateful_Validator(); $zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'missing-cpt', array( 'ok' => true ), 'post' ) ), $types, 'missing-cpt.zip' ); $importer = bricks_ie_v2_importer( $validator, $adapter ); list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip ); $result = $importer->import_from_zip( $zip, $request ); $writes = array_filter( $GLOBALS['bricks_ie_pf_write_log'], function ( $call ) { return in_array( $call['name'], array( 'wp_insert_post', 'wp_update_post', 'update_post_meta' ), true ); } ); bricks_ie_assert_same( 0, count( $writes ) ); bricks_ie_assert_same( 0, $result['posts_imported'] ); bricks_ie_assert( in_array( 'missing-cpt', $result['skipped'], true ) );
	} );

	bricks_ie_test( 'v2 integration: public session step rejects an expired session', function () {
		bricks_ie_v2_reset_store(); $result = ( new Bricks_IE_Importer() )->run_import_session_step( 'expired-session', 'token' ); bricks_ie_assert_instance_of( 'WP_Error', $result ); bricks_ie_assert_same( 'expired_session', $result->get_error_code() );
	} );

	bricks_ie_test( 'v2 integration: long staged mutation extends ownership and an expired visible slot cannot replay it', function () {
		bricks_ie_v2_reset_store();
		$GLOBALS['wpdb'] = new Bricks_IE_Test_WPDB();
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => array() ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array( bricks_ie_v2_post( 1, 'long-step' ) ), array(), 'long-staged-step.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		$report = $importer->preflight( $zip );
		$id = 'long-step'; $token = 'long-step-token'; $owner = hash( 'sha256', $token ); $now = time();
		$state = bricks_ie_v2_staged_state( $importer, $zip, $report, 'assets' );
		$state['session_id'] = $id; $state['session_token_hash'] = $owner; $state['lease_owner_hash'] = $owner; $state['archive_hash'] = hash_file( 'sha256', $zip );
		$GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ] = array( 'owner_token_hash' => $owner, 'session_id' => $id, 'user_id' => 42, 'archive_hash' => $state['archive_hash'], 'acquired_at' => $now, 'expires_at' => $now + Bricks_IE_Importer::IMPORT_LEASE_SECONDS, 'recover_after' => $now + Bricks_IE_Importer::IMPORT_STALE_RECOVERY_SECONDS );
		$GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_REGISTRY_OPTION ] = array( $id => array( 'zip_path' => $zip, 'trusted_temp_dir' => dirname( $zip ), 'is_temporary' => false, 'expires_at' => $now + 3600, 'lease_owner_hash' => $owner, 'archive_hash' => $state['archive_hash'], 'session_token_hash' => $owner, 'status' => 'confirmed' ) );
		set_transient( 'bricks_ie_import_' . $id, $state, 3600 );
		$GLOBALS['bricks_ie_nested_step_result'] = null;
		$adapter->css_callback = function () use ( $importer, $id, $token ) {
			$slot_name = 'bricks_ie_import_processing_' . $id;
			$lock = $GLOBALS['bricks_ie_exporter_test']['options'][ Bricks_IE_Importer::IMPORT_LOCK_OPTION ];
			$slot = $GLOBALS['bricks_ie_exporter_test']['options'][ $slot_name ];
			bricks_ie_assert( $lock['expires_at'] >= time() + Bricks_IE_Importer::IMPORT_MUTATION_GUARD_SECONDS - 5, 'Global lease was not extended before CSS mutation.' );
			bricks_ie_assert( $slot['expires_at'] >= time() + Bricks_IE_Importer::IMPORT_MUTATION_GUARD_SECONDS - 5, 'Processing ownership was not extended before CSS mutation.' );
			$GLOBALS['bricks_ie_exporter_test']['options'][ $slot_name ]['expires_at'] = time() - 1;
			$GLOBALS['bricks_ie_nested_step_result'] = $importer->run_import_session_step( $id, $token );
		};

		$result = $importer->run_import_session_step( $id, $token );
		bricks_ie_assert_same( true, $result['done'] );
		bricks_ie_assert_same( 'completed', $result['status'] );
		bricks_ie_assert_instance_of( 'WP_Error', $GLOBALS['bricks_ie_nested_step_result'] );
		bricks_ie_assert_same( 'import_in_progress', $GLOBALS['bricks_ie_nested_step_result']->get_error_code() );
		bricks_ie_assert_same( 1, count( array_filter( $adapter->calls, function ( $call ) { return 'regenerate_css_files' === $call[0]; } ) ) );
	} );

	bricks_ie_test( 'v2 integration: synchronous and staged native calls receive confirmed sensitive policy', function () {
		bricks_ie_v2_reset_store();
		$types = array( 'settings' => array( 'id' => 'settings', 'items' => array( array( 'id' => 'api-keys', 'label' => 'API keys' ) ) ) );
		$adapter = new Bricks_IE_V2_Stateful_Adapter();
		$adapter->inspect = array( 'manifest' => array( 'schema' => $adapter::EXPECTED_SCHEMA, 'version' => 1, 'types' => $types ) );
		$validator = new Bricks_IE_V2_Stateful_Validator();
		$zip = bricks_ie_v2_fixture( array(), $types, 'sensitive-policy.zip' );
		$importer = bricks_ie_v2_importer( $validator, $adapter );
		list( $report, $request ) = bricks_ie_v2_confirm( $importer, $zip, array( 'allow_sensitive_settings' => true ) );
		$result = $importer->import_from_zip( $zip, $request );
		bricks_ie_assert( is_array( $result ) );
		$sync = array_values( array_filter( $adapter->calls, function ( $call ) { return 'import_package' === $call[0]; } ) );
		bricks_ie_assert_same( true, $sync[0][2]['allow_sensitive_settings'] );

		$adapter->calls = array();
		$state = array( 'step' => 'native', 'v2_native_index' => 0, 'v2_native_order' => array( 'settings' ), 'zip_path' => $zip, 'preflight' => $report, 'v2_result' => array( 'status' => 'success', 'native_result' => array(), 'warnings' => array(), 'created' => array(), 'updated' => array(), 'skipped' => array(), 'failed' => array(), 'mappings' => array(), 'completed_steps' => array(), 'posts_imported' => 0, 'options_imported' => 0, 'id_remaps' => 0 ), 'native_identity_maps' => array(), 'native_source_ids' => array(), 'id_map' => array(), 'posts_processed' => 0, 'options_processed' => 0, 'posts_total' => 0, 'options_total' => 0, 'posts_imported' => 0, 'options_imported' => 0, 'total_units' => 1 );
		$method = new ReflectionMethod( $importer, 'advance_v2_session_step' );
		$method->setAccessible( true );
		$method->invokeArgs( $importer, array( &$state ) );
		$staged = array_values( array_filter( $adapter->calls, function ( $call ) { return 'import_package' === $call[0]; } ) );
		bricks_ie_assert_same( true, $staged[0][2]['allow_sensitive_settings'] );
	} );
}
