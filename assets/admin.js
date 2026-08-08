(function ($) {
    'use strict';

    var config = window.bricksIEImport || {}, i18n = config.i18n || {};
    var visible = 'bricks-ie-modal-overlay--visible', busy = false, requestBusy = false;
    var sessionId = '', sessionToken = '', archiveHash = '', planHash = '', plan = {}, reportStatus = '', allowSensitiveSettings = false, mutationActive = false;
    var form = $('#bricks-ie-import-form'), confirmModal = $('#bricks-ie-confirm-modal'), progressModal = $('#bricks-ie-progress-modal');
    var review = $('#bricks-ie-preflight-review'), confirmButton = $('#bricks-ie-modal-confirm');
    var backup = $('#bricks-ie-backup-ack'), warningAck = $('#bricks-ie-warning-ack'), warningWrap = $('#bricks-ie-warning-ack-wrap');
    var conflict = $('#bricks-ie-conflict-mode'), overwrite = $('#bricks-ie-allow-overwrite');
    var message = $('#bricks-ie-progress-message'), percent = $('#bricks-ie-progress-percent'), bar = $('#bricks-ie-progress-bar');
    var barWrap = $('.bricks-ie-progress__bar'), steps = $('#bricks-ie-progress-steps'), summary = $('#bricks-ie-progress-summary'), error = $('#bricks-ie-progress-error');

    function t(key, fallback) { return i18n[key] || fallback; }
    function setConflictHelp(button, show) {
        var tooltip = $('#' + button.attr('aria-controls'));
        button.attr('aria-expanded', show ? 'true' : 'false');
        tooltip.prop('hidden', !show);
    }
    $('.bricks-ie-help').each(function () {
        var button = $(this), wrapper = button.closest('.bricks-ie-label-with-help'), touchAt = 0;
        wrapper.on('mouseenter', function () { setConflictHelp(button, true); });
        wrapper.on('mouseleave', function () { if (!wrapper.find(':focus').length) setConflictHelp(button, false); });
        button.on('focusin', function () { setConflictHelp(button, true); });
        button.on('focusout', function (event) { if (!wrapper[0].contains(event.relatedTarget)) setConflictHelp(button, false); });
		button.on('touchend', function (event) { touchAt = Date.now(); event.preventDefault(); setConflictHelp(button, button.attr('aria-expanded') !== 'true'); });
        button.on('click', function (event) { if (Date.now() - touchAt < 500) return; event.preventDefault(); setConflictHelp(button, true); });
	});
	$(document).on('touchstart', function (event) {
		$('.bricks-ie-help[aria-expanded="true"]').each(function () {
			var button = $(this), wrapper = button.closest('.bricks-ie-label-with-help')[0];
			if (wrapper && !wrapper.contains(event.target)) setConflictHelp(button, false);
		});
	});
	var cancelButtons = $('#bricks-ie-modal-cancel, #bricks-ie-progress-cancel');
    function open(modal) { if (!modal.hasClass(visible) && document.activeElement && !modal[0].contains(document.activeElement)) modal.data('bricks-ie-focus-trigger', document.activeElement); modal.addClass(visible); var focus = modal.find('button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])').first(); if (focus.length) focus.trigger('focus'); }
    function close(modal) { var trigger = modal.data('bricks-ie-focus-trigger'); modal.removeClass(visible).removeData('bricks-ie-focus-trigger'); if (trigger && document.documentElement.contains(trigger) && !$(trigger).is(':disabled')) $(trigger).trigger('focus'); }
    function disableForm(value) { form.find('input,button,select').prop('disabled', value); }
    function errorMessage(response) {
        var envelope = responseEnvelope(response), data = envelope.data, code = envelope.code;
        if (code === 'expired_session') return t('expired', 'The import session expired. Please start again.');
        if (code === 'import_unauthorized') return t('unauthorized', 'Your import authorization is no longer valid. Please refresh and try again.');
        if (code === 'import_lease_lost' || code === 'lease_lost') return t('leaseLost', 'The import lease was lost. No further steps can be run.');
        return data && data.message ? data.message : t('ajaxError', 'The import request failed. Please try again.');
    }
    function responseEnvelope(response) { var payload = response && (response.responseJSON || response), data = payload && payload.data; return { data: data || {}, code: data && data.code ? data.code : '' }; }
    function isImportInProgress(response) { return responseEnvelope(response).code === 'import_in_progress'; }
    function node(tag, text) { var el = document.createElement(tag); if (text !== undefined) el.textContent = String(text); return el; }
    function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
    function itemText(item) {
        if (item === null || item === undefined) return '';
        if (typeof item !== 'object') return String(item);
        return item.label || item.name || item.slug || item.domain || item.message || item.action || JSON.stringify(item);
    }
    function list(title, value) {
        if (!value || (Array.isArray(value) && !value.length)) return;
        var values = Array.isArray(value) ? value : [value], section = node('section'), heading = node('h4', title + ' (' + values.length + ')'), ul = node('ul');
        section.appendChild(heading); values.forEach(function (item) { ul.appendChild(node('li', itemText(item))); }); section.appendChild(ul); review[0].appendChild(section);
    }
    function renderReview(report) {
        clear(review[0]);
        var title = node('h4', t('review', 'Preflight review')); review[0].appendChild(title);
        var source = report.source_environment || {}, target = report.target_environment || {}, meta = node('p');
        meta.textContent = 'Format: ' + (report.format_version || 'unknown') + ' | Source URL: ' + (source.site_url || 'unknown') + ' | Source Bricks: ' + (source.bricks_version || 'unknown') + ' | Target Bricks: ' + (target.bricks_version || 'unknown'); review[0].appendChild(meta);
        list('Native domains', report.native_domains); list('Posts', report.posts);
        list('Conflicts', report.conflicts); list('Dependencies', report.dependencies); list('Omissions', report.omissions);
        list('Security warnings', report.security_warnings); list('Warnings', report.warnings);
        if (report.blocking && report.blocking.length) list('Blocking issues', report.blocking);
        if (report.status === 'blocked') { var blocked = node('p', t('blocked', 'This archive is blocked and cannot be imported. It can be cancelled, but not confirmed.')); blocked.className = 'bricks-ie-preflight-blocked'; review[0].appendChild(blocked); }
    }
    function resetProgress() { summary.prop('hidden', true).empty(); error.prop('hidden', true).empty(); steps.empty(); bar.css('width', '0%'); percent.text('0%'); barWrap.attr('aria-valuenow', 0); }
    function renderSteps(items) {
        steps.empty(); (items || []).forEach(function (step) { var li = node('li'), icon = node('span'), label = node('span'); li.className = 'bricks-ie-progress-step'; li.setAttribute('data-step', step.key || ''); icon.className = 'bricks-ie-progress-step__icon dashicons dashicons-marker'; icon.setAttribute('aria-hidden', 'true'); label.className = 'bricks-ie-progress-step__label'; label.textContent = step.label || ''; li.appendChild(icon); li.appendChild(label); steps[0].appendChild(li); });
    }
    function progress(data) {
        var value = Math.max(0, Math.min(100, parseInt(data.percent, 10) || 0)); if (data.steps) renderSteps(data.steps);
        message.text(data.message || ''); percent.text(value + '%'); bar.css('width', value + '%'); barWrap.attr('aria-valuenow', value);
        var done = data.completed_steps || [], failed = data.failed_steps || data.error_steps || data.failed || []; failed = Array.isArray(failed) ? failed : [failed]; steps.find('.bricks-ie-progress-step').each(function () { var el = $(this), icon = el.find('.bricks-ie-progress-step__icon'), key = el.attr('data-step'), step = (data.steps || []).filter(function (item) { return item.key === key; })[0] || {}; el.removeClass('is-complete is-current is-error'); icon.removeClass('dashicons-marker dashicons-update dashicons-yes-alt dashicons-no-alt'); if ($.inArray(key, failed) !== -1 || step.status === 'failed' || step.status === 'error') { el.addClass('is-error'); icon.addClass('dashicons-no-alt'); } else if ($.inArray(key, done) !== -1) { el.addClass('is-complete'); icon.addClass('dashicons-yes-alt'); } else if (key === (data.current_step || '')) { el.addClass('is-current'); icon.addClass('dashicons-update'); } else icon.addClass('dashicons-marker'); });
        if (data.summary) summary.text(data.summary).prop('hidden', false);
        if (data.warnings) listProgress(data.warnings); if (data.results) listProgress(data.results);
    }
    function listProgress(items) { (Array.isArray(items) ? items : [items]).forEach(function (item) { var p = node('p', typeof item === 'object' ? (item.message || item.label || JSON.stringify(item)) : item); summary.append(p).prop('hidden', false); }); }
    function resetFormState() { confirmButton.prop('disabled', true); backup.prop('checked', false); warningAck.prop('checked', false); warningWrap.prop('hidden', true); overwrite.prop('checked', false).prop('required', false); }
    function terminal(status, textValue, data, acknowledged) { busy = false; mutationActive = false; requestBusy = false; if (acknowledged) { sessionId = ''; sessionToken = ''; archiveHash = ''; planHash = ''; plan = {}; } reportStatus = ''; allowSensitiveSettings = false; disableForm(false); resetFormState(); cancelButtons.prop('disabled', false); progressModal.removeClass('is-running is-error is-complete is-partial is-cancelled').addClass('is-' + status); $('#bricks-ie-progress-cancel').prop('hidden', true); $('#bricks-ie-progress-close').prop('hidden', false); message.text(textValue); if (data) progress(data); open(progressModal); }
    function fail(response, data) { var hadMutation = mutationActive; error.text(errorMessage(response) + (hadMutation ? ' ' + t('partialChanges', 'Partial changes may already have been applied because imports are not transactional.') : '')).prop('hidden', false); terminal('error', t('importFailed', 'Import failed.'), data, false); }
    function confirmError(response) { requestBusy = false; error.text(errorMessage(response)).prop('hidden', false); disableForm(false); confirmButton.prop('disabled', false); cancelButtons.prop('disabled', false); open(confirmModal); }
    function cancel() {
        if (requestBusy || !sessionId || !sessionToken) return;
        requestBusy = true; cancelButtons.prop('disabled', true); $.post(config.ajaxUrl, { action: 'bricks_ie_import_cancel', _ajax_nonce: config.nonce, session_id: sessionId, session_token: sessionToken }).always(function (response) { requestBusy = false; if (isImportInProgress(response)) { cancelButtons.prop('disabled', false); progress(responseEnvelope(response).data); return; } if (response && response.success) terminal('cancelled', t('cancelled', 'The import was cancelled. Cleanup was attempted.'), responseEnvelope(response).data, true); else { cancelButtons.prop('disabled', false); fail(response); } });
    }
    function nextStep() {
        if (!mutationActive || requestBusy || !sessionId) return;
        requestBusy = true; $.post(config.ajaxUrl, { action: 'bricks_ie_import_step', _ajax_nonce: config.nonce, session_id: sessionId, session_token: sessionToken }).done(function (response) { requestBusy = false; if (!response || !response.success) { if (isImportInProgress(response)) { progress(responseEnvelope(response).data); return window.setTimeout(nextStep, 150); } return fail(response, responseEnvelope(response).data); } progress(responseEnvelope(response).data); if (responseEnvelope(response).data.done) { var stepData = responseEnvelope(response).data, terminalStatus = stepData.status === 'partial' ? 'partial' : (stepData.status === 'cancelled' ? 'cancelled' : (stepData.status === 'failed' || stepData.status === 'blocked' || stepData.status === 'error' ? 'error' : 'complete')); terminal(terminalStatus, terminalStatus === 'partial' ? t('importPartial', 'Import completed with warnings.') : terminalStatus === 'cancelled' ? t('cancelled', 'The import was cancelled.') : terminalStatus === 'error' ? t('importFailed', 'Import failed.') : t('importComplete', 'Import complete.'), stepData, true); } else window.setTimeout(nextStep, 150); }).fail(function (response) { requestBusy = false; if (isImportInProgress(response)) { progress(responseEnvelope(response).data); return window.setTimeout(nextStep, 150); } fail(response); });
    }
    function confirmImport() {
        if (requestBusy || reportStatus === 'blocked' || !sessionId || !backup.prop('checked') || (warningWrap.is(':visible') && !warningAck.prop('checked')) || (conflict.val() === 'replace' && !overwrite.prop('checked'))) return;
        requestBusy = true; confirmButton.prop('disabled', true); cancelButtons.filter('#bricks-ie-modal-cancel').prop('disabled', true); $.post(config.ajaxUrl, { action: 'bricks_ie_import_confirm', _ajax_nonce: config.nonce, session_id: sessionId, session_token: sessionToken, archive_hash: archiveHash, plan_hash: planHash, conflict_mode: conflict.val(), allow_overwrite: overwrite.prop('checked') ? '1' : '0', allow_sensitive_settings: allowSensitiveSettings ? '1' : '0', backup_acknowledged: '1', warnings_acknowledged: warningAck.prop('checked') ? '1' : '0' }).done(function (response) { requestBusy = false; if (!response || !response.success) { if (isImportInProgress(response)) return confirmError(response); return confirmError(response); } mutationActive = true; close(confirmModal); progressModal.removeClass('is-error is-complete is-partial').addClass('is-running'); $('#bricks-ie-progress-cancel').prop('hidden', false); resetProgress(); open(progressModal); progress(responseEnvelope(response).data); nextStep(); }).fail(function (response) { confirmError(response); });
    }
    function preflight() {
        var file = form.find('input[type=file]')[0]; if (!file || !file.files.length) { window.alert(t('selectFile', 'Please choose a .zip file to import.')); return; }
        if (busy || requestBusy) return; busy = true; requestBusy = true; sessionId = ''; sessionToken = ''; archiveHash = ''; planHash = ''; plan = {}; reportStatus = ''; disableForm(true); resetProgress(); progressModal.addClass('is-running'); $('#bricks-ie-progress-cancel').prop('hidden', true); $('#bricks-ie-progress-close').prop('hidden', true); message.text(t('preflighting', 'Uploading and preparing review...')); open(progressModal);
        allowSensitiveSettings = $('#bricks-ie-allow-sensitive').prop('checked'); var data = new FormData(form[0]); data.set ? (data.set('action', 'bricks_ie_import_preflight'), data.set('_ajax_nonce', config.nonce), data.set('allow_sensitive_settings', allowSensitiveSettings ? '1' : '0')) : (data.append('action', 'bricks_ie_import_preflight'), data.append('_ajax_nonce', config.nonce));
        $.ajax({ url: config.ajaxUrl, method: 'POST', dataType: 'json', data: data, processData: false, contentType: false }).done(function (response) { requestBusy = false; if (!response || !response.success) return fail(response); var result = response.data, report = result.preflight || result; sessionId = result.session_id || ''; sessionToken = result.session_token || ''; archiveHash = report.archive_hash || result.archive_hash || ''; planHash = report.plan_hash || result.plan_hash || ''; plan = report.plan || {}; reportStatus = report.status || ''; renderReview(report); warningWrap.prop('hidden', report.status !== 'warning'); overwrite.prop('required', conflict.val() === 'replace'); backup.prop('checked', false); warningAck.prop('checked', false); confirmButton.prop('disabled', report.status === 'blocked'); close(progressModal); disableForm(false); form.find('[name=allow_overwrite]').prop('disabled', conflict.val() !== 'replace'); open(confirmModal); }).fail(function (response) { requestBusy = false; fail(response); });
    }
    if (!form.length || !confirmModal.length || !progressModal.length || !config.ajaxUrl) return;
    conflict.on('change', function () { overwrite.prop('disabled', this.value !== 'replace'); if (this.value === 'replace') overwrite.prop('required', true); else overwrite.prop('checked', false).prop('required', false); });
    form.on('submit', function (event) { event.preventDefault(); if (!busy) preflight(); });
    backup.add(warningAck).on('change', function () { confirmButton.prop('disabled', !backup.prop('checked') || (warningWrap.is(':visible') && !warningAck.prop('checked')) || (conflict.val() === 'replace' && !overwrite.prop('checked'))); });
    $('#bricks-ie-modal-cancel').on('click', function () { if (sessionId) { close(confirmModal); cancel(); } else close(confirmModal); });
    $('#bricks-ie-modal-confirm').on('click', confirmImport); $('#bricks-ie-progress-cancel').on('click', cancel);
    $('#bricks-ie-progress-close').on('click', function () { if (!busy) close(progressModal); });
    confirmModal.on('click', function (event) { if (event.target === this && !requestBusy) { close(confirmModal); if (sessionId) cancel(); } });
    $(document).on('keydown', function (event) { if (event.key === 'Escape') { var help = $('.bricks-ie-help[aria-expanded="true"]'); if (help.length) { event.preventDefault(); setConflictHelp(help.first(), false); return; } } var modal = confirmModal.hasClass(visible) ? confirmModal : progressModal.hasClass(visible) ? progressModal : $(); if (!modal.length) return; if (event.key === 'Escape') { event.preventDefault(); if (requestBusy) return; if (modal.is(progressModal) && !mutationActive) return close(progressModal); if (modal.is(progressModal) && mutationActive) return cancel(); close(modal); if (modal.is(confirmModal) && sessionId) cancel(); } else if (event.key === 'Tab') { var focusable = modal.find('button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible'), first = focusable.first()[0], last = focusable.last()[0]; if (first && last && (event.target === last || event.target === modal[0]) && !event.shiftKey) { event.preventDefault(); $(first).trigger('focus'); } else if (first && last && event.target === first && event.shiftKey) { event.preventDefault(); $(last).trigger('focus'); } } });
    $(window).on('beforeunload', function () { return mutationActive ? t('leaveWarning', 'An import is currently running. Leaving this page may interrupt it.') : undefined; });
}(jQuery));
