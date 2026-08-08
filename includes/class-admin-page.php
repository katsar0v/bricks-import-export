<?php
/**
 * Admin page for Bricks Import & Export.
 *
 * Registers a submenu page under Bricks and renders the export/import UI.
 *
 * @package BricksIE
 */

class Bricks_IE_Admin_Page {

	/**
	 * Singleton instance.
	 *
	 * @var Bricks_IE_Admin_Page|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Bricks_IE_Admin_Page
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hooks into WordPress.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page under Bricks (or under Tools when Bricks is inactive).
	 */
	public function register_page() {
		$parent_slug = bricks_ie_is_bricks_active() ? 'bricks' : 'tools.php';

		add_submenu_page(
			$parent_slug,
			__( 'Import & Export', 'bricks-ie' ),
			__( 'Import & Export', 'bricks-ie' ),
			'manage_options',
			'bricks-import-export',
			array( $this, 'render' )
		);

		if ( bricks_ie_is_bricks_active() ) {
			add_action( 'admin_menu', array( $this, 'move_to_last' ), 9999 );
		}
	}

	/**
	 * Move our submenu entry to the last position under Bricks.
	 */
	public function move_to_last() {
		global $submenu;

		if ( empty( $submenu['bricks'] ) ) {
			return;
		}

		foreach ( $submenu['bricks'] as $i => $item ) {
			if ( 'bricks-import-export' === $item[2] ) {
				$entry = $submenu['bricks'][ $i ];
				unset( $submenu['bricks'][ $i ] );
				$submenu['bricks'][] = $entry;
				break;
			}
		}
	}

	/**
	 * Enqueue admin CSS on our page only.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$allowed_hooks = array(
			'bricks_page_bricks-import-export',  // registered under Bricks menu
			'tools_page_bricks-import-export',   // registered under Tools when Bricks is inactive
		);
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

			wp_enqueue_style(
				'bricks-ie-admin',
				BRICKS_IE_URL . 'assets/admin.css',
				array(),
				BRICKS_IE_VERSION
			);

			wp_enqueue_script(
				'bricks-ie-admin',
				BRICKS_IE_URL . 'assets/admin.js',
				array( 'jquery' ),
				BRICKS_IE_VERSION,
				true
			);

			wp_localize_script(
				'bricks-ie-admin',
				'bricksIEImport',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'bricks_ie_import' ),
					'i18n'    => array(
						'ajaxError'      => __( 'The import request failed. Please try again.', 'bricks-ie' ),
						'importComplete' => __( 'Import complete.', 'bricks-ie' ),
						'importFailed'   => __( 'Import failed.', 'bricks-ie' ),
						'importPartial'  => __( 'Import completed with warnings.', 'bricks-ie' ),
						'importCancelled'=> __( 'Import cancelled.', 'bricks-ie' ),
						'leaveWarning'   => __( 'An import is currently running. Leaving this page may interrupt it.', 'bricks-ie' ),
						'partialChanges' => __( 'Partial changes may already have been applied because imports are not transactional.', 'bricks-ie' ),
						'selectFile'     => __( 'Please choose a .zip file to import.', 'bricks-ie' ),
						'preflighting'   => __( 'Uploading and preparing review...', 'bricks-ie' ),
						'backup'         => __( 'I have a recent backup and understand that imports are not reversible.', 'bricks-ie' ),
						'warningAck'     => __( 'I understand and accept the warnings shown above.', 'bricks-ie' ),
						'overwrite'      => __( 'Authorize replacing existing Bricks data.', 'bricks-ie' ),
						'sensitive'      => __( 'Allow sensitive settings to be imported (strong warning).', 'bricks-ie' ),
						'notSupported'   => __( 'General media and remote template image downloads are not supported.', 'bricks-ie' ),
						'review'         => __( 'Preflight review', 'bricks-ie' ),
						'blocked'        => __( 'This archive is blocked and cannot be imported.', 'bricks-ie' ),
						'cancelImport'   => __( 'Cancel import', 'bricks-ie' ),
						'cancelled'      => __( 'The import was cancelled. Cleanup was attempted.', 'bricks-ie' ),
						'expired'        => __( 'The import session expired. Please start again.', 'bricks-ie' ),
						'unauthorized'   => __( 'Your import authorization is no longer valid. Please refresh and try again.', 'bricks-ie' ),
						'leaseLost'      => __( 'The import lease was lost. No further steps can be run.', 'bricks-ie' ),
					),
				)
			);
		}

	/**
	 * Render the settings page.
	 */
	public function render() {
		$bricks_active = bricks_ie_is_bricks_active();
		$import_status = isset( $_GET['bricks_ie_import'] ) ? sanitize_text_field( wp_unslash( $_GET['bricks_ie_import'] ) ) : '';
		?>
		<div class="wrap">
			<h1 style="margin-bottom: 1.5em;"><?php esc_html_e( 'Import & Export', 'bricks-ie' ); ?></h1>

			<?php if ( ! $bricks_active ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Bricks Builder is not active.', 'bricks-ie' ); ?></strong>
						<?php esc_html_e( 'Please install and activate the Bricks theme to use this plugin.', 'bricks-ie' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 'ok' === $import_status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bricks state imported successfully.', 'bricks-ie' ); ?></p></div>
			<?php elseif ( 'error' === $import_status && isset( $_GET['msg'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( wp_unslash( $_GET['msg'] ) ); ?></p></div>
			<?php endif; ?>

			<div class="bricks-ie-section bricks-ie-section--export">
				<h2><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export Bricks State', 'bricks-ie' ); ?></h2>
				<fieldset <?php echo $bricks_active ? '' : 'disabled'; ?>>
				<p class="description">
					<?php esc_html_e( 'Download the selected Bricks Builder configuration for transfer or backup:', 'bricks-ie' ); ?>
				</p>
				<ul class="bricks-ie-export-checklist">
					<li><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span> <?php esc_html_e( 'Bricks settings', 'bricks-ie' ); ?></li>
					<li><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> <?php esc_html_e( 'Style Manager, theme styles, and color palettes', 'bricks-ie' ); ?></li>
					<li><span class="dashicons dashicons-art" aria-hidden="true"></span> <?php esc_html_e( 'Global classes, variables, components, queries, and elements', 'bricks-ie' ); ?></li>
					<li><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <?php esc_html_e( 'Pages, Bricks templates, and enabled post types (with all Bricks meta)', 'bricks-ie' ); ?></li>
				</ul>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'bricks_ie_export' ); ?>
					<input type="hidden" name="action" value="bricks_ie_export">
					<?php submit_button( __( 'Download Export (.zip)', 'bricks-ie' ), 'primary', 'submit', false ); ?>
				</form>
				</fieldset>
			</div>

			<div class="bricks-ie-section bricks-ie-section--import">
				<h2><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Import Bricks State', 'bricks-ie' ); ?></h2>
				<fieldset <?php echo $bricks_active ? '' : 'disabled'; ?>>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="bricks-ie-import-form">
					<?php wp_nonce_field( 'bricks_ie_import' ); ?>
					<input type="hidden" name="action" value="bricks_ie_import">
					<div class="bricks-ie-import-dropzone">
						<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
						<p class="description">
							<?php esc_html_e( 'Select a Bricks export .zip file to review and import selected Bricks Builder data.', 'bricks-ie' ); ?>
						</p>
						<input type="file" name="bricks_ie_import_file" accept=".zip" required>
					</div>
					<p class="description bricks-ie-not-supported"><?php esc_html_e( 'General media and remote template image downloads are not supported.', 'bricks-ie' ); ?></p>
					<p class="bricks-ie-conflict-control"><span class="bricks-ie-label-with-help"><label for="bricks-ie-conflict-mode"><?php esc_html_e( 'Conflict handling', 'bricks-ie' ); ?></label> <span class="bricks-ie-help-anchor"><button type="button" class="bricks-ie-help" aria-expanded="false" aria-controls="bricks-ie-conflict-help" aria-describedby="bricks-ie-conflict-help" aria-label="<?php esc_attr_e( 'About conflict handling', 'bricks-ie' ); ?>"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button><span id="bricks-ie-conflict-help" class="bricks-ie-tooltip" role="tooltip" hidden><?php esc_html_e( 'Skip keeps an existing item unchanged and imports only items that are not already on this site. Replace overwrites the matching item with the archive version. New items are imported in both modes; Replace also requires the authorization checkbox below.', 'bricks-ie' ); ?></span></span></span><br>
						<select name="conflict_mode" id="bricks-ie-conflict-mode"><option value="skip" selected><?php esc_html_e( 'Skip existing items (recommended)', 'bricks-ie' ); ?></option><option value="replace"><?php esc_html_e( 'Replace existing items', 'bricks-ie' ); ?></option></select></p>
					<p><label><input type="checkbox" name="allow_overwrite" id="bricks-ie-allow-overwrite" value="1" disabled> <?php esc_html_e( 'Authorize replacing existing Bricks data.', 'bricks-ie' ); ?></label></p>
					<p><span class="bricks-ie-label-with-help"><label><input type="checkbox" name="allow_sensitive_settings" id="bricks-ie-allow-sensitive" value="1"> <?php esc_html_e( 'Allow sensitive settings to be imported (strong warning).', 'bricks-ie' ); ?></label> <span class="bricks-ie-help-anchor"><button type="button" class="bricks-ie-help" aria-expanded="false" aria-controls="bricks-ie-sensitive-help" aria-describedby="bricks-ie-sensitive-help" aria-label="<?php esc_attr_e( 'About sensitive settings', 'bricks-ie' ); ?>"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></button><span id="bricks-ie-sensitive-help" class="bricks-ie-tooltip" role="tooltip" hidden><?php esc_html_e( 'Imports Google Maps and reCAPTCHA API keys, custom CSS and JavaScript, and template passwords from the archive. These values are skipped by default. Enable this only for a trusted archive when you intend to import them.', 'bricks-ie' ); ?></span></span></span></p>
					<br>
					<?php submit_button( __( 'Review import', 'bricks-ie' ), 'secondary', 'bricks_ie_import_submit', false ); ?>
				</form>
				</fieldset>
			</div>

			<div class="bricks-ie-section bricks-ie-section--cli">
				<h2><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> <?php esc_html_e( 'WP-CLI Commands', 'bricks-ie' ); ?></h2>
				<fieldset <?php echo $bricks_active ? '' : 'disabled'; ?>>
				<p class="description">
					<?php esc_html_e( 'You can also export and import Bricks state via the command line:', 'bricks-ie' ); ?>
				</p>
				<div class="bricks-ie-cli-examples">
					<h3><?php esc_html_e( 'Export', 'bricks-ie' ); ?></h3>
					<code>wp bricks export</code>
					<p class="description"><?php esc_html_e( 'Exports to bricks-export-YYYY-MM-DD.zip in the current directory.', 'bricks-ie' ); ?></p>
					<code>wp bricks export /tmp/my-backup.zip</code>
					<p class="description"><?php esc_html_e( 'Exports to a custom path.', 'bricks-ie' ); ?></p>

					<h3><?php esc_html_e( 'Import', 'bricks-ie' ); ?></h3>
					<code>wp bricks import --file=bricks-export-2026-05-12.zip --dry-run</code>
					<p class="description"><?php esc_html_e( 'Runs preflight and prints the report without changing the site.', 'bricks-ie' ); ?></p>
					<code>wp bricks import --file=bricks-export-2026-05-12.zip --backup-acknowledged --yes</code>
					<p class="description"><?php esc_html_e( 'Imports without the interactive prompt after acknowledging a recent backup.', 'bricks-ie' ); ?></p>
				</div>
				</fieldset>
			</div>
		</div>

			<div class="bricks-ie-modal-overlay" id="bricks-ie-confirm-modal">
				<div class="bricks-ie-modal" role="dialog" aria-modal="true" aria-labelledby="bricks-ie-modal-title">
					<div class="bricks-ie-modal__header">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<h3 id="bricks-ie-modal-title"><?php esc_html_e( 'Confirm Import', 'bricks-ie' ); ?></h3>
				</div>
				<div class="bricks-ie-modal__body">
					<div id="bricks-ie-preflight-review" class="bricks-ie-preflight-review"></div>
					<p><label><input type="checkbox" id="bricks-ie-backup-ack"> <?php esc_html_e( 'I have a recent backup and understand that imports are not reversible.', 'bricks-ie' ); ?></label></p>
					<p id="bricks-ie-warning-ack-wrap" hidden><label><input type="checkbox" id="bricks-ie-warning-ack"> <?php esc_html_e( 'I understand and accept the warnings shown above.', 'bricks-ie' ); ?></label></p>
				</div>
				<div class="bricks-ie-modal__footer">
					<button type="button" class="button" id="bricks-ie-modal-cancel"><?php esc_html_e( 'Cancel', 'bricks-ie' ); ?></button>
					<button type="button" class="button button-primary" id="bricks-ie-modal-confirm" disabled><?php esc_html_e( 'Import Now', 'bricks-ie' ); ?></button>
					</div>
				</div>
			</div>

			<div class="bricks-ie-modal-overlay" id="bricks-ie-progress-modal">
				<div class="bricks-ie-modal bricks-ie-modal--progress" role="dialog" aria-modal="true" aria-labelledby="bricks-ie-progress-title" aria-describedby="bricks-ie-progress-message">
					<div class="bricks-ie-modal__header">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<h3 id="bricks-ie-progress-title"><?php esc_html_e( 'Import Progress', 'bricks-ie' ); ?></h3>
					</div>
					<div class="bricks-ie-modal__body">
						<div class="bricks-ie-progress">
							<div class="bricks-ie-progress__meta">
								<span id="bricks-ie-progress-message"><?php esc_html_e( 'Preparing import...', 'bricks-ie' ); ?></span>
								<strong id="bricks-ie-progress-percent">0%</strong>
							</div>
							<div class="bricks-ie-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
								<span id="bricks-ie-progress-bar"></span>
							</div>
						</div>
						<ol class="bricks-ie-progress-steps" id="bricks-ie-progress-steps" aria-live="polite"></ol>
						<div class="bricks-ie-progress-summary" id="bricks-ie-progress-summary" hidden></div>
						<div class="bricks-ie-progress-error" id="bricks-ie-progress-error" hidden></div>
					</div>
					<div class="bricks-ie-modal__footer">
						<button type="button" class="button" id="bricks-ie-progress-cancel"><?php esc_html_e( 'Cancel import', 'bricks-ie' ); ?></button>
						<button type="button" class="button button-primary" id="bricks-ie-progress-close" hidden><?php esc_html_e( 'Close', 'bricks-ie' ); ?></button>
					</div>
				</div>
			</div>
			<?php
		}
	}
