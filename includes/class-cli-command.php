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
	 * [--allow-sensitive-settings]
	 * : Include sensitive settings in the export. Omitted by default.
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
		$this->require_authorized_user();
		$output_path = ! empty( $args[0] ) ? $args[0] : 'bricks-export-' . gmdate( 'Y-m-d' ) . '.zip';

		WP_CLI::log( 'Building Bricks export…' );

		$exporter = $this->create_exporter();
		$request = array();
		if ( $this->flag( $assoc_args, 'allow-sensitive-settings' ) ) {
			$request['allow_sensitive_settings'] = true;
		}
		$result   = $exporter->build_zip( $output_path, $request );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$size_kb = round( $result['size'] / 1024, 1 );

		$native = isset( $result['native'] ) && is_array( $result['native'] ) ? $result['native'] : array();
		$warnings = isset( $result['warnings'] ) ? (array) $result['warnings'] : array();
		$omissions = isset( $result['omissions'] ) ? (array) $result['omissions'] : array();
		WP_CLI::success( sprintf(
			'Exported to %s (%s KB) — schema %s, %d native type(s), %d native item(s), %d option(s), %d post(s).%s',
			$result['file'],
			$size_kb,
			isset( $result['schema_version'] ) ? $result['schema_version'] : 'unknown',
			isset( $native['types'] ) ? $native['types'] : 0,
			isset( $native['items'] ) ? $native['items'] : 0,
			isset( $result['options_count'] ) ? $result['options_count'] : 0,
			isset( $result['posts_count'] ) ? $result['posts_count'] : 0,
			( $warnings || $omissions ) ? ' Warnings/omissions: ' . count( $warnings ) . '/' . count( $omissions ) . '.' : ''
		) );
		if ( $warnings ) WP_CLI::log( 'Warnings: ' . implode( ' | ', array_map( array( $this, 'format_report_item' ), $warnings ) ) );
		if ( $omissions ) WP_CLI::log( 'Omissions: ' . implode( ' | ', array_map( array( $this, 'format_report_item' ), $omissions ) ) );
	}

	/**
	 * Create the exporter used by the command.
	 *
	 * Kept as a method so the command can be exercised without performing a
	 * real export in contract tests.
	 *
	 * @return Bricks_IE_Exporter
	 */
	protected function create_exporter() {
		return new Bricks_IE_Exporter();
	}

	/**
	 * Convert a warning or omission record into safe CLI text.
	 *
	 * @param mixed $item Report record.
	 * @return string
	 */
	private function format_report_item( $item ) {
		if ( is_array( $item ) ) {
			if ( isset( $item['message'] ) && is_scalar( $item['message'] ) && '' !== (string) $item['message'] ) {
				return (string) $item['message'];
			}

			$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $item, JSON_PARTIAL_OUTPUT_ON_ERROR ) : json_encode( $item, JSON_PARTIAL_OUTPUT_ON_ERROR );
			return false !== $json ? $json : '(unserializable report record)';
		}

		return is_scalar( $item ) ? (string) $item : '(unserializable report record)';
	}

	/**
	 * Import a Bricks export zip file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to the zip file to import.
	 *
	 * [--dry-run]
	 * : Run preflight only; never write.
	 * [--conflict=<mode>]
	 * : Conflict policy: skip (default) or replace.
	 * [--allow-overwrite]
	 * : Required with --conflict=replace.
	 * [--allow-sensitive-settings]
	 * : Include sensitive settings when supported by the archive.
	 * [--import-images]
	 * : Import or reconnect images referenced by Bricks templates.
	 * [--backup-acknowledged]
	 * : Acknowledge that a backup exists before mutation.
	 * [--accept-warnings]
	 * : Permit mutation when preflight status is warning.
	 * [--yes]
	 * : Skip only the interactive confirmation prompt.
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
		$this->require_authorized_user();
		$file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : '';

		if ( empty( $file ) ) {
			WP_CLI::error( 'Please specify a zip file with --file=<path>.' );
		}

		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file ) );
		}

		$importer = new Bricks_IE_Importer();
		$conflict = isset( $assoc_args['conflict'] ) ? strtolower( (string) $assoc_args['conflict'] ) : 'skip';
		if ( ! in_array( $conflict, array( 'skip', 'replace' ), true ) ) WP_CLI::error( 'Invalid --conflict. Use skip or replace.' );
		$policy = array( 'conflict_mode' => $conflict );
		if ( $this->flag( $assoc_args, 'allow-overwrite' ) ) $policy['allow_overwrite'] = true;
		if ( $this->flag( $assoc_args, 'allow-sensitive-settings' ) ) $policy['allow_sensitive_settings'] = true;
		if ( $this->flag( $assoc_args, 'import-images' ) ) $policy['import_images'] = true;

		// Preflight is always the first importer operation and is strictly no-write.
		$report = $importer->preflight( $file, $policy );
		if ( is_wp_error( $report ) ) WP_CLI::error( $report->get_error_message() );
		if ( $this->flag( $assoc_args, 'dry-run' ) ) {
			WP_CLI::log( 'Preflight report: ' . json_encode( $report ) );
			return;
		}

		if ( 'blocked' === $report['status'] ) WP_CLI::error( 'Import blocked by preflight.' );
		if ( 'warning' === $report['status'] && ! $this->flag( $assoc_args, 'accept-warnings' ) ) WP_CLI::error( 'Preflight returned warnings. Re-run with --accept-warnings after reviewing the report.' );
		if ( ! $this->flag( $assoc_args, 'backup-acknowledged' ) ) WP_CLI::error( 'Mutation requires --backup-acknowledged.' );
		if ( 'replace' === $conflict && ! $this->flag( $assoc_args, 'allow-overwrite' ) ) WP_CLI::error( 'Replace requires --allow-overwrite.' );

		if ( ! $this->flag( $assoc_args, 'yes' ) ) {
			WP_CLI::log( 'This will mutate Bricks settings and content according to the confirmed preflight plan.' );
			WP_CLI::confirm( 'Are you sure you want to proceed?' );
		}
		$policy['backup_acknowledged'] = true;
		$policy['preflight'] = $report;
		WP_CLI::log( 'Importing Bricks state…' );
		$result = $importer->import_from_zip( $file, $policy );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$status = isset( $result['status'] ) ? $result['status'] : 'completed';
		if ( in_array( $status, array( 'blocked', 'failed', 'partial', 'cancelled' ), true ) ) WP_CLI::error( sprintf( 'Import %s: %s', $status, json_encode( $result ) ) );
		WP_CLI::success( sprintf(
			'Imported %d option(s), %d post(s), remapped %d ID(s) — schema %s, native %d type(s)/%d item(s), warnings %d.',
			$result['options_imported'],
			$result['posts_imported'],
			$result['id_remaps'],
			isset( $report['format_version'] ) ? $report['format_version'] : 'unknown',
			isset( $report['plan']['native']['types'] ) ? count( (array) $report['plan']['native']['types'] ) : 0,
			isset( $report['plan']['native']['items'] ) ? array_sum( array_map( 'count', (array) $report['plan']['native']['items'] ) ) : 0,
			isset( $result['warnings'] ) ? count( (array) $result['warnings'] ) : 0
		) );
		if ( 1 === (int) ( isset( $report['format_version'] ) ? $report['format_version'] : 0 ) && 'skip' === $conflict ) {
			WP_CLI::log( 'Schema v1 conflict policy is skip; existing posts were left untouched.' );
		}
	}

	/**
	 * Require an actual, authorized WordPress user before doing any command work.
	 *
	 * In particular, this must run before constructing an exporter/importer so a
	 * missing WP-CLI --user cannot cause any archive or state operation.
	 *
	 * @return void
	 */
	private function require_authorized_user() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$authorized = $user_id > 0 && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		if ( ! $authorized ) {
			WP_CLI::error( 'A real current WordPress administrator is required. Run WP-CLI with --user=<administrator>.' );
		}
	}

	private function flag( $args, $name ) {
		return isset( $args[ $name ] ) && ( false !== $args[ $name ] ) && ( true === $args[ $name ] || '1' === (string) $args[ $name ] || 'true' === strtolower( (string) $args[ $name ] ) || '' === (string) $args[ $name ] );
	}
}
