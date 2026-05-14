<?php
/**
 * WP-CLI commands for Bricks Import & Export.
 *
 * Provides `wp bricks export` and `wp bricks import` commands.
 *
 * @package BricksIE
 */

class Bricks_IE_CLI_Command {

	/**
	 * Export the current Bricks state to a zip file.
	 *
	 * ## OPTIONS
	 *
	 * [<path>]
	 * : Path for the output zip file. Defaults to `bricks-export-YYYY-MM-DD.zip` in the current directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bricks export
	 *     wp bricks export /tmp/my-bricks-backup.zip
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( $args, $assoc_args ) {
		$output_path = ! empty( $args[0] ) ? $args[0] : 'bricks-export-' . gmdate( 'Y-m-d' ) . '.zip';

		WP_CLI::log( 'Building Bricks export…' );

		$exporter = new Bricks_IE_Exporter();
		$result   = $exporter->build_zip( $output_path );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$size_kb = round( $result['size'] / 1024, 1 );

		WP_CLI::success( sprintf(
			'Exported to %s (%s KB) — %d option(s), %d post(s).',
			$result['file'],
			$size_kb,
			$result['options_count'],
			$result['posts_count']
		) );
	}

	/**
	 * Import a Bricks export zip file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to the zip file to import.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bricks import --file=bricks-export-2026-05-12.zip
	 *     wp bricks import --file=bricks-export-2026-05-12.zip --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function import( $args, $assoc_args ) {
		$file = \WP_CLI\Utils\get_flag_value( $assoc_args, 'file', '' );

		if ( empty( $file ) ) {
			WP_CLI::error( 'Please specify a zip file with --file=<path>.' );
		}

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file ) );
		}

		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $yes ) {
			WP_CLI::log( 'This will overwrite your current Bricks settings, Style Manager data, theme styles, global classes, variables, color palettes, components, queries, elements, and all Bricks template/page content.' );
			WP_CLI::confirm( 'Are you sure you want to proceed?' );
		}

		WP_CLI::log( 'Importing Bricks state…' );

		$importer = new Bricks_IE_Importer();
		$result   = $importer->import_from_zip( $file );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf(
			'Imported %d option(s), %d post(s), remapped %d ID(s).',
			$result['options_imported'],
			$result['posts_imported'],
			$result['id_remaps']
		) );
	}
}
