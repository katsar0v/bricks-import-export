<?php
/**
 * Plugin Name: Bricks Import & Export
 * Plugin URI:  https://katsarov.design
 * Description: Export and import your Bricks Builder configuration — settings, Style Manager, theme styles, global classes, variables, pages, templates, and Bricks-enabled post types — as a single zip archive. Supports both admin UI and WP-CLI.
 * Version:     1.0.1
 * Author:      Katsarov Design
 * Author URI:  https://katsarov.design
 * License:     GPL-2.0-or-later
 * Text Domain: bricks-ie
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'BRICKS_IE_VERSION', '1.0.1' );
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

require_once BRICKS_IE_DIR . 'includes/class-bricks-exporter.php';
require_once BRICKS_IE_DIR . 'includes/class-bricks-importer.php';
require_once BRICKS_IE_DIR . 'includes/class-admin-page.php';
require_once BRICKS_IE_DIR . 'includes/class-cli-command.php';

Bricks_IE_Admin_Page::instance();

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

	$importer = new Bricks_IE_Importer();
	$importer->upload();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'cli_init', function () {
		WP_CLI::add_command( 'bricks', 'Bricks_IE_CLI_Command' );
	} );
}
