<?php
/**
 * Plugin Name: Bricks Import & Export
 * Plugin URI:  https://katsarov.design
 * Description: Export and import your Bricks Builder configuration — settings, Style Manager, theme styles, global classes, variables, pages, templates, and Bricks-enabled post types — as a single zip archive. Supports both admin UI and WP-CLI.
 * Version:     1.1.3
 * Author:      Katsarov Design
 * Author URI:  https://katsarov.design
 * License:     GPL-2.0-or-later
 * Text Domain: bricks-ie
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'BRICKS_IE_VERSION', '1.1.3' );
define( 'BRICKS_IE_FILE', __FILE__ );
define( 'BRICKS_IE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRICKS_IE_URL', plugin_dir_url( __FILE__ ) );
define( 'BRICKS_IE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check whether the Bricks theme is installed and active.
 *
 * Bricks defines BRICKS_VERSION in its functions.php; it is only present
 * when Bricks (or a child theme whose parent is Bricks) is the active theme.
 *
 * @return bool
 */
function bricks_ie_is_bricks_active() {
	return defined( 'BRICKS_VERSION' );
}

/**
 * Get legacy Bricks global-setting keys that must not leave the site by default.
 *
 * Keep this policy in the bootstrap so both legacy export and import consumers
 * use the same filterable vocabulary. The aliases are intentionally broad:
 * older Bricks versions used several different names for these values.
 *
 * @return array
 */
function bricks_ie_get_legacy_sensitive_settings_keys() {
	$mandatory_keys = array(
		'adobeFontsProjectId', 'apiKeyUnsplash', 'apiKeyGoogleMaps', 'apiKeyGoogleRecaptcha',
		'apiSecretKeyGoogleRecaptcha', 'apiKeyHCaptcha', 'apiSecretKeyHCaptcha', 'apiKeyTurnstile',
		'apiSecretKeyTurnstile', 'apiKeyMailchimp', 'apiKeySendgrid', 'facebookAppId',
		'instagramAccessToken', 'executeCodeEnabled', 'customCss', 'customScriptsHeader',
		'customScriptsBodyHeader', 'customScriptsBodyFooter', 'myTemplatesPassword',
		'remoteTemplatesPassword',
		// Generic aliases used by nested legacy settings payloads.
		'password', 'pass',
		// Conservative aliases used by older Bricks releases.
		'apiKey', 'apiKeys', 'apiSecretKey', 'googleMapsAPIKey', 'recaptchaSiteKey',
		'recaptchaSecretKey', 'customCode', 'customCSS', 'customJS', 'codeExecution',
		'executeCode', 'allowCodeExecution', 'codeExecutionEnabled',
	);
	$keys = apply_filters( 'bricks_ie_legacy_sensitive_settings_keys', $mandatory_keys );

	if ( ! is_array( $keys ) ) {
		return $mandatory_keys;
	}

	// The filter is additive only: sensitive defaults must not be weakened by a
	// site-specific callback, including one that returns malformed data.
	return array_values( array_unique( array_merge( $mandatory_keys, array_filter( $keys, 'is_string' ) ) ) );
}

/**
 * Get Bricks option names exported and imported by default.
 *
 * Media-backed settings such as custom fonts/uploads are intentionally excluded:
 * this plugin stores database state only and does not bundle upload files.
 *
 * @return array
 */
function bricks_ie_get_default_option_names() {
	return array(
		'bricks_global_settings',
		'bricks_theme_styles',
		'bricks_global_classes',
		'bricks_color_palette',
		'bricks_style_manager',
		'bricks_global_variables',
		'bricks_global_variables_categories',
		'bricks_components',
		'bricks_global_queries',
		'bricks_global_queries_categories',
		'bricks_global_elements',
		'bricks_global_classes_categories',
		'bricks_global_classes_locked',
		'bricks_global_classes_trash',
		'bricks_global_pseudo_classes',
		'bricks_breakpoints',
		'bricks_icon_sets',
		'bricks_custom_icons',
		'bricks_disabled_icon_sets',
		'bricks_font_favorites',
		'bricks_sidebars',
		'bricks_element_manager',
	);
}

/**
 * Get the filtered Bricks option names to export/import.
 *
 * @return array
 */
function bricks_ie_get_option_names() {
	return apply_filters( 'bricks_ie_options', bricks_ie_get_default_option_names() );
}

/**
 * Get post types that should be exported by default.
 *
 * Bricks stores the builder-enabled post types in the global settings option.
 * Keep pages and templates as the baseline, then include configured post types
 * such as WooCommerce products when the local site allows Bricks editing there.
 *
 * @return array
 */
function bricks_ie_get_default_post_types() {
	$post_types = array( 'page', 'bricks_template' );
	$settings   = get_option( 'bricks_global_settings', array() );

	if ( is_array( $settings ) && ! empty( $settings['postTypes'] ) && is_array( $settings['postTypes'] ) ) {
		foreach ( $settings['postTypes'] as $post_type ) {
			$post_type = sanitize_key( $post_type );

			if ( $post_type && post_type_exists( $post_type ) ) {
				$post_types[] = $post_type;
			}
		}
	}

	return array_values( array_unique( $post_types ) );
}

/**
 * Get filtered post types to export/import.
 *
 * @return array
 */
function bricks_ie_get_post_types() {
	return apply_filters( 'bricks_ie_post_types', bricks_ie_get_default_post_types() );
}

/**
 * Get post types that may be created when missing during import.
 *
 * Dynamic content types such as products are intentionally excluded by default:
 * their catalog records should exist already, and this plugin should only move
 * their Bricks layout meta.
 *
 * @return array
 */
function bricks_ie_get_create_missing_post_types() {
	return apply_filters( 'bricks_ie_create_missing_post_types', array( 'page', 'bricks_template' ) );
}

/**
 * Get post types whose title/status may be updated during import.
 *
 * @return array
 */
function bricks_ie_get_update_post_fields_post_types() {
	return apply_filters( 'bricks_ie_update_post_fields_post_types', array( 'page', 'bricks_template' ) );
}

// These are dependencies of the importer and must be loaded before it.  Keep
// the exporter/importer ordering below for compatibility with 1.0.x.
require_once BRICKS_IE_DIR . 'includes/class-archive-validator.php';
require_once BRICKS_IE_DIR . 'includes/class-bricks-transfer-adapter.php';
require_once BRICKS_IE_DIR . 'includes/class-bricks-exporter.php';
require_once BRICKS_IE_DIR . 'includes/class-bricks-importer.php';
require_once BRICKS_IE_DIR . 'includes/class-admin-page.php';
require_once BRICKS_IE_DIR . 'includes/class-cli-command.php';

Bricks_IE_Admin_Page::instance();

/**
 * Cron hook used to remove expired staged import sessions.
 *
 * This callback deliberately performs no capability or login checks: WP-Cron
 * may run in a request without an authenticated user.
 */
function bricks_ie_cleanup_import_sessions() {
	$importer = new Bricks_IE_Importer();
	$importer->cleanup_expired_import_sessions();
}

/** Schedule the hourly cleanup once, without creating duplicate events. */
function bricks_ie_schedule_cleanup_import_sessions() {
	if ( ! wp_next_scheduled( 'bricks_ie_cleanup_import_sessions' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'bricks_ie_cleanup_import_sessions' );
	}
}

/** Remove only this plugin's cleanup event(s). */
function bricks_ie_deactivate() {
	wp_clear_scheduled_hook( 'bricks_ie_cleanup_import_sessions' );
}

register_activation_hook( BRICKS_IE_FILE, 'bricks_ie_schedule_cleanup_import_sessions' );
register_deactivation_hook( BRICKS_IE_FILE, 'bricks_ie_deactivate' );
add_action( 'init', 'bricks_ie_schedule_cleanup_import_sessions' );
add_action( 'bricks_ie_cleanup_import_sessions', 'bricks_ie_cleanup_import_sessions' );

add_action( 'admin_post_bricks_ie_export', 'bricks_ie_handle_export' );

function bricks_ie_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bricks-ie' ), 403 );
	}

	check_admin_referer( 'bricks_ie_export' );

	$exporter = new Bricks_IE_Exporter();
	$exporter->download();
}

add_action( 'admin_post_bricks_ie_import', 'bricks_ie_handle_import' );

function bricks_ie_handle_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bricks-ie' ), 403 );
	}

	check_admin_referer( 'bricks_ie_import' );

	// The synchronous form target is intentionally non-mutating. Imports must
	// use the staged JavaScript preflight flow or the WP-CLI preflight flow.
	wp_die( esc_html__( 'Admin imports require JavaScript preflight and confirmation, or WP-CLI preflight. No archive was imported.', 'bricks-ie' ), esc_html__( 'Import review required', 'bricks-ie' ), array( 'response' => 400 ) );
}

add_action( 'wp_ajax_bricks_ie_import_preflight', 'bricks_ie_ajax_import_preflight' );
// 1.0.x clients used "start".  It is intentionally an alias, not a second
// implementation, so old clients receive the staged (non-mutating) response.
add_action( 'wp_ajax_bricks_ie_import_start', 'bricks_ie_ajax_import_preflight' );
add_action( 'wp_ajax_bricks_ie_import_confirm', 'bricks_ie_ajax_import_confirm' );
add_action( 'wp_ajax_bricks_ie_import_step', 'bricks_ie_ajax_import_step' );
add_action( 'wp_ajax_bricks_ie_import_cancel', 'bricks_ie_ajax_import_cancel' );

function bricks_ie_ajax_import_authorize() {
	if ( ! current_user_can( 'manage_options' ) ) {
		bricks_ie_ajax_import_send_error( 'import_unauthorized', __( 'You do not have sufficient permissions to import Bricks data.', 'bricks-ie' ), null, 403 );
	}

	if ( ! check_ajax_referer( 'bricks_ie_import', '_ajax_nonce', false ) ) {
		bricks_ie_ajax_import_send_error( 'invalid_nonce', __( 'Import security check failed. Please refresh the page and try again.', 'bricks-ie' ), null, 403 );
	}
	return true;
}

/**
 * Send a stable, machine-readable AJAX error envelope.
 *
 * Keep error data optional: callers may use it for field-level details without
 * changing the response shape used by existing clients.
 *
 * @param string       $code
 * @param string       $message
 * @param mixed        $data
 * @param int          $status
 */
function bricks_ie_ajax_import_send_error( $code, $message, $data = null, $status = 400 ) {
	$error = array(
		'code'    => (string) $code,
		'message' => (string) $message,
	);

	if ( null !== $data ) {
		$error['data'] = $data;
	}

	wp_send_json_error( $error, (int) $status );
}

/**
 * Read a bounded scalar request value without normalizing valid token casing.
 *
 * @param string $key
 * @param int    $max_length
 * @return string
 */
function bricks_ie_ajax_import_scalar( $key, $max_length = 255 ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
		return '';
	}

	$value = sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) );
	return substr( $value, 0, (int) $max_length );
}

function bricks_ie_ajax_import_bool( $key ) {
	return isset( $_POST[ $key ] ) && in_array( strtolower( (string) wp_unslash( $_POST[ $key ] ) ), array( '1', 'true', 'yes', 'on' ), true );
}

function bricks_ie_ajax_import_policy() {
	$mode = isset( $_POST['conflict_mode'] ) ? sanitize_key( wp_unslash( $_POST['conflict_mode'] ) ) : 'skip';
	if ( ! in_array( $mode, array( 'skip', 'replace' ), true ) ) {
		$mode = 'skip';
	}
	return array(
		'conflict_mode'          => $mode,
		'allow_overwrite'        => bricks_ie_ajax_import_bool( 'allow_overwrite' ),
		'allow_sensitive_settings' => bricks_ie_ajax_import_bool( 'allow_sensitive_settings' ),
		'import_images'          => bricks_ie_ajax_import_bool( 'import_images' ),
	);
}

function bricks_ie_ajax_import_error_or_success( $result ) {
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		bricks_ie_ajax_import_send_error( $result->get_error_code(), $result->get_error_message(), ( null === $data || '' === $data ) ? null : $data, 400 );
	}
	wp_send_json_success( $result );
}

function bricks_ie_ajax_import_preflight() {
	bricks_ie_ajax_import_authorize();

	$importer = new Bricks_IE_Importer();
	// Never pass through client-controlled plan data. Image intent is a bounded
	// boolean that is bound into the server-generated preflight plan.
	bricks_ie_ajax_import_error_or_success( $importer->start_import_preflight_session( bricks_ie_ajax_import_policy() ) );
}

function bricks_ie_ajax_import_step() {
	bricks_ie_ajax_import_authorize();

	$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
	$session_token = bricks_ie_ajax_import_scalar( 'session_token' );
	$importer   = new Bricks_IE_Importer();
	$result     = $importer->run_import_session_step( $session_id, $session_token );
	bricks_ie_ajax_import_error_or_success( $result );
}

function bricks_ie_ajax_import_confirm() {
	bricks_ie_ajax_import_authorize();
	$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
	$session_token = bricks_ie_ajax_import_scalar( 'session_token' );
	$policy = bricks_ie_ajax_import_policy();
	$archive_hash = bricks_ie_ajax_import_scalar( 'archive_hash' );
	if ( ! preg_match( '/\A[a-f0-9]{64}\z/i', $archive_hash ) ) {
		bricks_ie_ajax_import_send_error( 'invalid_archive_hash', __( 'A valid 64-character archive hash is required.', 'bricks-ie' ), array( 'field' => 'archive_hash', 'format' => 'sha256' ), 400 );
	}
	$plan_hash = bricks_ie_ajax_import_scalar( 'plan_hash' );
	if ( ! preg_match( '/\A[a-f0-9]{64}\z/i', $plan_hash ) ) {
		bricks_ie_ajax_import_send_error( 'invalid_plan_hash', __( 'A valid 64-character preflight plan hash is required.', 'bricks-ie' ), array( 'field' => 'plan_hash', 'format' => 'sha256' ), 400 );
	}
	$plan_policy = array(
		'conflict_mode'           => $policy['conflict_mode'],
		'allow_overwrite'         => $policy['allow_overwrite'],
		'allow_sensitive_settings' => $policy['allow_sensitive_settings'],
		'import_images'           => $policy['import_images'],
	);
	$confirmation = array(
		'archive_hash'         => $archive_hash,
		'plan_hash'            => $plan_hash,
		// Construct this on the server; a submitted plan is deliberately ignored.
		'plan'                 => $plan_policy,
		'backup_acknowledged'  => bricks_ie_ajax_import_bool( 'backup_acknowledged' ),
		'warnings_acknowledged'=> bricks_ie_ajax_import_bool( 'warnings_acknowledged' ),
		'allow_sensitive_settings' => $policy['allow_sensitive_settings'],
		'conflict_mode'        => $policy['conflict_mode'],
		'allow_overwrite'      => $policy['allow_overwrite'],
		'import_images'        => $policy['import_images'],
	);
	$result = ( new Bricks_IE_Importer() )->confirm_import_session( $session_id, $session_token, $confirmation );
	bricks_ie_ajax_import_error_or_success( $result );
}

function bricks_ie_ajax_import_cancel() {
	bricks_ie_ajax_import_authorize();
	$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
	$session_token = bricks_ie_ajax_import_scalar( 'session_token' );
	$result = ( new Bricks_IE_Importer() )->cancel_import_session( $session_id, $session_token );
	bricks_ie_ajax_import_error_or_success( $result );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'cli_init', function () {
		WP_CLI::add_command( 'bricks', 'Bricks_IE_CLI_Command' );
	} );
}
