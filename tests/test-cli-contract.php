<?php
/** Contract tests for the 1.1.0 WP-CLI policy boundary. */

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static $logs = array();
		public static $confirmations = 0;
		public static function log( $message ) { self::$logs[] = (string) $message; }
		public static function success( $message ) { self::$logs[] = (string) $message; }
		public static function confirm( $message ) { self::$confirmations++; }
		public static function error( $message ) { throw new RuntimeException( (string) $message ); }
	}
}

require_once dirname( __DIR__ ) . '/includes/class-cli-command.php';

class Bricks_IE_CLI_Test_Exporter {
	public function build_zip( $output_path, $request = array() ) {
		return array(
			'file'           => $output_path,
			'size'           => 2048,
			'schema_version' => '2',
			'native'         => array( 'types' => 1, 'items' => 1 ),
			'options_count'  => 1,
			'posts_count'    => 1,
			'warnings'       => array( 'scalar warning' ),
			'omissions'      => array(
				array( 'message' => 'structured omission' ),
				array( 'type' => 'record', 'id' => 7 ),
				'legacy omission',
			),
		);
	}
}

class Bricks_IE_CLI_Test_Command extends Bricks_IE_CLI_Command {
	protected function create_exporter() {
		return new Bricks_IE_CLI_Test_Exporter();
	}
}

bricks_ie_test( 'cli: dry-run performs preflight and does not import', function () {
	bricks_ie_pf_reset();
	$zip = bricks_ie_pf_v1_archive();
	WP_CLI::$logs = array();
	( new Bricks_IE_CLI_Command() )->import( array(), array( 'file' => $zip, 'dry-run' => true ) );
	bricks_ie_assert( count( $GLOBALS['bricks_ie_preflight_test']['write_calls'] ) === 0 );
	bricks_ie_assert( strpos( implode( "\n", WP_CLI::$logs ), 'Preflight report:' ) !== false );
} );

bricks_ie_test( 'cli: mutation requires a real current administrator identity', function () {
	bricks_ie_pf_reset();
	$zip = bricks_ie_pf_v1_archive();
	$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = false;
	$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;
	try {
		( new Bricks_IE_CLI_Command() )->import( array(), array( 'file' => $zip, 'backup-acknowledged' => true, 'accept-warnings' => true, 'yes' => true ) );
		throw new RuntimeException( 'Expected identity failure.' );
	} catch ( RuntimeException $exception ) {
		bricks_ie_assert( strpos( $exception->getMessage(), '--user' ) !== false );
	}
	$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = true;
	$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = true;
	bricks_ie_assert( strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-cli-command.php' ), '$user_id > 0' ) !== false );
} );

bricks_ie_test( 'cli: user 0 is rejected before export or import work', function () {
	bricks_ie_pf_reset();
	$GLOBALS['bricks_ie_session_user'] = 0;
	$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = false;
	$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;
	WP_CLI::$logs = array();
	$command = new Bricks_IE_CLI_Command();
	try {
		$command->export( array(), array() );
		throw new RuntimeException( 'Expected export identity failure.' );
	} catch ( RuntimeException $exception ) {
		bricks_ie_assert( strpos( $exception->getMessage(), '--user' ) !== false, 'Export did not provide --user guidance: ' . $exception->getMessage() );
		bricks_ie_assert_same( array(), WP_CLI::$logs, 'Exporter work must not start before authorization.' );
	}
	try {
		$command->import( array(), array( 'file' => __FILE__ ) );
		throw new RuntimeException( 'Expected import identity failure.' );
	} catch ( RuntimeException $exception ) {
		bricks_ie_assert( strpos( $exception->getMessage(), '--user' ) !== false, 'Import did not provide --user guidance: ' . $exception->getMessage() );
	}
	$GLOBALS['bricks_ie_session_user'] = 42;
} );

bricks_ie_test( 'cli: non-admin export is rejected before exporter construction', function () {
	bricks_ie_pf_reset();
	$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = false;
	$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = false;
	WP_CLI::$logs = array();
	try {
		( new Bricks_IE_CLI_Command() )->export( array(), array() );
		throw new RuntimeException( 'Expected export authorization failure.' );
	} catch ( RuntimeException $exception ) {
		bricks_ie_assert( strpos( $exception->getMessage(), '--user' ) !== false );
		bricks_ie_assert_same( array(), WP_CLI::$logs );
	}
	$GLOBALS['bricks_ie_preflight_test']['caps']['manage_options'] = true;
	$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = true;
} );

bricks_ie_test( 'cli: v1 policy flags are propagated and skip is reported', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-cli-command.php' );
	bricks_ie_assert( strpos( $source, "'conflict_mode' => \$conflict" ) !== false );
	bricks_ie_assert( strpos( $source, "'allow_overwrite'] = true" ) !== false );
	bricks_ie_assert( strpos( $source, 'import_from_zip( $file, $policy )' ) !== false );
	bricks_ie_assert( strpos( $source, 'Schema v1 conflict policy is skip; existing posts were left untouched.' ) !== false );
} );

bricks_ie_test( 'cli: command contract retains policy flags and legacy export counts', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-cli-command.php' );
	foreach ( array( '--file', '--dry-run', '--conflict', '--allow-overwrite', '--allow-sensitive-settings', '--backup-acknowledged', '--accept-warnings', '--yes' ) as $flag ) {
		bricks_ie_assert( strpos( $source, $flag ) !== false, 'Missing CLI flag ' . $flag );
	}
	foreach ( array( 'options_imported', 'posts_imported', 'id_remaps', 'schema_version', 'native', 'warnings', 'omissions' ) as $key ) {
		bricks_ie_assert( strpos( $source, $key ) !== false, 'Missing compatibility/report key ' . $key );
	}
} );

bricks_ie_test( 'cli: structured export omissions are logged without conversion warnings', function () {
	$GLOBALS['bricks_ie_session_user'] = 42;
	$GLOBALS['bricks_ie_adapter_test']['caps']['manage_options'] = true;
	WP_CLI::$logs = array();
	$warnings = array();
	$previous_handler = set_error_handler(
		function ( $severity, $message ) use ( &$warnings ) {
			$warnings[] = $message;
			return false;
		},
		E_ALL
	);
	try {
		( new Bricks_IE_CLI_Test_Command() )->export( array( '/tmp/test-export.zip' ), array() );
	} finally {
		restore_error_handler();
	}
	$output = implode( "\n", WP_CLI::$logs );
	bricks_ie_assert_same( array(), $warnings, 'Export emitted warnings: ' . implode( '; ', $warnings ) );
	bricks_ie_assert( strpos( $output, 'structured omission' ) !== false );
	bricks_ie_assert( strpos( $output, '{"type":"record","id":7}' ) !== false );
	bricks_ie_assert( strpos( $output, 'legacy omission' ) !== false );
} );
