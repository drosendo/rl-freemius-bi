/**
 * @file RL Freemius BI Settings UI Enhancements.
 *
 * Manages dynamic field descriptions, Tippy.js tooltips, checkbox group mass-selection,
 * and automated page reloading upon AJAX settings persistence.
 *
 * @package RL_Freemius_BI
 * @author  David Rosendo
 */

/**
 * @typedef {Object} RlFsbiSettingsData
 * @property {Object<string, string>} [descriptions] Mapping of settings field identifiers to localized helper text.
 */

/**
 * @typedef {Window & { rlFsbiSettingsData?: RlFsbiSettingsData, tippy?: Function }} RlFsbiSettingsWindow
 */

(function($) {
	'use strict';

	/**
	 * Map of field identifiers to explanatory descriptions localized from the backend.
	 *
	 * @type {Object<string, string>}
	 */
	const descriptionsMap = (window.rlFsbiSettingsData && window.rlFsbiSettingsData.descriptions) || {};

	/**
	 * Renders field descriptions beneath form controls and synchronizes tooltip markup.
	 *
	 * Traverses known fields defined in the descriptions map as well as generic fields
	 * decorated with `data-tippy-content` to inject paragraph descriptions and tooltip icons.
	 *
	 * @function renderFieldDescriptions
	 * @returns {void}
	 */
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

	/**
	 * Initializes interactive Tippy.js popover tooltips on settings fields.
	 *
	 * @function initTooltips
	 * @returns {void}
	 */
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

	/**
	 * Counter tracking poll attempts for external Tippy library availability.
	 *
	 * @type {number}
	 */
	let tippyRetries = 0;

	/**
	 * Polls for Tippy.js initialization in case the library script loads asynchronously.
	 *
	 * Attempts up to 20 cycles at 150ms intervals before timing out gracefully.
	 *
	 * @function pollForTippy
	 * @returns {void}
	 */
	function pollForTippy() {
		if (typeof window.tippy === 'function') {
			initTooltips();
		} else if (tippyRetries < 20) {
			tippyRetries++;
			setTimeout(pollForTippy, 150);
		}
	}

	/**
	 * Binds DOM ready operations, mutation observers, tab switching events, and AJAX hooks.
	 *
	 * @listens jQuery:document#ready
	 */
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
			/**
			 * MutationObserver tracking child tree additions/removals within the options framework container.
			 *
			 * @type {MutationObserver}
			 */
			const observer = new MutationObserver(function() {
				renderFieldDescriptions();
			});
			const pageContainer = document.querySelector('.rl-options-page');
			if (pageContainer) {
				observer.observe(pageContainer, { childList: true, subtree: true });
			}
		}

		/**
		 * Handles mass-checking of all checkboxes in a multi-checkbox setting group.
		 *
		 * @listens jQuery:click
		 * @param {JQuery.ClickEvent} e - Click event object.
		 */
		$(document).on('click', '.rl-fsbi-check-all', function(e) {
			e.preventDefault();
			const $wrap = $(this).closest('.rl-fsbi-checkbox-list-wrap');
			$wrap.find('input[type="checkbox"]').prop('checked', true).trigger('change');
		});

		/**
		 * Handles mass-unchecking of all checkboxes in a multi-checkbox setting group.
		 *
		 * @listens jQuery:click
		 * @param {JQuery.ClickEvent} e - Click event object.
		 */
		$(document).on('click', '.rl-fsbi-uncheck-all', function(e) {
			e.preventDefault();
			const $wrap = $(this).closest('.rl-fsbi-checkbox-list-wrap');
			$wrap.find('input[type="checkbox"]').prop('checked', false).trigger('change');
		});

		/**
		 * Listens for successful AJAX settings saves and schedules an automated page reload
		 * so newly discovered plugins, refreshed license counts, and updated tabs appear immediately.
		 *
		 * @listens jQuery:ajaxSuccess
		 * @param {JQuery.TriggeredEvent} event - AJAX event.
		 * @param {jqXHR} xhr - jQuery XMLHttpRequest instance.
		 * @param {JQuery.AjaxSettings} settings - Ajax configuration object.
		 */
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

