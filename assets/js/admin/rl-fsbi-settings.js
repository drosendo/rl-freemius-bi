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

		/**
		 * Handles live discovery of Mailchimp audiences and tags upon API key input or change.
		 */
		let mcFetchDebounce = null;
		function fetchMailchimpData(apiKey, listId) {
			const ajaxUrl = window.rlFsbiSettingsData && window.rlFsbiSettingsData.ajaxUrl;
			const nonce = window.rlFsbiSettingsData && window.rlFsbiSettingsData.nonce;
			if (!ajaxUrl || !nonce || !apiKey || apiKey.indexOf('-') === -1) {
				return;
			}

			const $keyField = $('.rl-field[data-field-id="rl_fsbi_mailchimp_api_key"]');
			let $statusMsg = $keyField.find('.rl-fsbi-mc-status');
			if (!$statusMsg.length) {
				$keyField.find('.rl-field-control').append('<span class="rl-fsbi-mc-status" style="margin-left:8px; font-size:12px; color:#64748b; display:inline-block; vertical-align:middle;"></span>');
				$statusMsg = $keyField.find('.rl-fsbi-mc-status');
			}
			$statusMsg.html('<span class="spinner is-active" style="float:none; margin:0 4px 0 0; vertical-align:middle; width:16px; height:16px; display:inline-block;"></span> Fetching audiences & tags...');

			$.post(ajaxUrl, {
				action: 'rl_fsbi_fetch_mailchimp_data',
				nonce: nonce,
				api_key: apiKey,
				list_id: listId || ''
			}, function(res) {
				if (res && res.success && res.data) {
					$statusMsg.html('<span style="color:#16a34a; font-weight:600;">✓ Connected to Mailchimp</span>');

					// Populate or transform list select
					const $listField = $('.rl-field[data-field-id="rl_fsbi_mailchimp_list_id"]');
					const lists = res.data.lists || {};
					if ($listField.length && Object.keys(lists).length > 0) {
						const $listControl = $listField.find('select');
						const currentVal = $listControl.length ? $listControl.val() : ($listField.find('input').val() || '');
						const inputName = $listField.find('input, select').attr('name') || 'rl_fsbi_settings[rl_fsbi_mailchimp_list_id]';
						const inputId = $listField.find('input, select').attr('id') || 'rl_fsbi_mailchimp_list_id';

						let selectHtml = '<select id="' + inputId + '" name="' + inputName + '">';
						selectHtml += '<option value="">-- Select Default Audience --</option>';
						for (const [lId, lName] of Object.entries(lists)) {
							const selected = (currentVal === lId || Object.keys(lists).length === 1) ? ' selected="selected"' : '';
							selectHtml += '<option value="' + lId + '"' + selected + '>' + lName + ' (' + lId + ')</option>';
						}
						selectHtml += '</select>';

						if ($listControl.length) {
							$listControl.replaceWith(selectHtml);
						} else {
							$listField.find('input[type="text"]').replaceWith(selectHtml);
						}
					}

					// Populate plan tags if tags were returned
					const tags = res.data.tags || {};
					if (Object.keys(tags).length > 0) {
						$('.rl-field[data-field-id^="rl_fsbi_mc_plan_tag_"]').each(function() {
							const $tagField = $(this);
							const $select = $tagField.find('select');
							const currentVal = $select.length ? $select.val() : ($tagField.find('input').val() || '');
							const fieldName = $tagField.find('input, select').attr('name');
							const fieldId = $tagField.find('input, select').attr('id');

							let tagSelectHtml = '<select id="' + fieldId + '" name="' + fieldName + '">';
							tagSelectHtml += '<option value="">-- Use Default Tag --</option>';
							for (const [tId, tName] of Object.entries(tags)) {
								const selected = (currentVal === tName || currentVal === tId) ? ' selected="selected"' : '';
								tagSelectHtml += '<option value="' + tName + '"' + selected + '>' + tName + '</option>';
							}
							tagSelectHtml += '</select>';

							if ($select.length) {
								$select.replaceWith(tagSelectHtml);
							} else {
								$tagField.find('input[type="text"]').replaceWith(tagSelectHtml);
							}
						});
					}
				} else {
					const errorMsg = (res && res.data) ? res.data : 'Failed to connect to Mailchimp.';
					$statusMsg.html('<span style="color:#dc2626;">⚠ ' + errorMsg + '</span>');
				}
			}).fail(function() {
				$statusMsg.html('<span style="color:#dc2626;">⚠ Network error connecting to Mailchimp.</span>');
			});
		}

		$(document).on('input change blur', '.rl-field[data-field-id="rl_fsbi_mailchimp_api_key"] input', function() {
			const key = $(this).val().trim();
			clearTimeout(mcFetchDebounce);
			if (key.length >= 20 && key.indexOf('-') !== -1) {
				mcFetchDebounce = setTimeout(function() {
					fetchMailchimpData(key);
				}, 500);
			}
		});

		$(document).on('change', '.rl-field[data-field-id="rl_fsbi_mailchimp_list_id"] select', function() {
			const listId = $(this).val();
			const key = $('.rl-field[data-field-id="rl_fsbi_mailchimp_api_key"] input').val().trim();
			if (key && listId) {
				fetchMailchimpData(key, listId);
			}
		});

		/**
		 * Handles execution of the Mailchimp Sample Synchronization Test.
		 */
		$(document).on('click', '#rl-fsbi-run-sample-test-btn', function(e) {
			e.preventDefault();
			const $btn = $(this);
			const $icon = $btn.find('.dashicons');
			const $logWrap = $('#rl-fsbi-sample-log-wrap');
			const $console = $('#rl-fsbi-sample-log-console');
			const $badge = $('#rl-fsbi-sample-summary-badge');
			const $clearBtn = $('#rl-fsbi-clear-sample-log-btn');

			const ajaxUrl = window.rlFsbiSettingsData && window.rlFsbiSettingsData.ajaxUrl;
			const nonce = window.rlFsbiSettingsData && window.rlFsbiSettingsData.nonce;

			if (!ajaxUrl || !nonce) {
				alert('AJAX parameters missing.');
				return;
			}

			const sampleCount = parseInt($('#rl-fsbi-sample-user-count').val(), 10) || 5;
			const pluginId = $('#rl-fsbi-sample-plugin-select').val() || 'all';
			const dryRun = $('#rl-fsbi-sample-dry-run').is(':checked') ? 1 : 0;

			$btn.prop('disabled', true);
			$icon.removeClass('dashicons-controls-play').addClass('dashicons-update rl-fsbi-spinning');

			$logWrap.slideDown(200);
			$console.html('<div style="color:#94a3b8;">[' + (new Date()).toLocaleTimeString() + '] Initializing test synchronization job...</div>');
			$badge.text('Running...').css({ background: '#fef3c7', color: '#b45309' });

			// Call rlLOG at start
			if (window.rlFramework && typeof window.rlFramework.log === 'function') {
				window.rlFramework.log('Starting Mailchimp sample test run', { sampleCount: sampleCount, pluginId: pluginId, dryRun: dryRun });
			} else {
				console.log('[RL Framework DEBUG] Starting Mailchimp sample test run', { sampleCount: sampleCount, pluginId: pluginId, dryRun: dryRun });
			}

			$.post(ajaxUrl, {
				action: 'rl_fsbi_sample_mailchimp_test',
				nonce: nonce,
				sample_count: sampleCount,
				plugin_id: pluginId,
				dry_run: dryRun,
			}, function(res) {
				$btn.prop('disabled', false);
				$icon.removeClass('dashicons-update rl-fsbi-spinning').addClass('dashicons-controls-play');
				$clearBtn.show();

				if (res && res.data && Array.isArray(res.data.logs)) {
					let html = '';
					res.data.logs.forEach(function(item) {
						let color = '#94a3b8'; // debug
						if (item.level === 'error') {
							color = '#ef4444';
						} else if (item.level === 'warn') {
							color = '#f59e0b';
						} else if (item.level === 'info') {
							color = '#38bdf8';
						}

						// Call rlLOG for each step as requested
						if (window.rlFramework && typeof window.rlFramework.log === 'function') {
							if (item.level === 'error') {
								window.rlFramework.error(item.message, item.context || {});
							} else if (item.level === 'warn') {
								window.rlFramework.warn(item.message, item.context || {});
							} else if (item.level === 'info') {
								window.rlFramework.info(item.message, item.context || {});
							} else {
								window.rlFramework.log(item.message, item.context || {});
							}
						} else {
							console.log('[RL Framework ' + (item.level || 'DEBUG').toUpperCase() + '] ' + item.message);
						}

						html += '<div style="color:' + color + '; margin-bottom:2px;">';
						html += '<span style="color:#64748b; margin-right:6px;">[' + (item.time || '') + ']</span>';
						html += $('<div>').text(item.message).html();
						html += '</div>';
					});

					$console.html(html);
					$console.scrollTop($console[0].scrollHeight);

					if (res.success && res.data.summary) {
						const s = res.data.summary;
						$badge.text('Scanned: ' + s.scanned + ' | Opt-ins: ' + s.optins + ' | Synced: ' + s.synced + ' | Errors: ' + s.errors)
							  .css({ background: s.errors > 0 ? '#fee2e2' : '#dcfce7', color: s.errors > 0 ? '#991b1b' : '#166534' });
					} else {
						$badge.text('Failed').css({ background: '#fee2e2', color: '#991b1b' });
					}
				} else {
					const msg = (res && res.data && res.data.message) ? res.data.message : 'Unexpected test response.';
					$console.append('<div style="color:#ef4444;">[ERROR] ' + $('<div>').text(msg).html() + '</div>');
					$badge.text('Error').css({ background: '#fee2e2', color: '#991b1b' });
				}
			}).fail(function(xhr, status, error) {
				$btn.prop('disabled', false);
				$icon.removeClass('dashicons-update rl-fsbi-spinning').addClass('dashicons-controls-play');
				$clearBtn.show();
				$console.append('<div style="color:#ef4444;">[NETWORK ERROR] ' + $('<div>').text(error || status).html() + '</div>');
				$badge.text('Network Error').css({ background: '#fee2e2', color: '#991b1b' });
			});
		});

		$(document).on('click', '#rl-fsbi-clear-sample-log-btn', function(e) {
			e.preventDefault();
			$('#rl-fsbi-sample-log-console').empty();
			$('#rl-fsbi-sample-log-wrap').slideUp(150);
			$(this).hide();
		});

		/**
		 * Kit.com Dynamic Auto-Discovery of Tags.
		 */
		let kitFetchDebounce = null;
		function fetchKitData(key, secret) {
			const ajaxUrl = window.rlFsbiSettingsData && window.rlFsbiSettingsData.ajaxUrl;
			const nonce = window.rlFsbiSettingsData && window.rlFsbiSettingsData.nonce;
			if (!ajaxUrl || !nonce || !key) {
				return;
			}

			const $keyField = $('.rl-field[data-field-id="rl_fsbi_kit_api_key"]');
			let $statusMsg = $keyField.find('.rl-fsbi-kit-status-msg');
			if (!$statusMsg.length) {
				$keyField.find('.rl-field-control').append('<div class="rl-fsbi-kit-status-msg" style="margin-top:6px; font-size:12px;"></div>');
				$statusMsg = $keyField.find('.rl-fsbi-kit-status-msg');
			}
			$statusMsg.html('<span style="color:#0284c7;">⟳ Checking Kit.com tags...</span>');

			$.post(ajaxUrl, {
				action: 'rl_fsbi_fetch_kit_data',
				nonce: nonce,
				api_key: key,
				api_secret: secret || ''
			}, function(res) {
				if (res && res.success && res.data) {
					const tags = res.data.tags || {};
					const tagCount = Object.keys(tags).length;
					$statusMsg.html('<span style="color:#16a34a;">✓ Connected to Kit.com (' + tagCount + ' tags discovered)</span>');

					if (tagCount > 0) {
						// Populate default tag selector
						const $defaultTagField = $('.rl-field[data-field-id="rl_fsbi_kit_default_tag"]');
						if ($defaultTagField.length) {
							const currentDefVal = $defaultTagField.find('select').length ? $defaultTagField.find('select').val() : ($defaultTagField.find('input').val() || '');
							const inputName = $defaultTagField.find('input, select').attr('name') || 'rl_fsbi_settings[rl_fsbi_kit_default_tag]';
							const inputId = $defaultTagField.find('input, select').attr('id') || 'rl_fsbi_kit_default_tag';

							let defHtml = '<select id="' + inputId + '" name="' + inputName + '">';
							defHtml += '<option value="">-- Select Default Tag --</option>';
							for (const [tId, tName] of Object.entries(tags)) {
								const selected = (currentDefVal === tId || currentDefVal === tName) ? ' selected="selected"' : '';
								defHtml += '<option value="' + tId + '"' + selected + '>' + tName + ' (#' + tId + ')</option>';
							}
							defHtml += '</select>';

							if ($defaultTagField.find('select').length) {
								$defaultTagField.find('select').replaceWith(defHtml);
							} else {
								$defaultTagField.find('input[type="text"]').replaceWith(defHtml);
							}
						}

						// Populate plan tags
						$('.rl-field[data-field-id^="rl_fsbi_kit_plan_tag_"]').each(function() {
							const $tagField = $(this);
							const $select = $tagField.find('select');
							const currentVal = $select.length ? $select.val() : ($tagField.find('input').val() || '');
							const fieldName = $tagField.find('input, select').attr('name');
							const fieldId = $tagField.find('input, select').attr('id');

							let tagSelectHtml = '<select id="' + fieldId + '" name="' + fieldName + '">';
							tagSelectHtml += '<option value="">-- Use Default Tag --</option>';
							for (const [tId, tName] of Object.entries(tags)) {
								const selected = (currentVal === tId || currentVal === tName) ? ' selected="selected"' : '';
								tagSelectHtml += '<option value="' + tId + '"' + selected + '>' + tName + ' (#' + tId + ')</option>';
							}
							tagSelectHtml += '</select>';

							if ($select.length) {
								$select.replaceWith(tagSelectHtml);
							} else {
								$tagField.find('input[type="text"]').replaceWith(tagSelectHtml);
							}
						});
					}
				} else {
					const errorMsg = (res && res.data) ? res.data : 'Failed to connect to Kit.com.';
					$statusMsg.html('<span style="color:#dc2626;">⚠ ' + errorMsg + '</span>');
				}
			}).fail(function() {
				$statusMsg.html('<span style="color:#dc2626;">⚠ Network error connecting to Kit.com.</span>');
			});
		}

		$(document).on('input change blur', '.rl-field[data-field-id="rl_fsbi_kit_api_key"] input, .rl-field[data-field-id="rl_fsbi_kit_api_secret"] input', function() {
			const $keyInput = $('.rl-field[data-field-id="rl_fsbi_kit_api_key"] input');
			if (!$keyInput.length) return;
			const key = $keyInput.val().trim();
			const $secretInput = $('.rl-field[data-field-id="rl_fsbi_kit_api_secret"] input');
			const secret = $secretInput.length ? $secretInput.val().trim() : '';
			clearTimeout(kitFetchDebounce);
			if (key.length >= 8) {
				kitFetchDebounce = setTimeout(function() {
					fetchKitData(key, secret);
				}, 400);
			}
		});

		/**
		 * Handles execution of the Kit.com Sample Synchronization Test.
		 */
		$(document).on('click', '#rl-fsbi-run-kit-sample-test-btn', function(e) {
			e.preventDefault();
			const $btn = $(this);
			const $icon = $btn.find('.dashicons');
			const $logWrap = $('#rl-fsbi-kit-sample-log-wrap');
			const $console = $('#rl-fsbi-kit-sample-log-console');
			const $badge = $('#rl-fsbi-kit-sample-summary-badge');
			const $clearBtn = $('#rl-fsbi-clear-kit-sample-log-btn');

			const ajaxUrl = window.rlFsbiSettingsData && window.rlFsbiSettingsData.ajaxUrl;
			const nonce = window.rlFsbiSettingsData && window.rlFsbiSettingsData.nonce;

			if (!ajaxUrl || !nonce) {
				alert('AJAX parameters missing.');
				return;
			}

			const sampleCount = parseInt($('#rl-fsbi-kit-sample-user-count').val(), 10) || 5;
			const pluginId = $('#rl-fsbi-kit-sample-plugin-select').val() || 'all';
			const dryRun = $('#rl-fsbi-kit-sample-dry-run').is(':checked') ? 1 : 0;

			$btn.prop('disabled', true);
			$icon.removeClass('dashicons-controls-play').addClass('dashicons-update rl-fsbi-spinning');

			$logWrap.slideDown(200);
			$console.html('<div style="color:#94a3b8;">[' + (new Date()).toLocaleTimeString() + '] Initializing Kit.com test synchronization job...</div>');
			$badge.text('Running...').css({ background: '#fef3c7', color: '#b45309' });

			if (window.rlFramework && typeof window.rlFramework.log === 'function') {
				window.rlFramework.log('Starting Kit.com sample test run', { sampleCount: sampleCount, pluginId: pluginId, dryRun: dryRun });
			} else {
				console.log('[RL Framework DEBUG] Starting Kit.com sample test run', { sampleCount: sampleCount, pluginId: pluginId, dryRun: dryRun });
			}

			$.post(ajaxUrl, {
				action: 'rl_fsbi_sample_kit_test',
				nonce: nonce,
				sample_count: sampleCount,
				plugin_id: pluginId,
				dry_run: dryRun,
			}, function(res) {
				$btn.prop('disabled', false);
				$icon.removeClass('dashicons-update rl-fsbi-spinning').addClass('dashicons-controls-play');
				$clearBtn.show();

				if (res && res.data && Array.isArray(res.data.logs)) {
					let html = '';
					res.data.logs.forEach(function(item) {
						let color = '#94a3b8';
						if (item.level === 'error') {
							color = '#ef4444';
						} else if (item.level === 'warn') {
							color = '#f59e0b';
						} else if (item.level === 'info') {
							color = '#38bdf8';
						}

						if (window.rlFramework && typeof window.rlFramework.log === 'function') {
							if (item.level === 'error') {
								window.rlFramework.error(item.message, item.context || {});
							} else if (item.level === 'warn') {
								window.rlFramework.warn(item.message, item.context || {});
							} else if (item.level === 'info') {
								window.rlFramework.info(item.message, item.context || {});
							} else {
								window.rlFramework.log(item.message, item.context || {});
							}
						} else {
							console.log('[RL Framework ' + (item.level || 'DEBUG').toUpperCase() + '] ' + item.message);
						}

						html += '<div style="color:' + color + '; margin-bottom:2px;">';
						html += '<span style="color:#64748b; margin-right:6px;">[' + (item.time || '') + ']</span>';
						html += $('<div>').text(item.message).html();
						html += '</div>';
					});

					$console.html(html);
					$console.scrollTop($console[0].scrollHeight);

					if (res.success && res.data.summary) {
						const s = res.data.summary;
						$badge.text('Scanned: ' + s.scanned + ' | Opt-ins: ' + s.optins + ' | Synced: ' + s.synced + ' | Errors: ' + s.errors)
							  .css({ background: s.errors > 0 ? '#fee2e2' : '#dcfce7', color: s.errors > 0 ? '#991b1b' : '#166534' });
					} else {
						$badge.text('Failed').css({ background: '#fee2e2', color: '#991b1b' });
					}
				} else {
					const msg = (res && res.data && res.data.message) ? res.data.message : 'Unexpected test response.';
					$console.append('<div style="color:#ef4444;">[ERROR] ' + $('<div>').text(msg).html() + '</div>');
					$badge.text('Error').css({ background: '#fee2e2', color: '#991b1b' });
				}
			}).fail(function(xhr, status, error) {
				$btn.prop('disabled', false);
				$icon.removeClass('dashicons-update rl-fsbi-spinning').addClass('dashicons-controls-play');
				$clearBtn.show();
				$console.append('<div style="color:#ef4444;">[NETWORK ERROR] ' + $('<div>').text(error || status).html() + '</div>');
				$badge.text('Network Error').css({ background: '#fee2e2', color: '#991b1b' });
			});
		});

		/**
		 * Kit subscriber quota panel: preview and prune the oldest tagged subscribers.
		 *
		 * Pruning unsubscribes real people, so it always requires an explicit
		 * confirmation and only ever runs one bounded batch per click.
		 */
		/**
		 * Kit subscriber quota panel: preview and prune the oldest tagged subscribers.
		 *
		 * Pruning unsubscribes real people, so it always requires an explicit
		 * confirmation and only ever runs bounded batches per click.
		 */
		const quotaData = (window.rlFsbiSettingsData && window.rlFsbiSettingsData) || {};
		const quotaState = { total: 0, excess: 0, candidates: 0, candidateIds: [], scope: 'all', drainRunning: false, drainStopRequested: false };

		function quotaRequest(action, payload) {
			return $.post(quotaData.ajaxUrl, Object.assign({
				action: action,
				nonce: quotaData.nonce
			}, payload || {}));
		}

		/**
		 * Promise wrapper so the drain loop can await a jQuery request.
		 */
		function quotaPost(action, payload) {
			return new Promise(function(resolve, reject) {
				quotaRequest(action, payload).done(resolve).fail(reject);
			});
		}

		/**
		 * Confirm dialog that works on the framework's SweetAlert2 v9
		 * ({value}) and on v10+ ({isConfirmed}).
		 */
		function quotaConfirm(title, text) {
			if (window.Swal && typeof window.Swal.fire === 'function') {
				return window.Swal.fire({
					icon: 'warning',
					title: title,
					text: text,
					showCancelButton: true,
					confirmButtonText: 'Yes, unsubscribe',
					cancelButtonText: 'Cancel',
					confirmButtonColor: '#d63638',
					reverseButtons: true,
					focusCancel: true
				}).then(function(result) {
					return !!result && (result.isConfirmed === true || result.value === true);
				});
			}

			return Promise.resolve(window.confirm(title + '\n\n' + text));
		}

		function quotaScopeWarning() {
			return quotaState.scope === 'tagged'
				? 'Kit treats this as permanent: each person stops receiving all email and must opt in again explicitly. Only subscribers carrying this plugin\'s Kit tags are removed.'
				: 'Kit treats this as permanent: each person stops receiving all email and must opt in again explicitly. Any active subscriber is eligible, oldest first.';
		}

		/**
		 * Paint the panel from a preview payload.
		 */
		function quotaApplyPreview(data) {
			quotaState.total = Number(data.total || 0);
			quotaState.excess = Number(data.excess || 0);
			quotaState.candidates = Number(data.candidate_count || 0);
			quotaState.scope = data.scope || 'all';
			quotaState.candidateIds = (data.candidates || [])
				.map(function(candidate) { return Number(candidate.id); })
				.filter(function(id) { return id > 0; });

			$('#rl-fsbi-kit-quota-total').text(quotaState.total.toLocaleString());
			$('#rl-fsbi-kit-quota-excess').text(quotaState.excess.toLocaleString());
			$('#rl-fsbi-kit-quota-candidates').text(quotaState.candidates.toLocaleString());
			$('#rl-fsbi-kit-quota-limit').text(Number(data.limit || 0) > 0 ? Number(data.limit).toLocaleString() : 'Unlimited');

			quotaMessage(data.message || '');
			quotaRenderCandidates(data.candidates);
			$('#rl-fsbi-kit-quota-warning').prop('hidden', quotaState.excess <= 0);

			const idle = !quotaState.drainRunning;
			$('#rl-fsbi-kit-quota-prune').prop('disabled', !idle || quotaState.candidates <= 0 || quotaState.excess <= 0);
			$('#rl-fsbi-kit-quota-drain').prop('disabled', !idle || quotaState.excess <= 0);
			$('#rl-fsbi-kit-quota-preview').prop('disabled', !idle);

			return data;
		}

		/**
		 * Re-read the quota and the next N candidates from Kit.
		 *
		 * @param {number} [previewSize] Candidates to fetch (server caps at 500).
		 */
		function quotaLoadCandidates(previewSize) {
			return quotaPost('rl_fsbi_kit_prune_preview', previewSize ? { preview_size: previewSize } : null)
				.then(function(res) {
					if (!res || !res.success) {
						throw new Error(typeof res.data === 'string' ? res.data : 'Preview failed.');
					}

					return quotaApplyPreview(res.data || {});
				});
		}

		function quotaSpinner(active) {
			$('.rl-fsbi-kit-quota-spinner').toggleClass('is-active', !!active);
		}

		function quotaMessage(text, isError) {
			$('#rl-fsbi-kit-quota-output')
				.text(text)
				.css('color', isError ? '#b32d2e' : '#1d2327');
		}

		function quotaProgress(percent, text) {
			const $wrap = $('#rl-fsbi-kit-quota-progress');
			if (!$wrap.length) { return; }
			$wrap.prop('hidden', false);
			$('#rl-fsbi-kit-quota-progress-fill').css('width', Math.max(0, Math.min(100, percent)) + '%');
			if (typeof text === 'string') {
				$('#rl-fsbi-kit-quota-progress-text').text(text);
			}
		}

		function quotaProgressReset() {
			$('#rl-fsbi-kit-quota-progress').prop('hidden', true);
			$('#rl-fsbi-kit-quota-progress-fill').css('width', '0%');
			$('#rl-fsbi-kit-quota-progress-text').text('');
		}

		function quotaRenderCandidates(candidates) {
			const $list = $('#rl-fsbi-kit-quota-list').empty();
			(candidates || []).forEach(function(candidate) {
				const when = candidate.created_at ? new Date(candidate.created_at).toLocaleDateString() : '';
				$('<li>').text(candidate.email + (when ? ' — joined ' + when : '')).appendTo($list);
			});
		}

		function quotaNotify(options) {
			if (window.Swal && typeof window.Swal.fire === 'function') {
				return window.Swal.fire(options);
			}
			window.alert((options.title || '') + (options.text ? '\n\n' + options.text : ''));
			return null;
		}

		/**
		 * Populate the quota panel on page load so it never shows empty dashes.
		 * Read-only: the preview endpoint never changes subscriber state.
		 */
		$(function() {
			if (!$('.rl-fsbi-kit-quota').length) { return; }

			if (!quotaData.kitQuotaEnabled) {
				// No key at render time: say so instead of leaving silent dashes.
				quotaMessage('Save a Kit API key above, then reload this page to load your quota.', false);
				$('#rl-fsbi-kit-quota-prune').prop('disabled', true);
				return;
			}

			$('#rl-fsbi-kit-quota-preview').trigger('click');
		});

		$(document).on('click', '#rl-fsbi-kit-quota-preview', function(e) {
			e.preventDefault();

			const $btn = $(this);

			quotaSpinner(true);
			$btn.prop('disabled', true);

			quotaLoadCandidates()
				.catch(function(err) {
					quotaMessage((err && err.message) || 'Preview failed: network error.', true);
				})
				.then(function() {
					quotaSpinner(false);
					$btn.prop('disabled', quotaState.drainRunning);
				});
		});

		$(document).on('click', '#rl-fsbi-kit-quota-prune', function(e) {
			e.preventDefault();

			const target = Math.min(25, quotaState.excess, quotaState.candidateIds.length);

			if (target <= 0) {
				quotaMessage('Nothing to remove — use "Refresh from Kit" to load the current candidates.', false);
				return;
			}

			quotaConfirm('Unsubscribe ' + target + ' subscriber' + (target === 1 ? '' : 's') + '?', quotaScopeWarning())
				.then(function(confirmed) {
					if (confirmed) { runPruneBatch(target); }
				});
		});

		$(document).on('click', '#rl-fsbi-kit-quota-drain', function(e) {
			e.preventDefault();

			const goal = quotaState.excess;

			if (goal <= 0) {
				quotaMessage('You are within the limit — nothing to remove.', false);
				return;
			}

			quotaConfirm(
				'Unsubscribe all ' + goal.toLocaleString() + ' over the limit?',
				quotaScopeWarning() + ' This runs continuously until you are back under the limit. Keep this tab open and use Stop to interrupt.'
			).then(function(confirmed) {
				if (confirmed) { runDrain(); }
			});
		});

		$(document).on('click', '#rl-fsbi-kit-quota-stop', function(e) {
			e.preventDefault();
			quotaState.drainRunning = false;
			quotaState.drainStopRequested = true;
			$(this).prop('disabled', true);
			quotaMessage('Stopping after the current chunk…', false);
		});

		/**
		 * Remove everything above the limit, one server-safe chunk at a time.
		 *
		 * The loop is client-driven so every request stays short and the tab
		 * shows live progress. Stop takes effect between chunks, never mid-request.
		 */
		function runDrain() {
			const $drain   = $('#rl-fsbi-kit-quota-drain');
			const $prune   = $('#rl-fsbi-kit-quota-prune');
			const $refresh = $('#rl-fsbi-kit-quota-preview');
			const $stop    = $('#rl-fsbi-kit-quota-stop');

			const goal = Math.max(0, quotaState.excess);
			const CHUNK = 25;
			const MAX_REQUESTS = 400;

			let removed = 0;
			let failed = 0;
			let requests = 0;

			quotaState.drainRunning = true;

			$drain.prop('disabled', true);
			$prune.prop('disabled', true);
			$refresh.prop('disabled', true);
			$stop.prop('hidden', false).prop('disabled', false);
			quotaSpinner(true);
			quotaProgress(0, 'Loading candidates from Kit…');

			const paint = function(isFinal) {
				const done = removed + failed;
				quotaProgress(
					goal > 0 ? Math.min(100, Math.round((done / goal) * 100)) : 100,
					'Removed ' + removed.toLocaleString() + ' of ' + goal.toLocaleString()
						+ (failed ? ' (' + failed + ' failed)' : '') + (isFinal ? '.' : '…')
				);
			};

			const finish = function(reason) {
				const stopped = !!reason || quotaState.drainStopRequested === true;

				quotaState.drainRunning = false;
				quotaState.drainStopRequested = false;
				quotaSpinner(false);
				$stop.prop('hidden', true);
				$drain.prop('disabled', quotaState.excess <= 0);
				$prune.prop('disabled', quotaState.excess <= 0);
				$refresh.prop('disabled', false);

				paint(true);

				if (reason) { quotaMessage(reason, true); }

				quotaNotify({
					icon: failed ? 'warning' : (stopped ? 'info' : 'success'),
					title: stopped ? 'Stopped' : 'Done',
					text: 'Removed ' + removed.toLocaleString() + ' subscriber' + (removed === 1 ? '' : 's') + '. '
						+ (quotaState.excess > 0
							? quotaState.excess.toLocaleString() + ' still over the limit.'
							: 'You are now within the limit.'),
					confirmButtonText: 'OK'
				});

				setTimeout(function() {
					if (quotaData.kitQuotaEnabled) { $('#rl-fsbi-kit-quota-preview').trigger('click'); }
				}, 600);
			};

			const nextChunk = function() {
				if (!quotaState.drainRunning || quotaState.excess <= 0) {
					finish('');
					return;
				}

				if (requests >= MAX_REQUESTS) {
					finish('Stopped after ' + MAX_REQUESTS + ' requests — click again to continue.');
					return;
				}

				const ready = quotaState.candidateIds.length
					? Promise.resolve()
					: quotaLoadCandidates(500);

				ready.then(function() {
					if (!quotaState.candidateIds.length) {
						finish('Kit returned no more eligible subscribers.');
						return;
					}

					const chunk = quotaState.candidateIds.splice(0, Math.min(CHUNK, Math.max(1, quotaState.excess)));
					requests++;

					return quotaPost('rl_fsbi_kit_prune_batch', {
						confirm: 'yes',
						batch_size: chunk.length,
						subscriber_ids: JSON.stringify(chunk)
					}).then(function(res) {
						if (!res || !res.success) {
							throw new Error(typeof res.data === 'string' ? res.data : 'Prune failed.');
						}

						const data = res.data || {};
						const chunkRemoved = Number(data.removed || 0);
						const chunkFailed = Number(data.failed || 0);

						removed += chunkRemoved;
						failed += chunkFailed;

						quotaState.total = Number(data.total || quotaState.total);
						quotaState.excess = Number(data.excess || 0);
						$('#rl-fsbi-kit-quota-total').text(quotaState.total.toLocaleString());
						$('#rl-fsbi-kit-quota-excess').text(quotaState.excess.toLocaleString());

						paint();

						// A chunk that changes nothing means we are stuck rather than done.
						if (0 === chunkRemoved && 0 === chunkFailed) {
							finish('Kit stopped accepting removals.');
							return;
						}

						nextChunk();
					});
				}).catch(function(err) {
					finish((err && err.message) || 'Request failed.');
				});
			};

			// Pull a large candidate list up front so the loop only refetches
			// once per 500 removals instead of once per chunk.
			if (goal > CHUNK) {
				quotaLoadCandidates(500)
					.then(function() { nextChunk(); })
					.catch(function(err) {
						finish((err && err.message) || 'Could not load candidates.');
					});
			} else {
				nextChunk();
			}
		}

		/**
		 * Remove one batch in small chunks so the progress bar reflects real work.
		 *
		 * Each chunk is a separate request; the server caps the size regardless.
		 *
		 * @param {number} target How many to remove for this click.
		 */
		function runPruneBatch(target) {
			const CHUNK = 5;
			const $prune = $('#rl-fsbi-kit-quota-prune');
			const ids = quotaState.candidateIds.slice(0, target);
			let index = 0;
			let removed = 0;
			let failed = 0;

			quotaSpinner(true);
			$prune.prop('disabled', true);
			$('#rl-fsbi-kit-quota-preview').prop('disabled', true);
			quotaProgress(0, 'Starting…');

			const finish = function(isError) {
				quotaSpinner(false);
				$('#rl-fsbi-kit-quota-preview').prop('disabled', false);

				const doneCount = removed + failed;
				quotaProgress(target > 0 ? Math.round((doneCount / target) * 100) : 100,
					'Removed ' + removed + ' of ' + target + (failed ? ' (' + failed + ' failed)' : '') + '.');

				if (!isError) {
					quotaNotify({
						icon: failed ? 'warning' : 'success',
						title: failed ? 'Partially completed' : 'Done',
						text: 'Removed ' + removed + ' subscriber' + (removed === 1 ? '' : 's') + '. '
							+ (quotaState.excess > 0
								? quotaState.excess.toLocaleString() + ' still over the limit.'
								: 'You are now within the limit.'),
						confirmButtonText: 'OK'
					});
				}

				$prune.prop('disabled', quotaState.candidates <= 0 || quotaState.excess <= 0);

				// Refresh the candidate list so the next preview is accurate.
				setTimeout(function() {
					if (quotaData.kitQuotaEnabled) {
						$('#rl-fsbi-kit-quota-preview').trigger('click');
					}
				}, 400);
			};

			const step = function() {
				if (index >= ids.length) { finish(false); return; }

				const chunk = ids.slice(index, index + CHUNK);
				index += chunk.length;

				// Send the exact previewed IDs: asking the server for "the oldest N"
				// again re-selects the same people while Kit's active list catches up.
				quotaRequest('rl_fsbi_kit_prune_batch', {
					confirm: 'yes',
					batch_size: chunk.length,
					subscriber_ids: JSON.stringify(chunk)
				})
					.done(function(res) {
						if (!res || !res.success) {
							const msg = (res && res.data) ? res.data : 'Prune failed.';
							quotaMessage(typeof msg === 'string' ? msg : 'Prune failed.', true);
							finish(true);
							return;
						}

						const data = res.data || {};

						removed += Number(data.removed || 0);
						failed += Number(data.failed || 0);

						quotaState.total = Number(data.total || quotaState.total);
						quotaState.excess = Number(data.excess || 0);

						$('#rl-fsbi-kit-quota-total').text(quotaState.total.toLocaleString());
						$('#rl-fsbi-kit-quota-excess').text(quotaState.excess.toLocaleString());

						const doneCount = removed + failed;
						quotaProgress(
							target > 0 ? Math.round((doneCount / target) * 100) : 100,
							'Removing ' + doneCount + ' of ' + target + '…'
						);

						if (data.done || index >= ids.length || quotaState.excess <= 0) { finish(false); return; }
						step();
					})
					.fail(function() {
						quotaMessage('Prune failed: network error.', true);
						finish(true);
					});
			};

			step();
		}

		$(document).on('click', '#rl-fsbi-clear-kit-sample-log-btn', function(e) {
			e.preventDefault();
			$('#rl-fsbi-kit-sample-log-console').empty();
			$('#rl-fsbi-kit-sample-log-wrap').slideUp(150);
			$(this).hide();
		});
	});
})(jQuery);



/**
 * Stop browsers autofilling the Freemius credential fields.
 *
 * The Developer ID is a bare text input, so autofill happily drops an email
 * into it. Server-side validation now refuses that, but the field should not
 * invite the mistake in the first place.
 */
(function () {
	'use strict';

	function credentialField(name) {
		return document.getElementById('rl_options_' + name) ||
			document.querySelector('[name="rl_options[' + name + ']"]') ||
			document.getElementById(name);
	}

	function hardenCredentialFields() {
		var developerId = credentialField('rl_fsbi_developer_id');

		if (developerId) {
			developerId.setAttribute('autocomplete', 'off');
			developerId.setAttribute('inputmode', 'numeric');
			developerId.setAttribute('pattern', '[0-9]*');
			developerId.setAttribute('spellcheck', 'false');
		}

		['rl_fsbi_public_key', 'rl_fsbi_secret_key'].forEach(function (name) {
			var el = credentialField(name);

			if (el) {
				el.setAttribute('autocomplete', 'new-password');
				el.setAttribute('spellcheck', 'false');
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', hardenCredentialFields);
	} else {
		hardenCredentialFields();
	}
})();
