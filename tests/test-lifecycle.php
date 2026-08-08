<?php
/** Isolated lifecycle tests for the plugin bootstrap cron integration. */

require_once __DIR__ . '/bootstrap.php';

bricks_ie_test( 'lifecycle: scheduling, callback, deactivation, and denylist behavior', function () {
	$plugin = dirname( __DIR__ ) . '/bricks-import-export.php';
	$runner = bricks_ie_test_temp_dir() . '/lifecycle.php';
	$script = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', false );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['scheduled'] = array();
$GLOBALS['hooks'] = array();
$GLOBALS['filter'] = null;
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ] = $callback; }
function register_activation_hook( $file, $callback ) { $GLOBALS['activation'] = $callback; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['deactivation'] = $callback; }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['scheduled'][ $hook ] ) ? $GLOBALS['scheduled'][ $hook ] : false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['scheduled'][ $hook ] = array( $timestamp, $recurrence ); }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][ $hook ] ); }
function apply_filters( $hook, $value ) { return null === $GLOBALS['filter'] ? $value : call_user_func( $GLOBALS['filter'], $value ); }
class Bricks_IE_Importer { public static $calls = 0; public function cleanup_expired_import_sessions() { self::$calls++; } }
$source = file_get_contents( $argv[1] );
$source = preg_replace( '/require_once BRICKS_IE_DIR[^;]+;/', '', $source );
$source = str_replace( 'Bricks_IE_Admin_Page::instance();', '', $source );
eval( '?>' . $source );
$GLOBALS['activation']();
$first = $GLOBALS['scheduled']['bricks_ie_cleanup_import_sessions'];
$GLOBALS['hooks']['init']();
$same_schedule = ( $first === $GLOBALS['scheduled']['bricks_ie_cleanup_import_sessions'] );
$GLOBALS['hooks']['bricks_ie_cleanup_import_sessions']();
$callback_calls = Bricks_IE_Importer::$calls;
$GLOBALS['scheduled']['unrelated'] = 7;
$GLOBALS['deactivation']();
$only_plugin_cleared = ! isset( $GLOBALS['scheduled']['bricks_ie_cleanup_import_sessions'] ) && 7 === $GLOBALS['scheduled']['unrelated'];
$GLOBALS['filter'] = function () { return array( 'site_custom_secret' ); };
$keys = bricks_ie_get_legacy_sensitive_settings_keys();
$additive = in_array( 'apiKeyUnsplash', $keys, true ) && in_array( 'site_custom_secret', $keys, true );
$GLOBALS['filter'] = function () { return 'invalid'; };
$invalid_preserves = in_array( 'customScriptsHeader', bricks_ie_get_legacy_sensitive_settings_keys(), true );
echo json_encode( array( $same_schedule, 'hourly' === $first[1], 1 === $callback_calls, $only_plugin_cleared, $additive, $invalid_preserves ) );
PHP;
	file_put_contents( $runner, $script );
	$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' ' . escapeshellarg( $plugin ) );
	bricks_ie_assert_same( array( true, true, true, true, true, true ), json_decode( trim( (string) $output ), true ), 'Lifecycle subprocess assertions failed.' );
} );
