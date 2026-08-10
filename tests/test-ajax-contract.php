<?php
/** Contract checks for the bootstrap's AJAX wiring.
 *
 * These tests intentionally inspect the bootstrap without loading WordPress:
 * the project runner is dependency-free, while the production callbacks are
 * exercised with mocked Bricks_IE_Importer instances by the integration suite.
 */

bricks_ie_test( 'ajax contract: dependencies and actions are wired', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/bricks-import-export.php' );
	bricks_ie_assert( false !== strpos( $source, "class-archive-validator.php" ) );
	bricks_ie_assert( false !== strpos( $source, "class-bricks-transfer-adapter.php" ) );
	bricks_ie_assert( false !== strpos( $source, "wp_ajax_bricks_ie_import_preflight" ) );
	bricks_ie_assert( false !== strpos( $source, "wp_ajax_bricks_ie_import_start', 'bricks_ie_ajax_import_preflight" ) );
	bricks_ie_assert( false !== strpos( $source, "wp_ajax_bricks_ie_import_confirm" ) );
	bricks_ie_assert( false !== strpos( $source, "wp_ajax_bricks_ie_import_cancel" ) );
} );

bricks_ie_test( 'ajax contract: tokens and confirmation policy cannot be bypassed', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/bricks-import-export.php' );
	bricks_ie_assert( false !== strpos( $source, 'run_import_session_step( $session_id, $session_token )' ) );
	bricks_ie_assert( false !== strpos( $source, 'cancel_import_session( $session_id, $session_token )' ) );
	bricks_ie_assert( false !== strpos( $source, 'confirm_import_session( $session_id, $session_token, $confirmation )' ) );
	bricks_ie_assert( false !== strpos( $source, "'import_images'        => false" ) );
	bricks_ie_assert( false !== strpos( $source, "'plan'                 => \$plan_policy" ) );
	bricks_ie_assert( false !== strpos( $source, "check_ajax_referer( 'bricks_ie_import', '_ajax_nonce', false )" ) );
	bricks_ie_assert( false !== strpos( $source, "'code'    => (string) \$code" ) );
	bricks_ie_assert( false !== strpos( $source, "\$error['data'] = \$data" ) );
	bricks_ie_assert( false !== strpos( $source, "function bricks_ie_ajax_import_scalar" ) );
	bricks_ie_assert( false !== strpos( $source, "preg_match( '/\\A[a-f0-9]{64}\\z/i'" ) );
	bricks_ie_assert( false !== strpos( $source, "'invalid_archive_hash'" ) );
	bricks_ie_assert( false !== strpos( $source, "'invalid_plan_hash'" ) );
	bricks_ie_assert( false !== strpos( $source, "'plan_hash'            => \$plan_hash" ) );
	bricks_ie_assert( false !== strpos( $source, "'allow_sensitive_settings' => \$policy['allow_sensitive_settings']" ) );
} );

bricks_ie_test( 'admin fallback: never constructs a mutating importer', function () {
	$source = file_get_contents( dirname( __DIR__ ) . '/bricks-import-export.php' );
	$handler = substr( $source, strpos( $source, 'function bricks_ie_handle_import' ), strpos( $source, "add_action( 'wp_ajax_bricks_ie_import_preflight'" ) - strpos( $source, 'function bricks_ie_handle_import' ) );
	bricks_ie_assert( false !== strpos( $handler, "check_admin_referer( 'bricks_ie_import' )" ) );
	bricks_ie_assert( false === strpos( $handler, 'new Bricks_IE_Importer' ) );
	bricks_ie_assert( false === strpos( $handler, '->upload' ) );
	bricks_ie_assert( false !== strpos( $handler, 'require JavaScript preflight' ) );
} );

bricks_ie_test( 'admin flow: controls, localized contract, and token forwarding are present', function () {
	$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-admin-page.php' );
	$script = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
	foreach ( array( 'bricks-ie-conflict-mode', 'bricks-ie-allow-overwrite', 'bricks-ie-allow-sensitive', 'bricks-ie-preflight-review', 'bricks-ie-backup-ack', 'bricks-ie-warning-ack', 'bricks-ie-progress-cancel' ) as $id ) {
		bricks_ie_assert( false !== strpos( $admin, $id ), $id . ' control is required.' );
	}
	foreach ( array( 'bricks-ie-conflict-help', 'bricks-ie-sensitive-help' ) as $help_id ) {
		bricks_ie_assert( false !== strpos( $admin, 'aria-describedby="' . $help_id . '"' ), $help_id . ' must be announced with the control.' );
	}
	bricks_ie_assert( false !== strpos( $admin, 'class="bricks-ie-help-anchor"' ), 'Help content must be anchored to its icon.' );
	bricks_ie_assert( false !== strpos( $admin, 'role="tooltip"' ), 'Help content must use tooltip semantics.' );
	foreach ( array( 'preflighting', 'backup', 'warningAck', 'overwrite', 'sensitive', 'blocked', 'cancelled', 'expired', 'unauthorized', 'leaseLost' ) as $key ) {
		bricks_ie_assert( false !== strpos( $admin, "'" . $key . "'" ), $key . ' must be localized.' );
	}
	foreach ( array( 'bricks_ie_import_preflight', 'bricks_ie_import_confirm', 'bricks_ie_import_step', 'bricks_ie_import_cancel', 'session_token', 'archive_hash', 'plan_hash' ) as $value ) {
		bricks_ie_assert( false !== strpos( $script, $value ), $value . ' must be sent by the client.' );
	}
	bricks_ie_assert( false === strpos( $script, '.html(' ), 'Archive rendering must not use html().' );
	bricks_ie_assert( false === strpos( $script, 'innerHTML' ), 'Archive rendering must not use innerHTML.' );
	bricks_ie_assert( false !== strpos( $script, 'completed_steps' ), 'Completed steps must be tracked by explicit IDs.' );
	bricks_ie_assert( false !== strpos( $script, 'failed_steps' ), 'Failed step IDs must be rendered as errors.' );
	bricks_ie_assert( false !== strpos( $script, 'data.failed' ), 'Importer failed step IDs must be rendered as errors.' );
	bricks_ie_assert( false !== strpos( $script, 'responseEnvelope' ), 'AJAX response data must use one safe envelope extractor.' );
	bricks_ie_assert( false !== strpos( $script, "code === 'import_in_progress'" ), 'Transient contention must be detected from data.code.' );
	bricks_ie_assert( false === strpos( $script, "response.data.status === 'import_in_progress'" ), 'Transient contention must not read status from the outer AJAX response.' );
	bricks_ie_assert( false !== strpos( $script, 'confirmError' ), 'Confirmation errors must keep the session actionable.' );
	bricks_ie_assert( false === strpos( $script, '|| data.done' ), 'data.done must not mark every step complete.' );
	bricks_ie_assert( false !== strpos( $script, "status === 'failed'" ), 'Failed terminal status must not be complete.' );
	bricks_ie_assert( false !== strpos( $script, 'focus-trigger' ), 'Modal focus must be restored after close.' );
	bricks_ie_assert( false !== strpos( $script, "event.key === 'Tab'" ), 'Visible modals must trap focus.' );
	bricks_ie_assert( false !== strpos( $script, 'requestBusy' ), 'Cancellation must respect in-flight requests.' );
	bricks_ie_assert( false !== strpos( $script, 'cancelButtons.prop(\'disabled\', true)' ), 'Cancellation controls must lock in flight.' );
} );

bricks_ie_test( 'admin flow: captures the upload before disabling form controls', function () {
	$script          = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );
	$preflight_start = strpos( $script, 'function preflight()' );
	$preflight_end   = strpos( $script, 'if (!form.length', $preflight_start );
	$preflight       = substr( $script, $preflight_start, $preflight_end - $preflight_start );
	$form_data       = strpos( $preflight, 'new FormData(form[0])' );
	$disable_form    = strpos( $preflight, 'disableForm(true)' );

	bricks_ie_assert( false !== $form_data, 'Preflight must capture the import form data.' );
	bricks_ie_assert( false !== $disable_form, 'Preflight must disable the form while uploading.' );
	bricks_ie_assert( $form_data < $disable_form, 'FormData must be constructed before disabling the file input.' );
} );
