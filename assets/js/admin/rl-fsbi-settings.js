/**
 * RL Freemius BI Settings UI Enhancements
 */
(function($) {
	'use strict';

	const descriptionsMap = (window.rlFsbiSettingsData && window.rlFsbiSettingsData.descriptions) || {};

	function renderFieldDescriptions() {
		// 1. Process fields known in descriptionsMap
		for (const [fieldId, descText] of Object.entries(descriptionsMap)) {
			if (!descText) {
				continue;
			}
			const $field = $('.rl-field[data-field-id="' + fieldId + '"]');
			if (!$field.length) {
				continue;
			}

			const $control = $field.find('.rl-field-control');
			if ($control.length && !$control.find('.rl-fsbi-field-desc').length) {
				$control.append('<p class="description rl-fsbi-field-desc">' + descText + '</p>');
			}

			// Ensure tooltip exists and has content
			let $tooltip = $field.find('.rl-field-tooltip');
			if (!$tooltip.length) {
				const $label = $field.find('.rl-field-label');
				if ($label.length) {
					$label.append(' <span class="rl-field-tooltip" data-tippy-content="' + $('<div/>').text(descText).html() + '"><span class="dashicons dashicons-info"></span></span>');
				}
			} else if (!$tooltip.attr('data-tippy-content')) {
				$tooltip.attr('data-tippy-content', descText);
			}
		}

		// 2. Generic fallback for any other fields with data-tippy-content
		$('.rl-field').each(function() {
			const $field = $(this);
			const $control = $field.find('.rl-field-control');
			const $tooltip = $field.find('.rl-field-tooltip');

			if ($control.length && $tooltip.length && !$control.find('.rl-fsbi-field-desc').length) {
				const desc = $tooltip.attr('data-tippy-content');
				if (desc && desc.trim()) {
					$control.append('<p class="description rl-fsbi-field-desc">' + desc + '</p>');
				}
			}
		});

		initTooltips();
	}

	function initTooltips() {
		if (typeof window.tippy === 'function') {
			try {
				window.tippy('.rl-options-page .rl-field-tooltip[data-tippy-content]', {
					allowHTML: true,
					theme: 'light-border',
					placement: 'right',
					maxWidth: 360,
					arrow: true,
					interactive: true,
					zIndex: 99999
				});
			} catch (e) {
				// Silent catch
			}
		}
	}

	// Retry tooltip initialization in case Tippy.js loads asynchronously
	let tippyRetries = 0;
	function pollForTippy() {
		if (typeof window.tippy === 'function') {
			initTooltips();
		} else if (tippyRetries < 20) {
			tippyRetries++;
			setTimeout(pollForTippy, 150);
		}
	}

	$(document).ready(function() {
		// Render visible descriptions under each field control immediately
		renderFieldDescriptions();
		pollForTippy();

		// Re-run whenever tab navigation switches
		$(document).on('click', '.rl-nav-tab, .rl-tab-link, .rl-sidebar-link, .rl-settings-tab', function() {
			setTimeout(renderFieldDescriptions, 60);
			setTimeout(renderFieldDescriptions, 200);
		});

		// Observe DOM changes (e.g. conditional fields showing/hiding)
		if (window.MutationObserver) {
			const observer = new MutationObserver(function() {
				renderFieldDescriptions();
			});
			const pageContainer = document.querySelector('.rl-options-page');
			if (pageContainer) {
				observer.observe(pageContainer, { childList: true, subtree: true });
			}
		}

		// Handle Check All / Uncheck All in Checkbox List
		$(document).on('click', '.rl-fsbi-check-all', function(e) {
			e.preventDefault();
			const $wrap = $(this).closest('.rl-fsbi-checkbox-list-wrap');
			$wrap.find('input[type="checkbox"]').prop('checked', true).trigger('change');
		});

		$(document).on('click', '.rl-fsbi-uncheck-all', function(e) {
			e.preventDefault();
			const $wrap = $(this).closest('.rl-fsbi-checkbox-list-wrap');
			$wrap.find('input[type="checkbox"]').prop('checked', false).trigger('change');
		});

		// Auto-reload after settings are saved so newly discovered plugins and unlocked tabs appear immediately
		$(document).ajaxSuccess(function(event, xhr, settings) {
			if (settings && settings.data && typeof settings.data === 'string' && settings.data.indexOf('action=rl_fsbi_save_settings') !== -1) {
				try {
					const res = typeof xhr.responseJSON !== 'undefined' ? xhr.responseJSON : JSON.parse(xhr.responseText);
					if (res && res.success) {
						// Listen to SweetAlert confirm button click
						$(document).one('click', '.swal2-confirm', function() {
							setTimeout(function() {
								window.location.reload();
							}, 150);
						});

						// Fallback reload timer if user does not click or SweetAlert is bypassed
						setTimeout(function() {
							window.location.reload();
						}, 1400);
					}
				} catch (e) {
					// Silent catch
				}
			}
		});
	});
})(jQuery);
