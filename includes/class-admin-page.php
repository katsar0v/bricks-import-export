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
					<?php esc_html_e( 'Download a complete snapshot of your Bricks Builder configuration:', 'bricks-ie' ); ?>
				</p>
				<ul class="bricks-ie-export-checklist">
					<li><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span> <?php esc_html_e( 'Bricks settings', 'bricks-ie' ); ?></li>
					<li><span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> <?php esc_html_e( 'Bricks theme styles', 'bricks-ie' ); ?></li>
					<li><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <?php esc_html_e( 'Pages and Bricks templates (with all Bricks meta)', 'bricks-ie' ); ?></li>
					<li><span class="dashicons dashicons-art" aria-hidden="true"></span> <?php esc_html_e( 'Bricks global classes & color palette', 'bricks-ie' ); ?></li>
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
							<?php esc_html_e( 'Select a Bricks export .zip file to restore a previously exported Bricks Builder state.', 'bricks-ie' ); ?>
						</p>
						<input type="file" name="bricks_ie_import_file" accept=".zip" required>
					</div>
					<br>
					<?php submit_button( __( 'Import', 'bricks-ie' ), 'secondary', 'bricks_ie_import_submit', false ); ?>
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
					<code>wp bricks import --file=bricks-export-2026-05-12.zip</code>
					<p class="description"><?php esc_html_e( 'Imports from a zip file. Prompts for confirmation.', 'bricks-ie' ); ?></p>
					<code>wp bricks import --file=bricks-export-2026-05-12.zip --yes</code>
					<p class="description"><?php esc_html_e( 'Imports without confirmation prompt.', 'bricks-ie' ); ?></p>
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
					<p><?php esc_html_e( 'This will overwrite your current Bricks settings, theme styles, global classes, color palette, and all Bricks template and page content.', 'bricks-ie' ); ?></p>
					<p><strong><?php esc_html_e( 'This action cannot be undone. Are you sure you want to proceed?', 'bricks-ie' ); ?></strong></p>
				</div>
				<div class="bricks-ie-modal__footer">
					<button type="button" class="button" id="bricks-ie-modal-cancel"><?php esc_html_e( 'Cancel', 'bricks-ie' ); ?></button>
					<button type="button" class="button button-primary" id="bricks-ie-modal-confirm"><?php esc_html_e( 'Import Now', 'bricks-ie' ); ?></button>
				</div>
			</div>
		</div>

		<script>
		(function($) {
			var $form = $('#bricks-ie-import-form');
			var $modal = $('#bricks-ie-confirm-modal');
			var visibleClass = 'bricks-ie-modal-overlay--visible';
			var confirmed = false;

			function openModal() {
				$modal.addClass(visibleClass);
			}

			function closeModal() {
				$modal.removeClass(visibleClass);
			}

			$form.on('submit', function(e) {
				if (!confirmed) {
					e.preventDefault();
					openModal();
					return false;
				}
			});

			$('#bricks-ie-modal-cancel').on('click', function() {
				closeModal();
			});

			$('#bricks-ie-modal-confirm').on('click', function() {
				closeModal();
				confirmed = true;
				$form[0].submit();
			});

			$modal.on('click', function(e) {
				if (e.target === this) {
					closeModal();
				}
			});

			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && $modal.hasClass(visibleClass)) {
					closeModal();
				}
			});
		})(jQuery);
		</script>
		<?php
	}
}
