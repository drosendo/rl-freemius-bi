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

		$(document).on('click', '#rl-fsbi-clear-kit-sample-log-btn', function(e) {
			e.preventDefault();
			$('#rl-fsbi-kit-sample-log-console').empty();
			$('#rl-fsbi-kit-sample-log-wrap').slideUp(150);
			$(this).hide();
		});
	});
})(jQuery);


