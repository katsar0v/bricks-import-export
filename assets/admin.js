(function($) {
	'use strict';

	var config = window.bricksIEImport || {};
	var i18n = config.i18n || {};
	var visibleClass = 'bricks-ie-modal-overlay--visible';
	var importing = false;
	var sessionId = '';

	var $form = $('#bricks-ie-import-form');
	var $confirmModal = $('#bricks-ie-confirm-modal');
	var $progressModal = $('#bricks-ie-progress-modal');
	var $progressMessage = $('#bricks-ie-progress-message');
	var $progressPercent = $('#bricks-ie-progress-percent');
	var $progressBar = $('#bricks-ie-progress-bar');
	var $progressBarWrap = $('.bricks-ie-progress__bar');
	var $progressSteps = $('#bricks-ie-progress-steps');
	var $progressSummary = $('#bricks-ie-progress-summary');
	var $progressError = $('#bricks-ie-progress-error');
	var $progressClose = $('#bricks-ie-progress-close');
	var $progressHeaderIcon = $progressModal.find('.bricks-ie-modal__header .dashicons');

	function text(key, fallback) {
		return i18n[key] || fallback;
	}

	function openModal($modal) {
		$modal.addClass(visibleClass);
	}

	function closeModal($modal) {
		$modal.removeClass(visibleClass);
	}

	function setFormDisabled(disabled) {
		$form.find('input, button, select, textarea').prop('disabled', disabled);
	}

	function renderSteps(steps) {
		if (!steps || !steps.length) {
			return;
		}

		$progressSteps.empty();

		$.each(steps, function(index, step) {
			$('<li/>', {
				'class': 'bricks-ie-progress-step',
				'data-step': step.key,
				'html': '<span class="bricks-ie-progress-step__icon dashicons dashicons-marker" aria-hidden="true"></span><span class="bricks-ie-progress-step__label"></span>'
			}).find('.bricks-ie-progress-step__label').text(step.label).end().appendTo($progressSteps);
		});
	}

	function updateStepStatus(data, failed) {
		var completed = data.completed_steps || [];
		var current = data.current_step || '';

		$progressSteps.find('.bricks-ie-progress-step').each(function() {
			var $step = $(this);
			var $icon = $step.find('.bricks-ie-progress-step__icon');
			var key = $step.data('step');

			$step.removeClass('is-complete is-current is-error');
			$icon.removeClass('dashicons-marker dashicons-update dashicons-yes-alt dashicons-no-alt');

			if ($.inArray(key, completed) !== -1 || data.done) {
				$step.addClass('is-complete');
				$icon.addClass('dashicons-yes-alt');
			} else if (key === current) {
				$step.addClass(failed ? 'is-error' : 'is-current');
				$icon.addClass(failed ? 'dashicons-no-alt' : 'dashicons-update');
			} else {
				$icon.addClass('dashicons-marker');
			}
		});
	}

	function updateProgress(data) {
		var percent = parseInt(data.percent, 10);

		if (isNaN(percent)) {
			percent = 0;
		}

		percent = Math.max(0, Math.min(100, percent));

		if (data.steps) {
			renderSteps(data.steps);
		}

		$progressMessage.text(data.message || '');
		$progressPercent.text(percent + '%');
		$progressBar.css('width', percent + '%');
		$progressBarWrap.attr('aria-valuenow', percent);
		updateStepStatus(data, false);

		if (data.summary) {
			$progressSummary.text(data.summary).prop('hidden', false);
		}
	}

	function getErrorMessage(response) {
		if (response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message) {
			return response.responseJSON.data.message;
		}

		if (response && response.data && response.data.message) {
			return response.data.message;
		}

		return text('ajaxError', 'The import request failed. Please try again.');
	}

	function showError(message, data) {
		importing = false;
		sessionId = '';
		setFormDisabled(false);
		$progressModal.removeClass('is-running is-complete').addClass('is-error');
		$progressHeaderIcon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-no-alt');
		$progressClose.prop('hidden', false);
		$progressMessage.text(text('importFailed', 'Import failed.'));
		$progressError.text(message + ' ' + text('partialChanges', 'Partial changes may already have been applied because imports are not transactional.')).prop('hidden', false);
		updateStepStatus(data || { current_step: '', completed_steps: [] }, true);
	}

	function finishImport(data) {
		importing = false;
		sessionId = '';
		setFormDisabled(false);
		$progressModal.removeClass('is-running is-error').addClass('is-complete');
		$progressHeaderIcon.removeClass('dashicons-update dashicons-no-alt').addClass('dashicons-yes-alt');
		$progressClose.prop('hidden', false);
		$progressMessage.text(text('importComplete', 'Import complete.'));
		updateProgress(data);
	}

	function runNextStep() {
		if (!sessionId) {
			return;
		}

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'bricks_ie_import_step',
				_ajax_nonce: config.nonce,
				session_id: sessionId
			}
		}).done(function(response) {
			if (!response || !response.success) {
				showError(getErrorMessage(response), response ? response.data : null);
				return;
			}

			updateProgress(response.data);

			if (response.data.done) {
				finishImport(response.data);
				return;
			}

			window.setTimeout(runNextStep, 150);
		}).fail(function(response) {
			showError(getErrorMessage(response));
		});
	}

	function startImport() {
		var fileInput = $form.find('input[type="file"]')[0];

		if (fileInput && fileInput.files && !fileInput.files.length) {
			window.alert(text('selectFile', 'Please choose a .zip file to import.'));
			return;
		}

		var formData = new FormData($form[0]);

		if (formData.set) {
			formData.set('action', 'bricks_ie_import_start');
			formData.set('_ajax_nonce', config.nonce);
		} else {
			formData.append('action', 'bricks_ie_import_start');
			formData.append('_ajax_nonce', config.nonce);
		}

		importing = true;
		sessionId = '';
		setFormDisabled(true);
		$progressModal.removeClass('is-complete is-error').addClass('is-running');
		$progressHeaderIcon.removeClass('dashicons-yes-alt dashicons-no-alt').addClass('dashicons-update');
		$progressClose.prop('hidden', true);
		$progressSummary.prop('hidden', true).empty();
		$progressError.prop('hidden', true).empty();
		$progressPercent.text('0%');
		$progressBar.css('width', '0%');
		$progressBarWrap.attr('aria-valuenow', 0);
		$progressSteps.empty();
		$progressMessage.text(text('uploading', 'Uploading and validating archive...'));
		openModal($progressModal);

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: formData,
			processData: false,
			contentType: false
		}).done(function(response) {
			if (!response || !response.success) {
				showError(getErrorMessage(response), response ? response.data : null);
				return;
			}

			sessionId = response.data.session_id || '';
			updateProgress(response.data);
			runNextStep();
		}).fail(function(response) {
			showError(getErrorMessage(response));
		});
	}

	if (!$form.length || !$confirmModal.length || !$progressModal.length || !config.ajaxUrl) {
		return;
	}

	$form.on('submit', function(event) {
		event.preventDefault();

		if (importing) {
			return false;
		}

		openModal($confirmModal);
		return false;
	});

	$('#bricks-ie-modal-cancel').on('click', function() {
		closeModal($confirmModal);
	});

	$('#bricks-ie-modal-confirm').on('click', function() {
		closeModal($confirmModal);
		startImport();
	});

	$confirmModal.on('click', function(event) {
		if (event.target === this) {
			closeModal($confirmModal);
		}
	});

	$progressClose.on('click', function() {
		if (!importing) {
			closeModal($progressModal);
		}
	});

	$(document).on('keydown', function(event) {
		if (event.key === 'Escape' && $confirmModal.hasClass(visibleClass)) {
			closeModal($confirmModal);
		}
	});

	$(window).on('beforeunload', function() {
		if (importing) {
			return text('leaveWarning', 'An import is currently running. Leaving this page may interrupt it.');
		}

		return undefined;
	});
})(jQuery);
