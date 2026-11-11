/**
 * RL Freemius BI Admin Dashboard JavaScript
 *
 * Handles client-side initialization, AJAX data loading, interactive filtering,
 * Chart.js visualization rendering, DataTables integration, CSV exports,
 * and batched Freemius API synchronization.
 *
 * @package    RL_Freemius_BI
 * @subpackage RL_Freemius_BI/assets/js/admin
 */

(function() {
	'use strict';

	/**
	 * @typedef {Object} RlFsbiAdminConfig
	 * @property {string} ajaxUrl - WordPress admin AJAX endpoint URL.
	 * @property {string} nonce - Security nonce for AJAX verification.
	 * @property {string} localeFormat - BCP 47 locale format tag (e.g. 'en-US').
	 * @property {string} defaultCurrency - Default fallback currency code (e.g. 'USD').
	 * @property {number|string} tableRows - Default rows per page for DataTables.
	 * @property {number|string} forcedPluginId - Forced plugin ID scope if viewing a single plugin view.
	 * @property {Object.<string, string>} pluginsCatalog - Map of plugin IDs to display names.
	 */

	/**
	 * @typedef {Object} DashboardFilters
	 * @property {string} plugin_id - Selected plugin ID or 'all'.
	 * @property {string} currency - Selected currency filter code or 'all'.
	 * @property {string} start_date - Start date in YYYY-MM-DD format.
	 * @property {string} end_date - End date in YYYY-MM-DD format.
	 */

	/**
	 * @typedef {Object} LineChartDataset
	 * @property {string} label - Dataset series label.
	 * @property {Array.<number>} data - Array of numeric data values.
	 * @property {string} [borderColor] - Line stroke color.
	 * @property {string} [backgroundColor] - Line fill or background color.
	 * @property {number} [borderWidth] - Line stroke width.
	 * @property {boolean} [fill] - Whether to fill the area under the line.
	 * @property {number} [tension] - Bezier curve tension.
	 * @property {number} [pointRadius] - Radius of data point dots.
	 */

	/**
	 * Main Freemius BI Dashboard Controller object.
	 *
	 * @namespace FSBI
	 */
	const FSBI = {
		/**
		 * Cache of active Chart.js instances keyed by chart identifier.
		 * @type {Object.<string, Object>}
		 */
		charts: {},

		/**
		 * Active DataTables instance for the monthly breakdown table.
		 * @type {Object|null}
		 */
		dataTable: null,

		/**
		 * Currently active report currency code returned from backend payload.
		 * @type {string|null}
		 */
		currentReportCurrency: null,

		/**
		 * Whether the loading overlay is currently on screen.
		 * @type {boolean}
		 */
		loadingActive: false,

		/**
		 * Current loading percentage.
		 * @type {number}
		 */
		loadingValue: 0,

		/**
		 * Timer that eases the loading percentage while the request is in flight.
		 * @type {number|null}
		 */
		loadingTimer: null,

		/**
		 * Timestamp the overlay became visible, used to avoid a flicker on fast loads.
		 * @type {number}
		 */
		loadingShownAt: 0,

		/**
		 * Active customer-type segment for the uninstall reasons panel.
		 * @type {string}
		 */
		churnSegment: 'free',

		/**
		 * Last uninstall reason payload, kept so segment switches need no request.
		 * @type {Array}
		 */
		churnReasons: [],

		/**
		 * Uninstalls recorded in the selected period.
		 * @type {number}
		 */
		uninstallTotal: 0,

		/**
		 * AbortController instance for in-flight AJAX synchronization requests.
		 * @type {AbortController|null}
		 */
		syncController: null,

		/**
		 * Active server-side synchronization task identifier.
		 * @type {string|null}
		 */
		activeSyncId: null,

		/**
		 * Flag indicating whether synchronization was manually canceled by user.
		 * @type {boolean}
		 */
		syncCanceled: false,

		/**
		 * Flag indicating whether initial background data refresh check has executed.
		 * @type {boolean}
		 */
		latestRefreshDone: false,

		/**
		 * Cached monthly breakdown table data payload.
		 * @type {Object|null}
		 */
		monthlyTableData: null,

		/**
		 * Initialize dashboard controller: apply default filters, bind events,
		 * initialize tooltips, check latest data, and fetch initial dashboard payload.
		 *
		 * @function init
		 * @memberof FSBI
		 * @returns {void}
		 */
		init: function() {
			this.applyDefaultFilters();
			// Tabs first: the hash decides which panel is visible before any data
			// arrives, so a link to #growth does not flash the overview.
			this.initTabs();
			this.bindEvents();
			this.initTooltips();
			this.showLoading(this.loadingText('contacting'));
			this.refreshLatestData().finally(() => {
				this.setLoadingProgress(45, this.loadingText('building'));
				this.loadData();
			});
		},

		/**
		 * Read a translated loading string.
		 *
		 * @function loadingText
		 * @memberof FSBI
		 * @param {string} key - String key supplied by the PHP side.
		 * @returns {string}
		 */
		loadingText: function(key) {
			const strings = (window.rlFsbiAdmin && window.rlFsbiAdmin.loadingStrings) || {};
			return strings[key] || '';
		},

		/**
		 * Blur the dashboard and show the progress card.
		 *
		 * Idempotent: calling it while the card is already up only updates the
		 * message, so init() and loadData() can both ask for it without the
		 * percentage restarting halfway through.
		 *
		 * @function showLoading
		 * @memberof FSBI
		 * @param {string} message - Message to display.
		 * @returns {void}
		 */
		showLoading: function(message) {
			const overlay = document.getElementById('rl-fsbi-loading');
			const wrap = document.querySelector('.rl-fsbi-dashboard');

			if (!overlay || !wrap) {
				return;
			}

			if (this.loadingActive) {
				if (message) {
					this.setLoadingMessage(message);
				}
				return;
			}

			this.loadingActive = true;
			this.loadingValue = 4;
			this.loadingShownAt = Date.now();
			wrap.classList.add('is-loading');
			wrap.setAttribute('aria-busy', 'true');
			overlay.style.display = 'flex';
			overlay.style.opacity = '1';

			if (message) {
				this.setLoadingMessage(message);
			}

			this.paintLoading();

			// The payload arrives in a single round-trip, so there is no real
			// progress to measure. Ease towards 90% and let the response finish it.
			this.loadingTimer = window.setInterval(() => {
				if (this.loadingValue >= 90) {
					return;
				}

				this.loadingValue += (90 - this.loadingValue) * 0.07;
				this.paintLoading();
			}, 140);
		},

		/**
		 * Replace the loading message.
		 *
		 * @function setLoadingMessage
		 * @memberof FSBI
		 * @param {string} message - Message to display.
		 * @returns {void}
		 */
		setLoadingMessage: function(message) {
			const el = document.getElementById('rl-fsbi-loading-message');

			if (el && message) {
				el.textContent = message;
			}
		},

		/**
		 * Advance the progress indicator. Never moves backwards.
		 *
		 * @function setLoadingProgress
		 * @memberof FSBI
		 * @param {number} value - Percentage.
		 * @param {string} [message] - Optional new message.
		 * @returns {void}
		 */
		setLoadingProgress: function(value, message) {
			this.loadingValue = Math.max(this.loadingValue, Math.min(100, value));

			if (message) {
				this.setLoadingMessage(message);
			}

			this.paintLoading();
		},

		/**
		 * Render the current progress value.
		 *
		 * @function paintLoading
		 * @memberof FSBI
		 * @returns {void}
		 */
		paintLoading: function() {
			const pct = Math.round(this.loadingValue);
			const fill = document.getElementById('rl-fsbi-loading-fill');
			const label = document.getElementById('rl-fsbi-loading-pct');

			if (fill) {
				fill.style.width = pct + '%';
			}

			if (label) {
				label.textContent = pct + '%';
			}
		},

		/**
		 * Complete the bar, then unblur and remove the overlay.
		 *
		 * @function hideLoading
		 * @memberof FSBI
		 * @returns {void}
		 */
		hideLoading: function() {
			const overlay = document.getElementById('rl-fsbi-loading');
			const wrap = document.querySelector('.rl-fsbi-dashboard');

			if (!overlay) {
				return;
			}

			if (this.loadingTimer) {
				window.clearInterval(this.loadingTimer);
				this.loadingTimer = null;
			}

			this.setLoadingProgress(100, this.loadingText('done'));

			// A cached response can return in under 200ms, which would flash the
			// blur for a single frame. Hold it long enough to read as intentional.
			const shownFor = Date.now() - (this.loadingShownAt || 0);
			const holdMs = Math.max(0, 480 - shownFor);

			window.setTimeout(() => {
				overlay.style.opacity = '0';

				window.setTimeout(() => {
					overlay.style.display = 'none';
					overlay.style.opacity = '';

					if (wrap) {
						wrap.classList.remove('is-loading');
						wrap.removeAttribute('aria-busy');
					}

					this.loadingActive = false;
				}, 200);
			}, holdMs + 120);
		},

		/**
		 * Initialize interactive tooltips on all elements with `.rl-fsbi-info-tooltip`.
		 *
		 * @function initTooltips
		 * @memberof FSBI
		 * @returns {void}
		 */
		initTooltips: function() {
			if (typeof window.tippy === 'function') {
				try {
					window.tippy('.rl-fsbi-info-tooltip', {
						theme: 'light-border',
						placement: 'top',
						arrow: true,
						allowHTML: true,
						interactive: true,
					});
				} catch (e) {
					// Fallback to native browser title attribute.
				}
			}
		},

		/**
		 * Apply initial filter defaults for currency and rolling month dates.
		 *
		 * @function applyDefaultFilters
		 * @memberof FSBI
		 * @returns {void}
		 */
		/**
		 * Hash-routed tabs.
		 *
		 * The hash is the source of truth, so browser back/forward and a pasted
		 * link to #growth both land on the right panel. Tabs are a real tablist:
		 * one tab in the tab order at a time, arrow keys move between them,
		 * Home/End jump to the ends, and aria-selected carries the state.
		 *
		 * @function initTabs
		 * @memberof FSBI
		 * @returns {void}
		 */
		initTabs: function() {
			this.tabList = Array.prototype.slice.call(document.querySelectorAll('.rl-fsbi-tab'));
			if (!this.tabList.length) {
				return;
			}

			this.tabList.forEach((tab) => {
				tab.addEventListener('click', () => {
					const slug = tab.getAttribute('data-tab');
					if (window.location.hash !== '#' + slug) {
						window.location.hash = slug;
					} else {
						this.showTab(slug);
					}
				});

				tab.addEventListener('keydown', (event) => {
					const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
					if (keys.indexOf(event.key) === -1) {
						return;
					}

					event.preventDefault();
					const index = this.tabList.indexOf(tab);
					let next = index;

					if ('ArrowRight' === event.key) {
						next = (index + 1) % this.tabList.length;
					} else if ('ArrowLeft' === event.key) {
						next = (index - 1 + this.tabList.length) % this.tabList.length;
					} else if ('Home' === event.key) {
						next = 0;
					} else if ('End' === event.key) {
						next = this.tabList.length - 1;
					}

					const target = this.tabList[next];
					if (target) {
						target.focus();
						this.showTab(target.getAttribute('data-tab'), true);
					}
				});
			});

			window.addEventListener('hashchange', () => this.applyTabFromHash());
			this.applyTabFromHash();
		},

		/**
		 * Show the panel named in the URL hash, falling back to overview.
		 *
		 * A #wporg link on a product with no WordPress.org slug must not leave
		 * the dashboard blank, so an unknown tab resolves to overview.
		 *
		 * @function applyTabFromHash
		 * @memberof FSBI
		 * @returns {void}
		 */
		applyTabFromHash: function() {
			const slug = (window.location.hash || '').replace(/^#/, '');
			this.showTab(slug || 'overview');
		},

		/**
		 * Show one panel and mark its tab selected.
		 *
		 * @function showTab
		 * @memberof FSBI
		 * @param {string}  slug  Tab slug.
		 * @param {boolean} focus Move focus to the tab as well.
		 * @returns {void}
		 */
		showTab: function(slug, focus) {
			const panels = Array.prototype.slice.call(document.querySelectorAll('.rl-fsbi-panel[data-panel]'));
			const wanted = panels.some((panel) => panel.getAttribute('data-panel') === slug) ? slug : 'overview';

			panels.forEach((panel) => {
				const isActive = panel.getAttribute('data-panel') === wanted;
				if (isActive) {
					panel.removeAttribute('hidden');
				} else {
					panel.setAttribute('hidden', 'hidden');
				}
			});

			this.tabList.forEach((tab) => {
				const isActive = tab.getAttribute('data-tab') === wanted;
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
				tab.setAttribute('tabindex', isActive ? '0' : '-1');
				if (isActive && focus) {
					tab.focus();
				}
			});

			// Switching tabs reuses whatever that tab already loaded with the same
			// filters. Only an unknown or invalidated slice costs a request.
			// Set first: getFilters() reads currentTab, so the load started below
			// must already know which slice it is asking for.
			this.currentTab = wanted;

			if (!this.tabLoading) {
				const cached = this.cachedSection(wanted);
				if (cached) {
					this.hasRenderedData = true;
					this.renderDashboard(cached);
				} else if (this.hasRenderedData) {
					this.loadSection(wanted);
				}
			}

			// Arrow-key activation must keep the URL honest without pushing a
			// history entry per keystroke, so it rewrites the current entry.
			if (focus && window.location.hash !== '#' + wanted) {
				window.history.replaceState(null, '', '#' + wanted);
			}

			this.currentTab = wanted;
		},

		applyDefaultFilters: function() {
			const currencyFilter = document.getElementById('rl-fsbi-currency-filter');
			if (currencyFilter) {
				// Always start from all currencies, then convert to report currency in backend.
				currencyFilter.value = 'all';
			}

			const startField = document.getElementById('rl-fsbi-start-date');
			const endField = document.getElementById('rl-fsbi-end-date');
			if (startField && endField && !startField.value && !endField.value) {
				const now = new Date();
				const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
				const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);
				startField.value = this.toISODate(monthStart);
				endField.value = this.toISODate(monthEnd);
			}
		},

		/**
		 * Attach DOM event listeners to interactive toolbar controls and buttons.
		 *
		 * @function bindEvents
		 * @memberof FSBI
		 * @returns {void}
		 */
		bindEvents: function() {
			const self = this;

			document.getElementById('rl-fsbi-sync-btn')?.addEventListener('click', function() {
				self.syncData();
			});

			document.getElementById('rl-fsbi-sync-cancel-btn')?.addEventListener('click', function() {
				self.cancelSync();
			});

			document.getElementById('rl-fsbi-plugin-filter')?.addEventListener('change', function() {
				const selectedOption = this.options[this.selectedIndex];
				if (selectedOption) {
					const cleanTitle = selectedOption.value === 'all'
						? 'All Plugins'
						: selectedOption.text.replace(/\s*\(#\d+\)$/, '');
					self.setText('rl-fsbi-active-plugin-title', cleanTitle);

					const versionWrap = document.getElementById('rl-fsbi-active-plugin-version');
					if (versionWrap && selectedOption.value === 'all') {
						versionWrap.style.display = 'none';
					}
				}
				self.loadData();
			});

			document.getElementById('rl-fsbi-refresh-optins-btn')?.addEventListener('click', function(e) {
				e.preventDefault();
				if (rlFsbiAdmin && rlFsbiAdmin.newsletterProvider === 'kit') {
					self.fetchKitStats(true);
				} else if (rlFsbiAdmin && rlFsbiAdmin.newsletterProvider === 'mailchimp') {
					self.fetchMailchimpStats(true);
				} else {
					self.loadMarketingOptins(0, 0, 0, true);
				}
			});

			document.getElementById('rl-fsbi-sync-mailchimp-btn')?.addEventListener('click', function(e) {
				e.preventDefault();
				self.syncMailchimpOptins();
			});

			document.getElementById('rl-fsbi-mc-stop-sync-btn')?.addEventListener('click', function(e) {
				e.preventDefault();
				self.stopMailchimpSync();
			});

			const closeMcModal = function(e) {
				if (e) e.preventDefault();
				const modal = document.getElementById('rl-fsbi-mc-sync-modal');
				if (modal) modal.style.display = 'none';
			};
			document.getElementById('rl-fsbi-mc-close-modal-btn')?.addEventListener('click', closeMcModal);
			document.getElementById('rl-fsbi-mc-modal-close-x')?.addEventListener('click', closeMcModal);

			document.getElementById('rl-fsbi-currency-filter')?.addEventListener('change', function() {
				self.invalidateSections();
				self.tabLoading = self.currentTab || 'overview';
				self.loadData();
			});

			document.getElementById('rl-fsbi-plan-filter')?.addEventListener('change', function() {
				self.invalidateSections();
				self.tabLoading = self.currentTab || 'overview';
				self.loadData();
			});

			document.getElementById('rl-fsbi-start-date')?.addEventListener('change', function() {
				self.invalidateSections();
				self.tabLoading = self.currentTab || 'overview';
				self.loadData();
			});

			document.getElementById('rl-fsbi-end-date')?.addEventListener('change', function() {
				self.invalidateSections();
				self.tabLoading = self.currentTab || 'overview';
				self.loadData();
			});

			document.querySelectorAll('#rl-fsbi-churn-segments .rl-fsbi-churn-chip').forEach(function(chip) {
				chip.addEventListener('click', function() {
					const segment = this.getAttribute('data-segment') || 'free';
					self.churnSegment = segment;

					document.querySelectorAll('#rl-fsbi-churn-segments .rl-fsbi-churn-chip').forEach(function(other) {
						other.classList.toggle('is-active', other === chip);
						other.setAttribute('aria-pressed', other === chip ? 'true' : 'false');
					});

					self.paintChurnReasons();
				});
			});

			document.getElementById('rl-fsbi-export-monthly-csv')?.addEventListener('click', function() {
				self.exportMonthlyCsv();
			});

			document.getElementById('rl-fsbi-export-3yr-csv')?.addEventListener('click', function() {
				self.export3YearCsv();
			});

			document.getElementById('rl-fsbi-export-optins-csv-btn')?.addEventListener('click', function(e) {
				if (e) e.preventDefault();
				self.exportOptinsCsv();
			});

			document.getElementById('rl-fsbi-modal-export-csv-btn')?.addEventListener('click', function(e) {
				if (e) e.preventDefault();
				self.generateOptinsCsvOnServer();
			});

			document.getElementById('rl-fsbi-modal-download-latest-btn')?.addEventListener('click', function(e) {
				if (e) e.preventDefault();
				self.downloadLatestGeneratedCsv();
			});

			if (rlFsbiAdmin && rlFsbiAdmin.latestExport && rlFsbiAdmin.latestExport.token) {
				const downloadLatestBtn = document.getElementById('rl-fsbi-modal-download-latest-btn');
				const downloadLatestText = document.getElementById('rl-fsbi-modal-download-latest-text');
				if (downloadLatestBtn) {
					downloadLatestBtn.style.display = 'inline-flex';
					if (downloadLatestText && rlFsbiAdmin.latestExport.optins_count) {
						downloadLatestText.textContent = 'Download Ready CSV (' + Number(rlFsbiAdmin.latestExport.optins_count).toLocaleString() + ')';
					}
				}
			}

			if (rlFsbiAdmin && rlFsbiAdmin.lastNewsletterSync) {
				const lastSyncTag = document.getElementById('rl-fsbi-last-sync-tag');
				if (lastSyncTag) {
					lastSyncTag.textContent = 'Last: ' + rlFsbiAdmin.lastNewsletterSync + ' UTC';
				}
			}
		},

		/**
		 * Format a Date object into an ISO YYYY-MM-DD date string.
		 *
		 * @function toISODate
		 * @memberof FSBI
		 * @param {Date} dateObj - JavaScript Date object.
		 * @returns {string} Formatted date string (YYYY-MM-DD).
		 */
		toISODate: function(dateObj) {
			const pad = (v) => String(v).padStart(2, '0');
			return `${dateObj.getFullYear()}-${pad(dateObj.getMonth() + 1)}-${pad(dateObj.getDate())}`;
		},

		/**
		 * Safely update the textContent of a DOM element by element ID.
		 *
		 * @function setText
		 * @memberof FSBI
		 * @param {string} id - Target DOM element identifier.
		 * @param {string|number} value - Text content to set.
		 * @returns {void}
		 */
		setText: function(id, value) {
			const node = document.getElementById(id);
			if (node) {
				node.textContent = value;
			}
		},

		/**
		 * Safely update the innerHTML of a DOM element by element ID.
		 *
		 * @function setHtml
		 * @memberof FSBI
		 * @param {string} id - Target DOM element identifier.
		 * @param {string} value - HTML markup string to set.
		 * @returns {void}
		 */
		setHtml: function(id, value) {
			const node = document.getElementById(id);
			if (node) {
				node.innerHTML = value;
			}
		},

		/**
		 * Calculate optimal chart aspect ratio based on chart identifier and type.
		 *
		 * @function getChartAspectRatio
		 * @memberof FSBI
		 * @param {string} key - Chart identifier key.
		 * @param {string} type - Chart type (e.g. 'line', 'bar', 'doughnut').
		 * @returns {number} Aspect ratio (width / height).
		 */
		getChartAspectRatio: function(key, type) {
			if (type === 'doughnut') {
				return key === 'country' ? 1.8 : 1;
			}

			const map = {
				salesActivity: 2.2,
				revenueOverview: 2.0,
				wporg: 2.2,
				forecast: 1.7,
				churn: 1.5,
			};

			return map[key] || 1.8;
		},

		/**
		 * Retrieve active filter parameters from toolbar controls.
		 *
		 * @function getFilters
		 * @memberof FSBI
		 * @returns {DashboardFilters} Object containing plugin_id, currency, start_date, end_date.
		 */
		getFilters: function() {
			const forcedPlugin = Number.parseInt(rlFsbiAdmin.forcedPluginId || 0, 10);
			const pluginField = document.getElementById('rl-fsbi-plugin-filter');
			const pluginFallback = document.getElementById('rl-fsbi-plugin-filter-forced');
			let pluginId = pluginField?.value || 'all';

			if (forcedPlugin > 0) {
				pluginId = String(forcedPlugin);
			} else if (pluginField?.disabled && pluginFallback?.value) {
				pluginId = pluginFallback.value;
			}

			return {
				section: this.currentTab || 'overview',
				plugin_id: pluginId,
				currency: document.getElementById('rl-fsbi-currency-filter')?.value || 'all',
				plan_id: document.getElementById('rl-fsbi-plan-filter')?.value || 'all',
				start_date: document.getElementById('rl-fsbi-start-date')?.value || '',
				end_date: document.getElementById('rl-fsbi-end-date')?.value || '',
			};
		},

		/**
		 * Fill the plan filter from the payload and keep the choice across reloads.
		 *
		 * @function updatePlanFilter
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing the plans list.
		 * @returns {void}
		 */
		updatePlanFilter: function(data) {
			const select = document.getElementById('rl-fsbi-plan-filter');
			if (!select) {
				return;
			}

			const plans = Array.isArray(data.plans) ? data.plans : [];
			const current = select.value;

			select.innerHTML = '';

			const allOption = document.createElement('option');
			allOption.value = 'all';
			allOption.textContent = 'All Plans';
			select.appendChild(allOption);

			plans.forEach((plan) => {
				const option = document.createElement('option');
				option.value = String(plan.id);
				option.textContent = plan.name;
				select.appendChild(option);
			});

			const keep = data.plan_filter ? String(data.plan_filter) : current;
			if (keep && [...select.options].some((o) => o.value === keep)) {
				select.value = keep;
			}
		},

		/**
		 * Resolve the current display currency code.
		 *
		 * @function getDisplayCurrency
		 * @memberof FSBI
		 * @returns {string} 3-letter uppercase ISO currency code.
		 */
		getDisplayCurrency: function() {
			if (this.currentReportCurrency) {
				return this.currentReportCurrency;
			}
			const selectedCurrency = document.getElementById('rl-fsbi-currency-filter')?.value || rlFsbiAdmin.defaultCurrency || 'USD';
			return selectedCurrency === 'all' ? (rlFsbiAdmin.defaultCurrency || 'USD') : selectedCurrency;
		},

		/**
		 * Format a numeric amount into a localized currency string using Intl.NumberFormat.
		 *
		 * @function formatCurrency
		 * @memberof FSBI
		 * @param {number|string} value - Monetary amount to format.
		 * @param {string} [currency] - Optional target currency code. Defaults to active display currency.
		 * @returns {string} Formatted localized currency string.
		 */
		formatCurrency: function(value, currency) {
			const safe = Number.isFinite(Number(value)) ? Number(value) : 0;
			return new Intl.NumberFormat(rlFsbiAdmin.localeFormat || 'en-US', {
				style: 'currency',
				currency: currency || this.getDisplayCurrency(),
				maximumFractionDigits: 2,
			}).format(safe);
		},

		/**
		 * Format a numeric value into a localized percentage string.
		 *
		 * @function formatPercent
		 * @memberof FSBI
		 * @param {number|string} value - Percentage value (e.g. 14.5).
		 * @param {number} [decimals=1] - Number of decimal digits.
		 * @returns {string} Formatted percentage string (e.g. '14.5%').
		 */
		formatPercent: function(value, decimals = 1) {
			const safe = Number.isFinite(Number(value)) ? Number(value) : 0;
			return `${safe.toFixed(decimals)}%`;
		},

		/**
		 * Trigger background sync check to fetch recent transactions if cache is stale.
		 *
		 * @function refreshLatestData
		 * @memberof FSBI
		 * @returns {Promise.<void>} Promise resolving when the check completes.
		 */
		refreshLatestData: function() {
			if (this.latestRefreshDone) {
				return Promise.resolve();
			}
			this.latestRefreshDone = true;

			return fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams({
					action: 'rl_fsbi_refresh_latest',
					nonce: rlFsbiAdmin.nonce,
				}),
			})
			.then((response) => response.json())
			.catch((error) => {
				console.warn('Latest Freemius refresh failed:', error);
			});
		},

		/**
		 * Fetch marketing opt-ins asynchronously.
		/**
		 * Fetch subscriber count from Kit.com and update dashboard widget.
		 *
		 * @function fetchKitStats
		 * @memberof FSBI
		 * @param {boolean} manual Triggered by click.
		 * @returns {void}
		 */
		fetchKitStats: function(manual = false) {
			const refreshBtn = document.getElementById('rl-fsbi-refresh-optins-btn');
			const refreshIcon = refreshBtn ? refreshBtn.querySelector('.dashicons') : null;
			const statEl = document.getElementById('rl-fsbi-stat-marketing-optins');

			if (manual) {
				if (refreshIcon) {
					refreshIcon.classList.add('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = true;
				}
				if (statEl) {
					statEl.innerHTML = '<span class="spinner is-active" style="float:none; margin:0; width:auto; height:auto; display:inline-block;"></span>';
				}
			}

			this.lastFailedSection = null;
			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_fetch_kit_stats',
					nonce: rlFsbiAdmin.nonce,
					plugin_id: this.getFilters().plugin_id || 'all'
				}),
			})
			.then(response => response.json())
			.then(res => {
				if (refreshIcon) {
					refreshIcon.classList.remove('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = false;
				}
				if (res.success && res.data) {
					const count = res.data.count !== undefined ? res.data.count : (res.data.member_count || 0);
					if (statEl) {
						statEl.innerText = this.formatNumber(count);
					}
				}
			})
			.catch(() => {
				if (refreshIcon) {
					refreshIcon.classList.remove('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = false;
				}
			});
		},

		/**
		 * Fetch audience subscriber count from Mailchimp and update dashboard widget.
		 *
		 * @function fetchMailchimpStats
		 * @memberof FSBI
		 * @param {boolean} manual Triggered by click.
		 * @returns {void}
		 */
		fetchMailchimpStats: function(manual = false) {
			const refreshBtn = document.getElementById('rl-fsbi-refresh-optins-btn');
			const refreshIcon = refreshBtn ? refreshBtn.querySelector('.dashicons') : null;
			const statEl = document.getElementById('rl-fsbi-stat-marketing-optins');

			if (manual) {
				if (refreshIcon) {
					refreshIcon.classList.add('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = true;
				}
				if (statEl) {
					statEl.innerHTML = '<span class="spinner is-active" style="float:none; margin:0; width:auto; height:auto; display:inline-block;"></span>';
				}
			}

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_fetch_mailchimp_list_stats',
					nonce: rlFsbiAdmin.nonce,
					plugin_id: this.getFilters().plugin_id || 'all'
				}),
			})
			.then(response => response.json())
			.then(res => {
				if (refreshIcon) {
					refreshIcon.classList.remove('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = false;
				}
				if (res.success && res.data) {
					const count = res.data.member_count !== undefined ? res.data.member_count : (res.data.count || 0);
					if (statEl) {
						statEl.innerText = this.formatNumber(count);
					}
				}
			})
			.catch(() => {
				if (refreshIcon) {
					refreshIcon.classList.remove('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = false;
				}
			});
		},

		/**
		 * Asynchronously count users opting in across all tracked plugins.
		 *
		 * @function loadMarketingOptins
		 * @memberof FSBI
		 * @param {number} [offset=0] Pagination offset.
		 * @param {number} [count=0] Accumulated count.
		 * @param {number} [pluginIndex=0] Current plugin index.
		 * @returns {void}
		 */
		loadMarketingOptins: function(offset = 0, count = 0, pluginIndex = 0, manual = false) {
			const filters = this.getFilters();
			const statEl = document.getElementById('rl-fsbi-stat-marketing-optins');
			const refreshBtn = document.getElementById('rl-fsbi-refresh-optins-btn');
			const refreshIcon = refreshBtn ? refreshBtn.querySelector('.dashicons') : null;

			if (offset === 0 && pluginIndex === 0) {
				if (refreshIcon) {
					refreshIcon.classList.add('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = true;
				}
				if (statEl) {
					statEl.innerHTML = '<span class="spinner is-active" style="float:none; margin:0; width:auto; height:auto; display:inline-block;"></span>';
				}
			}

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_get_marketing_optins',
					nonce: rlFsbiAdmin.nonce,
					plugin_id: filters.plugin_id || 'all',
					offset: offset,
					count: count,
					plugin_index: pluginIndex
				}),
			})
			.then(response => response.json())
			.then(res => {
				if (res.success && !res.data.done) {
					// Continue fetching
					if (statEl) {
						statEl.innerHTML = '<span class="spinner is-active" style="float:none; margin:0; width:auto; height:auto; display:inline-block;"></span> <span style="font-size:12px; font-weight:normal;">(' + res.data.count + ')</span>';
					}
					this.loadMarketingOptins(res.data.offset, res.data.count, res.data.plugin_index, manual);
				} else if (res.success && res.data.done) {
					// Done
					if (refreshIcon) {
						refreshIcon.classList.remove('rl-fsbi-spinning');
					}
					if (refreshBtn) {
						refreshBtn.disabled = false;
					}
					if (statEl) {
						statEl.innerText = this.formatNumber(res.data.count);
					}
				} else {
					if (refreshIcon) {
						refreshIcon.classList.remove('rl-fsbi-spinning');
					}
					if (refreshBtn) {
						refreshBtn.disabled = false;
					}
					if (statEl) {
						statEl.innerText = '-';
					}
				}
			})
			.catch(err => {
				if (refreshIcon) {
					refreshIcon.classList.remove('rl-fsbi-spinning');
				}
				if (refreshBtn) {
					refreshBtn.disabled = false;
				}
				if (statEl) {
					statEl.innerText = '-';
				}
			});
		},

		/**
		 * Controller state tracking for Mailchimp sync cancellation.
		 */
		mailchimpSyncCanceled: false,
		mailchimpSyncController: null,
		csvGenCanceled: false,
		csvGenController: null,

		/**
		 * Stop an in-progress Mailchimp sync or server CSV generation.
		 *
		 * @function stopMailchimpSync
		 * @memberof FSBI
		 * @returns {void}
		 */
		stopMailchimpSync: function() {
			this.mailchimpSyncCanceled = true;
			this.csvGenCanceled = true;
			if (this.mailchimpSyncController) {
				this.mailchimpSyncController.abort();
				this.mailchimpSyncController = null;
			}
			if (this.csvGenController) {
				this.csvGenController.abort();
				this.csvGenController = null;
			}
			const statusText = document.getElementById('rl-fsbi-mc-sync-status-text');
			if (statusText) {
				statusText.innerHTML = '<span style="color:#dc2626; font-weight:600;">Process stopped by user.</span>';
			}
			const stopBtn = document.getElementById('rl-fsbi-mc-stop-sync-btn');
			const closeBtn = document.getElementById('rl-fsbi-mc-close-modal-btn');
			if (stopBtn) stopBtn.style.display = 'none';
			if (closeBtn) closeBtn.style.display = 'inline-block';

			const refreshIcon = document.querySelector('#rl-fsbi-sync-mailchimp-btn .dashicons');
			if (refreshIcon) refreshIcon.classList.remove('rl-fsbi-spinning');
			const syncBtn = document.getElementById('rl-fsbi-sync-mailchimp-btn');
			if (syncBtn) syncBtn.disabled = false;
		},

		/**
		 * Synchronize opted-in newsletter contacts to Mailchimp with interactive visual progress.
		 *
		 * @function syncMailchimpOptins
		 * @memberof FSBI
		 * @param {number} [offset=0] Pagination offset.
		 * @param {number} [syncedCount=0] Accumulated synced count.
		 * @param {number} [pluginIndex=0] Current plugin index.
		 * @param {number} [scannedCount=0] Accumulated scanned count.
		 * @param {number} [optinsCount=0] Accumulated opt-ins found.
		 * @param {number} [errorsCount=0] Accumulated errors/skips.
		 * @returns {void}
		 */
		syncMailchimpOptins: function(offset = 0, syncedCount = 0, pluginIndex = 0, scannedCount = 0, optinsCount = 0, errorsCount = 0) {
			const self = this;
			const btn = document.getElementById('rl-fsbi-sync-mailchimp-btn');
			const icon = btn ? btn.querySelector('.dashicons') : null;
			const filters = this.getFilters();

			const modal = document.getElementById('rl-fsbi-mc-sync-modal');
			const statusText = document.getElementById('rl-fsbi-mc-sync-status-text');
			const progressBar = document.getElementById('rl-fsbi-mc-sync-progress-bar');
			const logConsole = document.getElementById('rl-fsbi-mc-sync-live-log');
			const stopBtn = document.getElementById('rl-fsbi-mc-stop-sync-btn');
			const closeBtn = document.getElementById('rl-fsbi-mc-close-modal-btn');

			if (offset === 0 && pluginIndex === 0) {
				this.mailchimpSyncCanceled = false;
				this.mailchimpSyncController = new AbortController();

				if (btn) btn.disabled = true;
				if (icon) icon.classList.add('rl-fsbi-spinning');

				if (modal) modal.style.display = 'flex';
				if (stopBtn) stopBtn.style.display = 'inline-flex';
				if (closeBtn) closeBtn.style.display = 'none';
				if (progressBar) progressBar.style.width = '5%';

				this.setText('rl-fsbi-mc-stat-scanned', '0');
				this.setText('rl-fsbi-mc-stat-optins', '0');
				this.setText('rl-fsbi-mc-stat-synced', '0');
				this.setText('rl-fsbi-mc-stat-errors', '0');

				const providerName = (rlFsbiAdmin && rlFsbiAdmin.newsletterProvider === 'kit') ? 'Kit.com' : 'Mailchimp';
				const modalTitle = document.getElementById('rl-fsbi-mc-modal-title');
				if (modalTitle) modalTitle.textContent = providerName + ' Opt-ins Synchronization';
				if (statusText) statusText.innerText = 'Connecting to Freemius and ' + providerName + '...';
				if (logConsole) logConsole.innerHTML = '<div>' + (new Date()).toLocaleTimeString() + ' - Initializing ' + providerName + ' sync job...</div>';

				const deltaLabel = document.getElementById('rl-fsbi-sync-delta-label');
				const emailLabel = document.getElementById('rl-fsbi-csv-email-label');
				const syncedLabel = document.getElementById('rl-fsbi-mc-stat-synced-label');
				if (deltaLabel) deltaLabel.style.display = 'inline-flex';
				if (emailLabel) emailLabel.style.display = 'none';
				if (syncedLabel) syncedLabel.textContent = 'Synced';

				const startMsg = 'Starting ' + providerName + ' Opt-ins Synchronization (Target: ' + (filters.plugin_id || 'all') + ')';
				if (window.rlFramework && typeof window.rlFramework.log === 'function') {
					window.rlFramework.log(startMsg, { pluginId: filters.plugin_id || 'all', provider: providerName });
				} else {
					console.log('[RL Framework DEBUG] ' + startMsg);
				}
			}

			if (this.mailchimpSyncCanceled) {
				return;
			}

			const providerName = (rlFsbiAdmin && rlFsbiAdmin.newsletterProvider === 'kit') ? 'Kit.com' : 'Mailchimp';
			const deltaCheckbox = document.getElementById('rl-fsbi-sync-delta-only');
			const deltaOnly = deltaCheckbox && deltaCheckbox.checked ? 1 : 0;

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_sync_mailchimp_optins',
					nonce: rlFsbiAdmin.nonce,
					plugin_id: filters.plugin_id || 'all',
					offset: offset,
					synced_count: syncedCount,
					scanned_count: scannedCount,
					optins_count: optinsCount,
					errors_count: errorsCount,
					plugin_index: pluginIndex,
					delta_only: deltaOnly,
				}),
				signal: this.mailchimpSyncController?.signal,
			})
			.then(response => response.json())
			.then(res => {
				if (self.mailchimpSyncCanceled) {
					return;
				}

				if (res.success) {
					const data = res.data;
					self.setText('rl-fsbi-mc-stat-scanned', Number(data.scanned_count || 0).toLocaleString());
					self.setText('rl-fsbi-mc-stat-optins', Number(data.optins_count || 0).toLocaleString());
					self.setText('rl-fsbi-mc-stat-synced', Number(data.synced_count || 0).toLocaleString());
					self.setText('rl-fsbi-mc-stat-errors', Number(data.errors_count || 0).toLocaleString());

					if (Array.isArray(data.logs) && data.logs.length > 0) {
						data.logs.forEach(function(logLine) {
							if (logConsole) {
								logConsole.innerHTML += '<div>' + logLine + '</div>';
							}
							if (window.rlFramework && typeof window.rlFramework.log === 'function') {
								window.rlFramework.log(logLine);
							} else {
								console.log('[RL Framework DEBUG] ' + logLine);
							}
						});
						if (logConsole) {
							logConsole.scrollTop = logConsole.scrollHeight;
						}
					} else if (logConsole && data.message) {
						logConsole.innerHTML += '<div>' + (new Date()).toLocaleTimeString() + ' - ' + data.message + '</div>';
						logConsole.scrollTop = logConsole.scrollHeight;
						if (window.rlFramework && typeof window.rlFramework.log === 'function') {
							window.rlFramework.log(data.message, data);
						} else {
							console.log('[RL Framework DEBUG] ' + data.message, data);
						}
					}

					if (statusText && data.message) {
						statusText.innerText = data.message;
					}

					// Update progress bar
					if (progressBar && data.total_plugins > 0) {
						const progressPct = data.done
							? 100
							: Math.min(95, Math.max(10, Math.round(((data.plugin_index + 1) / (data.total_plugins + 1)) * 100)));
						progressBar.style.width = progressPct + '%';
					}

					if (!data.done) {
						// Continue next batch with a small delay for UI rendering
						setTimeout(function() {
							if (self.mailchimpSyncCanceled) {
								return;
							}
							self.syncMailchimpOptins(
								data.offset,
								data.synced_count,
								data.plugin_index,
								data.scanned_count,
								data.optins_count,
								data.errors_count
							);
						}, 60);
					} else {
						// Complete!
						if (icon) icon.classList.remove('rl-fsbi-spinning');
						if (btn) btn.disabled = false;
						if (stopBtn) stopBtn.style.display = 'none';
						if (closeBtn) closeBtn.style.display = 'inline-block';
						if (progressBar) progressBar.style.width = '100%';

						if (statusText) {
							statusText.innerHTML = '<span style="color:#16a34a; font-weight:600;">✓ ' + data.message + '</span>';
						}

						const finishMsg = 'Sync complete: ' + (data.message || '');
						if (window.rlFramework && typeof window.rlFramework.log === 'function') {
							window.rlFramework.log(finishMsg, data);
						} else {
							console.log('[RL Framework DEBUG] ' + finishMsg, data);
						}

						// Automatically update newsletter opt-ins number on dashboard from verified audience count!
						const audienceTotal = data.audience_member_count || data.synced_count;
						self.setText('rl-fsbi-stat-marketing-optins', Number(audienceTotal).toLocaleString());
					}
				} else {
					if (icon) icon.classList.remove('rl-fsbi-spinning');
					if (btn) btn.disabled = false;
					if (stopBtn) stopBtn.style.display = 'none';
					if (closeBtn) closeBtn.style.display = 'inline-block';

					const errorMsg = res.data || ('Failed to sync to ' + providerName + '.');
					if (statusText) {
						statusText.innerHTML = '<span style="color:#dc2626; font-weight:600;">⚠ ' + errorMsg + '</span>';
					}

					if (logConsole) {
						logConsole.innerHTML += '<div style="color:#ef4444;">' + (new Date()).toLocaleTimeString() + ' - ⚠ ' + errorMsg + '</div>';
						logConsole.scrollTop = logConsole.scrollHeight;
					}

					if (window.rlFramework && typeof window.rlFramework.error === 'function') {
						window.rlFramework.error('Sync failed: ' + errorMsg);
					} else {
						console.error('[RL Framework ERROR] Sync failed: ' + errorMsg);
					}
				}
			})
			.catch(err => {
				if (self.mailchimpSyncCanceled) {
					return;
				}
				if (icon) icon.classList.remove('rl-fsbi-spinning');
				if (btn) btn.disabled = false;
				if (stopBtn) stopBtn.style.display = 'none';
				if (closeBtn) closeBtn.style.display = 'inline-block';

				const errMsg = 'Error during ' + providerName + ' sync: ' + (err.message || 'Network error');
				if (statusText) {
					statusText.innerHTML = '<span style="color:#dc2626; font-weight:600;">⚠ ' + errMsg + '</span>';
				}

				if (logConsole) {
					logConsole.innerHTML += '<div style="color:#ef4444;">' + (new Date()).toLocaleTimeString() + ' - ⚠ ' + errMsg + '</div>';
					logConsole.scrollTop = logConsole.scrollHeight;
				}

				if (window.rlFramework && typeof window.rlFramework.error === 'function') {
					window.rlFramework.error(errMsg, err);
				} else {
					console.error('[RL Framework ERROR] ' + errMsg, err);
				}
			});
		},

		/**
		 * Title the sales activity panel after the period actually being viewed.
		 *
		 * The heading used to be hard-coded to "Current Month", which was simply
		 * wrong once a custom range was selected.
		 *
		 * @function updateSalesActivityTitle
		 * @memberof FSBI
		 * @param {Object} filters - Active filters, including start_date/end_date.
		 * @returns {void}
		 */
		updateSalesActivityTitle: function(filters) {
			const el = document.getElementById('rl-fsbi-sales-activity-title');
			if (!el) return;

			const base = 'Sales Activity';
			const toDate = function(value) {
				const parts = String(value || '').split('-').map(Number);
				if (parts.length !== 3 || parts.some(isNaN)) return null;
				return new Date(parts[0], parts[1] - 1, parts[2]);
			};

			const start = toDate(filters.start_date);
			const end = toDate(filters.end_date);

			if (!start || !end) {
				el.textContent = base;
				return;
			}

			const sameMonth = start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth();
			const lastDayOfMonth = new Date(end.getFullYear(), end.getMonth() + 1, 0).getDate();
			const fullMonth = sameMonth && 1 === start.getDate() && lastDayOfMonth === end.getDate();

			// Only name the month when the whole month is in view; a 2-8 March
			// range labelled "March 2026" would claim more than it shows.
			if (fullMonth) {
				el.textContent = base + ': ' + end.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
				return;
			}

			const sameYear = start.getFullYear() === end.getFullYear();
			const startLabel = start.toLocaleDateString(undefined, {
				day: 'numeric',
				month: 'short',
				year: sameYear ? undefined : 'numeric',
			});
			const endLabel = end.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

			el.textContent = base + ': ' + startLabel + ' \u2013 ' + endLabel;
		},

		/**
		 * Toggle the lightweight refresh affordance used after the first render.
		 *
		 * @function markRefreshing
		 * @memberof FSBI
		 * @param {boolean} active - Whether a refresh is in flight.
		 * @returns {void}
		 */
		markRefreshing: function(active) {
			const toolbar = document.querySelector('.rl-fsbi-toolbar');
			if (toolbar) {
				toolbar.classList.toggle('is-refreshing', !!active);
				toolbar.setAttribute('aria-busy', active ? 'true' : 'false');
			}
		},

		/**
		 * Fetch complete aggregated analytics dataset from backend via AJAX.
		 *
		 * @function loadData
		 * @memberof FSBI
		 * @returns {void}
		 */
		/**
		 * Load one tab's slice, showing a skeleton in that panel only.
		 *
		 * @function loadSection
		 * @memberof FSBI
		 * @param {string} section Tab slug.
		 * @returns {void}
		 */
		loadSection: function(section) {
			this.showSectionSkeleton(section);
			this.tabLoading = section;
			this.loadData();
		},

		/**
		 * Replace a panel's contents with a skeleton while its slice loads.
		 *
		 * @function showSectionSkeleton
		 * @memberof FSBI
		 * @param {string} section Tab slug.
		 * @returns {void}
		 */
		showSectionSkeleton: function(section) {
			const panel = document.querySelector('.rl-fsbi-panel[data-panel="' + section + '"]');
			if (!panel) {
				return;
			}

			const existing = panel.querySelector('.rl-fsbi-skeleton');
			if (existing) {
				return;
			}

			const wrap = document.createElement('div');
			wrap.className = 'rl-fsbi-skeleton';
			wrap.setAttribute('role', 'status');
			wrap.setAttribute('aria-live', 'polite');
			wrap.innerHTML = '<span class="screen-reader-text">' + (window.rlFsbiAdmin?.i18nLoading || 'Loading section…') + '</span>'
				+ '<span class="rl-fsbi-skeleton-bar"></span>'
				+ '<span class="rl-fsbi-skeleton-bar is-short"></span>'
				+ '<span class="rl-fsbi-skeleton-bar"></span>';
			panel.insertBefore(wrap, panel.firstChild);
			panel.setAttribute('aria-busy', 'true');
			// Old values must not show under a loading panel.
			panel.classList.add('is-loading');
		},

		/**
		 * Remove a panel's skeleton.
		 *
		 * @function clearSectionSkeleton
		 * @memberof FSBI
		 * @param {string} section Tab slug.
		 * @returns {void}
		 */
		clearSectionSkeleton: function(section) {
			const panel = document.querySelector('.rl-fsbi-panel[data-panel="' + section + '"]');
			if (!panel) {
				return;
			}

			panel.querySelectorAll('.rl-fsbi-skeleton').forEach((el) => el.remove());
			panel.removeAttribute('aria-busy');
			panel.classList.remove('is-loading');
		},

		/**
		 * Route a failed request to the panel it belonged to.
		 *
		 * @function failSection
		 * @memberof FSBI
		 * @param {string} message Message for the user.
		 * @param {number} status  HTTP status, 0 when there was no response.
		 * @returns {void}
		 */
		failSection: function(message, status) {
			const section = this.tabLoading || this.currentTab || 'overview';
			this.showSectionError(section, message);
			this.tabLoading = null;
			if (window.console && console.warn) {
				console.warn('[RL FSBI] action=rl_fsbi_get_dashboard_data section=' + section + ' status=' + status);
			}
		},

		/**
		 * Report a failed slice inside its own panel, with a Retry button.
		 *
		 * One failing request must never blank the whole dashboard: the other
		 * tabs keep working and the user can retry this one.
		 *
		 * @function showSectionError
		 * @memberof FSBI
		 * @param {string} section Tab slug.
		 * @param {string} message Message to show.
		 * @returns {void}
		 */
		showSectionError: function(section, message) {
			const panel = document.querySelector('.rl-fsbi-panel[data-panel="' + section + '"]');
			if (!panel) {
				return;
			}

			this.clearSectionSkeleton(section);
			panel.querySelectorAll('.rl-fsbi-section-error').forEach((el) => el.remove());

			const box = document.createElement('div');
			box.className = 'rl-fsbi-section-error';
			box.setAttribute('role', 'alert');

			const text = document.createElement('p');
			text.className = 'rl-fsbi-section-error-text';
			text.textContent = message;

			const retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'button';
			retry.textContent = window.rlFsbiAdmin?.i18nRetry || 'Retry';
			retry.addEventListener('click', () => {
				box.remove();
				panel.classList.remove('is-failed');
				this.loadSection(section);
			});

			box.appendChild(text);
			box.appendChild(retry);
			panel.insertBefore(box, panel.firstChild);
			// Stale figures under an error read as "this is the current data".
			panel.classList.add('is-failed');
		},

		/**
		 * Drop every cached slice and mark the other tabs stale.
		 *
		 * A filter change makes all cached payloads wrong, but only the active
		 * tab is refetched: the others reload when next opened, which keeps a
		 * date change to one request instead of one per tab.
		 *
		 * @function invalidateSections
		 * @memberof FSBI
		 * @returns {void}
		 */
		invalidateSections: function() {
			this.sectionCache = {};
			const active = this.currentTab || 'overview';
			document.querySelectorAll('.rl-fsbi-tab').forEach((tab) => {
				tab.classList.toggle('is-stale', tab.getAttribute('data-tab') !== active);
			});
		},

		/**
		 * Signature of everything that changes a payload.
		 *
		 * Two loads with the same signature can share a cached slice; anything
		 * else invalidates it.
		 *
		 * @function filterSignature
		 * @memberof FSBI
		 * @returns {string}
		 */
		filterSignature: function() {
			const f = this.getFilters();
			return [f.plugin_id, f.currency, f.plan_id, f.start_date, f.end_date].join('|');
		},

		/**
		 * Cached payload for the active tab, or null when absent or stale.
		 *
		 * @function cachedSection
		 * @memberof FSBI
		 * @param {string} section Tab slug.
		 * @returns {Object|null}
		 */
		cachedSection: function(section) {
			const key = section + '::' + this.filterSignature();
			const hit = this.sectionCache && this.sectionCache[key];
			return hit || null;
		},

		loadData: function() {
			const self = this;
			const filters = this.getFilters();

			// The overlay earns its keep once, while the dashboard is still empty.
			// Afterwards the existing figures stay on screen and simply update,
			// which is faster to read than a full-page spinner.
			if (!this.hasRenderedData) {
				this.showLoading(this.loadingText('building'));
			} else {
				this.markRefreshing(true);
			}
			this.updateSalesActivityTitle(filters);

			// A request that never answers would leave the overlay spinning for
			// ever, so it is abandoned and reported instead.
			const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
			const timeoutId = controller
				? window.setTimeout(() => controller.abort(), 45000)
				: null;

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				signal: controller ? controller.signal : undefined,
				body: new URLSearchParams({
					action: 'rl_fsbi_get_dashboard_data',
					nonce: rlFsbiAdmin.nonce,
					section: filters.section,
					...filters,
				}),
			})
			.then((response) => response.text().then((raw) => ({ response: response, raw: raw })))
			.then((result) => self.handleDashboardResponse(result.response, result.raw))
			.catch((error) => {
				console.error('Error loading dashboard data:', error);
				self.hideLoading();

				if (error && 'AbortError' === error.name) {
					self.failSection('This section timed out after 45 seconds. Retry, and check the server error log if it keeps happening.', 0);
					return;
				}

				self.failSection('Could not reach the server. Retry, and check the server error log if it keeps happening.', 0);
			})
			.finally(() => {
				if (null !== timeoutId) {
					window.clearTimeout(timeoutId);
				}
			});
		},

		/**
		 * Report why a dashboard request failed, in terms the user can act on.
		 *
		 * WordPress answers a rejected nonce with a bare `0` or `-1` and a 4xx
		 * status. Both are valid JSON values, so a naive `res.success` check
		 * reports them as a generic data failure and hides the real cause: the
		 * session expired and the page needs a reload.
		 *
		 * @function handleDashboardResponse
		 * @memberof FSBI
		 * @param {Response} response - Fetch response.
		 * @param {string}   raw      - Response body as text.
		 * @returns {void}
		 */
		handleDashboardResponse: function(response, raw) {
			let payload = null;

			try {
				payload = JSON.parse(raw);
			} catch (error) {
				payload = null;
			}

			const isObject = payload !== null && typeof payload === 'object' && !Array.isArray(payload);

			if (!response.ok || !isObject) {
				this.hideLoading();

				if (response.status === 400 || response.status === 401 || response.status === 403) {
					if (window.confirm('Your WordPress session has expired, so the dashboard could not refresh. Reload the page now?')) {
						window.location.reload();
					}
					return;
				}

				console.error('[RL FSBI] dashboard request failed', response.status, String(raw).slice(0, 500));
				this.failSection('Could not load this section (HTTP ' + response.status + '). The rest of the dashboard still works.', response.status);
				return;
			}

			if (!payload.success) {
				this.hideLoading();
				this.failSection(payload.data || 'The server rejected the request.', 0);
				return;
			}

			// Remember which tab this answered so its panel stops showing a
			// skeleton and the slice can be reused instead of refetched.
			const answered = (payload.data && payload.data.sections && payload.data.sections[0]) || this.tabLoading || 'overview';
			if (payload.data && typeof payload.data === 'object') {
				this.sectionCache = this.sectionCache || {};
				this.sectionCache[answered + '::' + this.filterSignature()] = payload.data;
			}
			this.clearSectionSkeleton(answered);
			document.querySelector('.rl-fsbi-panel[data-panel="' + answered + '"]')?.classList.remove('is-failed');
			document.querySelector('.rl-fsbi-tab[data-tab="' + answered + '"]')?.classList.remove('is-stale');
			this.tabLoading = null;

			if (this.hasRenderedData) {
				this.markRefreshing(false);
			} else {
				this.setLoadingProgress(88, this.loadingText('rendering'));
			}

			this.renderDashboard(payload.data || {});
			this.hasRenderedData = true;
			this.hideLoading();
		},

		/**
		 * Orchestrate rendering of all dashboard UI sections from data payload.
		 *
		 * @function renderDashboard
		 * @memberof FSBI
		 * @param {Object} data - Full backend analytics data payload.
		 * @returns {void}
		 */
		renderDashboard: function(data) {
			this.currentReportCurrency = data.report_currency || data?.summary?.report_currency || null;
			this.syncDateInputs(data.period || {});
			this.updateOverview(data);
			this.updatePageHeader(data);
			this.updateTopCards(data);
			this.updatePlanFilter(data);
			this.updateGrowthKpi(data);
			this.updateBlocks(data);
			this.updateMiniKpis(data);
			this.updateMiniStats(data);
			this.updateTrialPanel(data);
			this.updateCharts(data);
			this.updateMonthlyTable(data);
		},

		/**
		 * Show the period the server actually used in the toolbar.
		 *
		 * Blank date inputs read as "no range set" and left the label showing
		 * nothing but the dash between two empty fields.
		 *
		 * @function syncDateInputs
		 * @memberof FSBI
		 * @param {Object} period - { start, end } as resolved by the backend.
		 * @returns {void}
		 */
		syncDateInputs: function(period) {
			const start = document.getElementById('rl-fsbi-start-date');
			const end = document.getElementById('rl-fsbi-end-date');

			if (start && !start.value && period.start) {
				start.value = period.start;
			}
			if (end && !end.value && period.end) {
				end.value = period.end;
			}
		},

		/**
		 * Update active plugin title and version badge in the dashboard header.
		 *
		 * @function updatePageHeader
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing plugin_title and plugin_version.
		 * @returns {void}
		 */
		updatePageHeader: function(data) {
			if (data.plugin_title) {
				this.setText('rl-fsbi-active-plugin-title', data.plugin_title);
			}

			const versionWrap = document.getElementById('rl-fsbi-active-plugin-version');
			const versionVal = document.getElementById('rl-fsbi-active-plugin-version-val');

			if (versionWrap && versionVal) {
				const isAllPlugins = !data.plugin_id || data.plugin_id === 'all' || Number(data.plugin_id) === 0;
				if (!isAllPlugins && data.plugin_version) {
					versionVal.textContent = data.plugin_version;
					versionWrap.style.display = 'inline-flex';
				} else {
					versionWrap.style.display = 'none';
				}
			}
		},

		/**
		 * Update top KPI summary cards (Net Revenue, Expected Payout, Refunds Breakdown).
		 *
		 * @function updateTopCards
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing summary object.
		 * @returns {void}
		 */
		updateTopCards: function(data) {
			const currency = this.getDisplayCurrency();
			const summary = data.summary || {};

			const fxNote = document.getElementById('rl-fsbi-fx-note');
			if (fxNote) {
				fxNote.hidden = !summary.fx_converted;
			}
			this.setText('rl-fsbi-expected-payout', this.formatCurrency(Number(summary.expected_payout || 0), currency));
			this.setText('rl-fsbi-payout-note', summary.payout_note || 'Based on current MRR pace');

			if (Number(summary.refunds_count || 0) === 0) {
				this.setText('rl-fsbi-refunds-breakdown', 'No refunds');
				this.setText('rl-fsbi-refunds-sub', 'Healthy retention signal');
			} else {
				this.setText('rl-fsbi-refunds-breakdown', this.formatCurrency(Number(summary.refunds_total || 0), currency));
				this.setText('rl-fsbi-refunds-sub', `${Number(summary.refunds_count || 0)} refunds in period`);
			}
		},

		/**
		 * Write the live Health Score breakdown into its tooltips.
		 *
		 * @function updateHealthTooltip
		 * @memberof FSBI
		 * @param {Object} kpis - KPI payload.
		 * @returns {void}
		 */
		updateHealthTooltip: function(kpis) {
			const raw = kpis.health_criteria;
			const criteria = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' ? Object.values(raw) : []);
			if (!criteria.length) {
				return;
			}

			const available = criteria.reduce((sum, c) => sum + Number(c.max || 0), 0);
			const scaled = available > 0 && available !== 100;

			const rows = criteria.map((c) => {
				const pct = c.max > 0 ? Math.round((Number(c.score) / Number(c.max)) * 100) : 0;
				return `<div style="margin-bottom:4px;">• <strong>${c.label} (${c.max} pts):</strong> ${c.score} pts (${pct}%)</div>`;
			}).join('');

			const note = scaled
				? `<div style="margin-top:6px;font-size:11px;opacity:.85;">No trials in this period, so Trial Conversion is excluded and the remaining ${available} points are scaled to 100.</div>`
				: '';

			const html = `<div style="text-align:left;line-height:1.45;font-size:12px;padding:2px 4px;"><strong style="display:block;margin-bottom:6px;border-bottom:1px solid rgba(0,0,0,0.12);padding-bottom:4px;">Health Score (0–100) Breakdown</strong>${rows}${note}</div>`;

			document.querySelectorAll('.rl-fsbi-health-tooltip').forEach((el) => {
				el.setAttribute('data-tippy-content', html);
				if (el._tippy) {
					el._tippy.setContent(html);
				}
			});
		},

		/**
		 * Update mini-KPI cards and Portfolio Performance metrics.
		 *
		 * @function updateMiniKpis
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing kpis and summary objects.
		 * @returns {void}
		 */
		/**
		 * Overview tab: five KPI cards, the attention list and the MRR waterfall.
		 *
		 * Everything is read from the payload the overview slice already carries;
		 * a figure that is not in it shows an em dash rather than a zero, so a
		 * missing number never looks like a real one.
		 *
		 * @function updateOverview
		 * @memberof FSBI
		 * @param {Object} data Analytics payload.
		 * @returns {void}
		 */
		updateOverview: function(data) {
			const currency = this.getDisplayCurrency();
			const kpis = data.kpis || {};
			const summary = data.summary || {};
			// New subscriptions are reported under blocks.acquisition; the target
			// and its monthly series come from growth.growth (the new-subscription
			// series built for the growth tab).
			const acq = (data.blocks && data.blocks.acquisition) || {};
			const seriesData = data.growth || {};
			const subs = {
				new: Number(seriesData.current_new ?? acq.new_customers ?? 0),
				reactivations: Number(seriesData.current_reactivation ?? acq.reactivations ?? 0),
				target: Number(seriesData.target || 0),
			};
			const extra = data.overview || {};
			const money = (v) => this.formatCurrency(Number(v || 0), currency);

			const setKpi = (id, value, deltaText, deltaTone) => {
				this.setText('rl-fsbi-kpi-' + id, value);
				const delta = document.getElementById('rl-fsbi-kpi-' + id + '-delta');
				if (!delta) {
					return;
				}
				delta.textContent = deltaText || '';
				delta.className = 'rl-fsbi-kpi-delta' + (deltaTone ? ' is-' + deltaTone : '');
			};

			// New subscriptions vs target, with the pace expected by today.
			const newCount = Number(subs.new || 0);
			const target = Number(subs.target || 0);
			let paceText = '';
			if (target > 0) {
				const day = Number(data.overview?.day_of_month || new Date().getDate());
				const days = Number(data.overview?.days_in_month || 30);
				const expected = (target * day) / Math.max(1, days);
				const onTrack = newCount >= expected;
				paceText = onTrack
					? 'on track · ' + newCount + '/' + target
					: 'behind · ' + newCount + '/' + target;
				setKpi('newsubs', String(newCount), paceText, onTrack ? 'good' : 'warn');
			} else {
				setKpi('newsubs', String(newCount), Number(subs.reactivations || 0) + ' reactivations', 'neutral');
			}

			setKpi('mrr', money(kpis.mrr_converted ?? kpis.mrr), '', 'neutral');

			const net = Number(summary.net_revenue || 0);
			const comparison = extra.comparison || {};
			// A partial period is compared with the same day-span of the previous
			// month, never with a whole month: eleven days against thirty-one makes
			// any figure look like a collapse.
			const prev = comparison.partial
				? Number(comparison.net_previous || 0)
				: Number(summary.previous_net_revenue || 0);
			const basis = comparison.partial && comparison.label ? ' ' + comparison.label : ' vs previous period';

			if (prev > 0) {
				const change = ((net - prev) / prev) * 100;
				setKpi('net', money(net), (change >= 0 ? '▲ ' : '▼ ') + Math.abs(change).toFixed(1) + '%' + basis, change >= 0 ? 'good' : 'bad');
			} else if (prev === 0) {
				setKpi('net', money(net), 'no revenue in ' + (comparison.label || 'the previous period'), 'neutral');
			} else {
				setKpi('net', money(net), '', 'neutral');
			}

			const churn = Number(kpis.churn_rate || 0);
			setKpi('churn', this.formatPercent(churn, 1), churn < 3 ? 'within target' : 'above 3% target', churn < 3 ? 'good' : 'bad');

			const trialsStarted = Number(extra.trials_started || 0);
			const trialsConverted = Number(extra.trials_converted || 0);
			if (!trialsStarted) {
				// 0% and "no trials" are different facts; showing 0.0% implies a
				// conversion problem where there were simply no trials.
				setKpi('trialpaid', 'No trials', '', 'neutral');
			} else {
				const rate = (trialsConverted / trialsStarted) * 100;
				setKpi('trialpaid', this.formatPercent(rate, 1), trialsConverted + ' of ' + trialsStarted + ' converted', 'neutral');
			}

			// The sticky header's height depends on how the filters wrap, so the
			// scroll offset is measured rather than guessed.
			this.measureStickyHeader();

			this.bindDetectionRun();
			this.renderRecentMonths(extra);
			this.renderActivity(extra.recent_activity || []);

			this.renderAttention(extra.attention || []);
			this.renderWaterfall(extra.waterfall || {}, currency);

			// One sparkline per card, each drawn from the series its figure uses.
			const sparks = extra.series && !Array.isArray(extra.series) ? extra.series : { net: extra.series || [] };
			['newsubs', 'mrr', 'net', 'churn', 'trialpaid'].forEach((key) => {
				this.renderSparkline(key, sparks[key] || []);
			});
		},

		/**
		 * The compact three-month table, the next payout and what renews next.
		 *
		 * @function renderRecentMonths
		 * @memberof FSBI
		 * @param {Object} extra Overview payload.
		 * @returns {void}
		 */
		renderRecentMonths: function(extra) {
			const body = document.getElementById('rl-fsbi-recent-body');
			const currency = this.getDisplayCurrency();
			const months = Array.isArray(extra.recent_months) ? extra.recent_months : [];
			const labels = { settled: 'Settled', current: 'Current', next: 'Next', future: 'Not yet' };

			if (body) {
				body.innerHTML = months.length
					? months.map((month) => {
						const status = labels[month.payout_status] || labels.settled;
						const payout = Number(month.payout_total || 0) > 0
							? this.formatCurrency(Number(month.payout_total || 0), currency)
							: '—';
						return `<tr>
							<td>${month.period || month.month}</td>
							<td>${this.formatCurrency(Number(month.gross || 0), currency)}</td>
							<td>${this.formatCurrency(Number(month.net || 0), currency)}</td>
							<td><span class="rl-fsbi-payout-status is-${month.payout_status || 'settled'}">${status}</span> ${payout}</td>
						</tr>`;
					}).join('')
					: '<tr><td colspan="4">No payments in the last three months.</td></tr>';
			}

			const nextPayout = document.getElementById('rl-fsbi-next-payout');
			if (nextPayout) {
				const payout = extra.next_payout || {};
				nextPayout.textContent = Number(payout.amount || 0) > 0
					? `Next payout ${this.formatCurrency(Number(payout.amount || 0), currency)}${payout.date ? ' on ' + payout.date : ''}`
					: 'No payout scheduled yet';
			}

			const renewals = document.getElementById('rl-fsbi-renewals-due');
			if (renewals) {
				const due = extra.renewals_due || {};
				const count = Number(due.count || 0);
				renewals.textContent = count > 0
					? `${count} renewal${count === 1 ? '' : 's'} due in 30 days · ${this.formatCurrency(Number(due.amount || 0), currency)}`
					: 'No renewals due in 30 days';
			}
		},

		/**
		 * The last five payments, labelled the way the waterfall labels them.
		 *
		 * @function renderActivity
		 * @memberof FSBI
		 * @param {Array} items Activity entries.
		 * @returns {void}
		 */
		renderActivity: function(items) {
			const list = document.getElementById('rl-fsbi-activity-list');
			if (!list) {
				return;
			}
			const currency = this.getDisplayCurrency();
			const names = { new: 'New', renewal: 'Renewal', reactivation: 'Reactivation' };

			list.innerHTML = items.length
				? items.map((item) => `<li>
						<span class="rl-fsbi-activity-date">${item.date || ''}</span>
						<span class="rl-fsbi-activity-country">${item.country || '—'}</span>
						<span class="rl-fsbi-activity-kind is-${item.kind}">${names[item.kind] || item.kind}</span>
						<strong class="rl-fsbi-activity-amount">${this.formatCurrency(Number(item.amount || 0), currency)}</strong>
					</li>`).join('')
				: '<li class="rl-fsbi-activity-empty">No payments in this period.</li>';
		},

		/**
		 * Draw the attention list, most urgent first, capped at five.
		 *
		 * @function renderAttention
		 * @memberof FSBI
		 * @param {Array} items Attention entries.
		 * @returns {void}
		 */
		/**
		 * Publish the sticky header height so scroll targets can clear it.
		 *
		 * @function measureStickyHeader
		 * @memberof FSBI
		 * @returns {void}
		 */
		measureStickyHeader: function() {
			const bar = document.querySelector('.rl-fsbi-toolbar');
			if (!bar) {
				return;
			}
			document.documentElement.style.setProperty('--rl-fsbi-sticky-h', Math.round(bar.getBoundingClientRect().height) + 'px');

			if (!this.stickyHeaderBound) {
				this.stickyHeaderBound = true;
				window.addEventListener('resize', () => {
					const el = document.querySelector('.rl-fsbi-toolbar');
					if (el) {
						document.documentElement.style.setProperty('--rl-fsbi-sticky-h', Math.round(el.getBoundingClientRect().height) + 'px');
					}
				});
			}
		},

		renderAttention: function(items) {
			const box = document.getElementById('rl-fsbi-attention');
			const list = document.getElementById('rl-fsbi-attention-list');
			if (!box || !list) {
				return;
			}

			list.textContent = '';

			if (!Array.isArray(items) || !items.length) {
				box.hidden = true;
				return;
			}

			items.slice(0, 5).forEach((item) => {
				const li = document.createElement('li');
				li.className = 'rl-fsbi-attention-item is-' + (item.severity || 'medium');
				const icon = document.createElement('span');
				icon.className = 'dashicons ' + ('high' === item.severity ? 'dashicons-warning' : 'dashicons-info-outline');
				icon.setAttribute('aria-hidden', 'true');
				const link = document.createElement('a');
				link.href = '#' + String(item.hash || 'overview').replace(/^#/, '');
				link.textContent = Number(item.count).toLocaleString() + ' ' + item.label;
				li.appendChild(icon);
				li.appendChild(link);
				list.appendChild(li);
			});

			box.hidden = false;
		},

		/**
		 * MRR movement: new, reactivation, churn, then the net change.
		 *
		 * @function renderWaterfall
		 * @memberof FSBI
		 * @param {Object} wf     Waterfall values.
		 * @param {string} currency Currency code.
		 * @returns {void}
		 */
		renderWaterfall: function(wf, currency) {
			const host = document.getElementById('rl-fsbi-waterfall');
			if (!host) {
				return;
			}

			host.textContent = '';
			const bars = [
				{ key: 'new', label: 'New', tone: 'good' },
				{ key: 'reactivation', label: 'Reactivation', tone: 'good' },
				{ key: 'churn', label: 'Churn', tone: 'bad' },
				{ key: 'net', label: 'Net change', tone: 'neutral' },
			];
			const max = Math.max(1, ...bars.map((b) => Math.abs(Number(wf[b.key] || 0))));

			bars.forEach((bar) => {
				const value = Number(wf[bar.key] || 0);
				const row = document.createElement('div');
				row.className = 'rl-fsbi-waterfall-row is-' + bar.tone;

				const label = document.createElement('span');
				label.className = 'rl-fsbi-waterfall-label';
				label.textContent = bar.label;

				const track = document.createElement('span');
				track.className = 'rl-fsbi-waterfall-track';
				const fill = document.createElement('span');
				fill.className = 'rl-fsbi-waterfall-fill';
				fill.style.width = Math.max(2, Math.round((Math.abs(value) / max) * 100)) + '%';
				track.appendChild(fill);

				const amount = document.createElement('span');
				amount.className = 'rl-fsbi-waterfall-value';
				amount.textContent = (value < 0 ? '−' : '') + this.formatCurrency(Math.abs(value), currency);

				row.appendChild(label);
				row.appendChild(track);
				row.appendChild(amount);
				host.appendChild(row);
			});
		},

		/**
		 * Tiny line chart for one KPI card.
		 *
		 * @function renderSparkline
		 * @memberof FSBI
		 * @param {string} id      KPI id.
		 * @param {Array}  series  Points of {label, value}.
		 * @returns {void}
		 */
		/**
		 * Run detection with a live readout instead of a silent wait.
		 *
		 * A full payment history pull can take a minute; the page used to sit
		 * there with no sign that anything was happening.
		 *
		 * @function bindDetectionRun
		 * @memberof FSBI
		 * @returns {void}
		 */
		bindDetectionRun: function() {
			const button = document.querySelector('[data-detection-run]');
			if (!button || this.detectionBound) {
				return;
			}
			this.detectionBound = true;

			button.addEventListener('click', (event) => {
				event.preventDefault();

				const notice = button.closest('.rl-fsbi-detection-notice');
				const status = notice ? notice.querySelector('[data-detection-status]') : null;
				const text = notice ? notice.querySelector('[data-detection-text]') : null;
				const started = Date.now();

				button.setAttribute('aria-disabled', 'true');
				button.style.pointerEvents = 'none';
				button.textContent = 'Detecting…';
				if (status) {
					status.hidden = false;
				}
				if (text) {
					text.textContent = 'Reading your payment history…';
				}

				const format = (seconds) => Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');

				const poll = window.setInterval(async () => {
					const elapsed = Math.round((Date.now() - started) / 1000);
					try {
						const body = new URLSearchParams({ action: 'rl_fsbi_detection_progress', nonce: rlFsbiAdmin.nonce });
						const response = await fetch(rlFsbiAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
						const json = await response.json();
						const data = (json && json.data) || {};
						if (text) {
							const fetched = Number(data.fetched || 0).toLocaleString();
							text.textContent = data.stage === 'payments'
								? `Reading payments for product ${data.plugin_id || ''} — ${fetched} so far (${format(elapsed)})`
								: `Reading your payment history… (${format(elapsed)})`;
						}
					} catch (e) {
						if (text) {
							text.textContent = `Reading your payment history… (${format(elapsed)})`;
						}
					}
				}, 1500);

				// The request that does the work runs alongside the polling.
				fetch(button.getAttribute('href'), { credentials: 'same-origin' })
					.then((response) => {
						// A timeout on the host comes back as a 5xx: say so rather
						// than reloading into the same empty notice.
						if (!response.ok) {
							throw new Error('detection failed');
						}
					})
					.then(() => {
						window.clearInterval(poll);
						if (text) {
							text.textContent = 'Done. Reloading…';
						}
						window.location.reload();
					})
					.catch(() => {
						window.clearInterval(poll);
						if (text) {
							text.textContent = 'Detection did not finish. Try again, or set the values manually.';
						}
						button.textContent = 'Run detection';
						button.style.pointerEvents = '';
					});
			});
		},

		renderSparkline: function(id, series) {
			const canvas = document.getElementById('rl-fsbi-kpi-' + id + '-spark');
			if (!canvas) {
				return;
			}

			// No series means no band: an empty canvas reserved a strip of the card
			// that looked like a broken image.
			const wrap = canvas.parentElement;
			const hasSeries = Array.isArray(series) && series.length > 0 && !!window.Chart;
			if (wrap) {
				wrap.hidden = !hasSeries;
			}
			if (!hasSeries) {
				return;
			}

			if (this.charts['kpi_' + id]) {
				this.charts['kpi_' + id].destroy();
			}

			// The canvas is sized by its wrapper in CSS. Left to measure itself, a
			// chart built while the panel is still hidden takes height:100% of a
			// card that is then growing to fit it, and the two feed each other.
			this.charts['kpi_' + id] = new Chart(canvas, {
				type: 'line',
				data: {
					labels: series.map((p) => p.label),
					datasets: [{
						// A missing month is not a zero: charting it as one draws a
						// flat line where there is no data at all.
						data: series.map((p) => (p.value === null || p.value === undefined ? null : Number(p.value))),
						borderColor: '#5f6bff',
						borderWidth: 1.5,
						pointRadius: 0,
						fill: false,
						tension: 0.35,
					}],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false }, tooltip: { enabled: false } },
					scales: { x: { display: false }, y: { display: false } },
					animation: false,
				},
			});
		},

		updateMiniKpis: function(data) {
			const currency = this.getDisplayCurrency();
			const kpis = data.kpis || {};
			const mrr = Number(kpis.mrr_converted ?? kpis.mrr ?? 0);
			const arr = Number(kpis.arr_converted ?? kpis.arr ?? (mrr * 12));

			// MRR and Churn rate are their own KPI cards now, so the mini grid
			// carries only what those cards do not.
			this.setText('rl-fsbi-arr', this.formatCurrency(arr, currency));
			// "as of 11 Nov 2026" reads as a date; the raw payload timestamp does not.
			const asOfRaw = String(kpis.metric_as_of || '');
			const asOfDate = asOfRaw ? new Date(asOfRaw.replace(' ', 'T')) : null;
			const asOfText = asOfDate && !Number.isNaN(asOfDate.getTime())
				? asOfDate.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
				: 'the selected period end';
			this.setText('rl-fsbi-arr-as-of', `as of ${asOfText}`);
			this.setText('rl-fsbi-aov', kpis.aov === null || kpis.aov === undefined
				? '—'
				: this.formatCurrency(Number(kpis.aov), currency));
			this.setText('rl-fsbi-refund-rate', this.formatPercent(Number(kpis.refund_rate || 0), 1));
			this.setText('rl-fsbi-health-score', String(Math.round(Number(kpis.health_score || 0))));
			this.updateHealthTooltip(kpis);
		},

		/**
		 * Update mini-stat grid counts (Purchases, Trials/Conversions, Refunds, Renewals).
		 *
		 * @function updateMiniStats
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing stats object.
		 * @returns {void}
		 */
		updateMiniStats: function(data) {
			const stats = data.stats || {};
			this.setText('rl-fsbi-stat-purchases', Number(stats.purchases || 0).toLocaleString());
			this.setText('rl-fsbi-stat-trials-conversions', `${Number(stats.trials || 0)}/${Number(stats.conversions || 0)}`);
			this.setText('rl-fsbi-stat-refunds', Number(stats.refunds || 0).toLocaleString());
			this.setText('rl-fsbi-stat-renewals', Number(stats.renewals || 0).toLocaleString());
			if (data.marketing_optins !== undefined) {
				this.setText('rl-fsbi-stat-marketing-optins', Number(data.marketing_optins || 0).toLocaleString());
			}
		},

		/**
		 * Update trial conversion panel figures and percentage.
		 *
		 * @function updateTrialPanel
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing trial_conversion object.
		 * @returns {void}
		 */
		updateTrialPanel: function(data) {
			const trial = data.trial_conversion || {};
			const funnel = data.trial_funnel || {};
			const started = Number(funnel.started ?? trial.total_trials ?? 0);
			const withMethod = Number(funnel.with_payment_method || 0);
			const converted = Number(funnel.converted ?? trial.converted ?? 0);
			const canceled = Number(funnel.canceled || 0);
			const rate = Number(funnel.rate ?? trial.rate ?? 0);

			this.setText('rl-fsbi-trials-total', started.toLocaleString());
			this.setText('rl-fsbi-trials-with-method', withMethod.toLocaleString());
			this.setText('rl-fsbi-trials-converted', converted.toLocaleString());
			this.setText('rl-fsbi-trials-canceled', canceled.toLocaleString());
			this.setText('rl-fsbi-trials-rate', this.formatPercent(rate, 1));

			const sourceEl = document.getElementById('rl-fsbi-trials-source');
			if (sourceEl) {
				const isLive = funnel.source === 'trials';
				sourceEl.textContent = isLive
					? 'Live trial records'
					: 'Estimated from subscriptions';
				sourceEl.className = 'rl-fsbi-data-source rl-fsbi-data-source--' + (isLive ? 'live' : 'estimated');
			}

			this.renderTrialFunnel([
				{ label: 'Started', value: started },
				{ label: 'With payment method', value: withMethod },
				{ label: 'Converted', value: converted },
				{ label: 'Canceled', value: canceled },
			]);

			this.renderChurnReasons(data.churn_reasons || [], Number(data.uninstalls || 0));
		},

		/**
		 * Render the trial funnel bars, scaled to the started count.
		 *
		 * @function renderTrialFunnel
		 * @memberof FSBI
		 * @param {Array<{label: string, value: number}>} stages - Funnel stages.
		 * @returns {void}
		 */
		renderTrialFunnel: function(stages) {
			const container = document.getElementById('rl-fsbi-trial-funnel');
			if (!container) return;

			const max = stages.reduce((acc, stage) => Math.max(acc, Number(stage.value) || 0), 0);

			container.textContent = '';

			stages.forEach(function(stage, index) {
				const value = Number(stage.value) || 0;
				const percent = max > 0 ? Math.round((value / max) * 100) : 0;

				const row = document.createElement('div');
				row.className = 'rl-fsbi-funnel-row';

				const label = document.createElement('span');
				label.className = 'rl-fsbi-funnel-label';
				label.textContent = stage.label;

				const track = document.createElement('span');
				track.className = 'rl-fsbi-funnel-track';

				const fill = document.createElement('span');
				fill.className = 'rl-fsbi-funnel-fill rl-fsbi-funnel-fill--' + index;
				fill.style.width = percent + '%';
				track.appendChild(fill);

				const amount = document.createElement('strong');
				amount.className = 'rl-fsbi-funnel-value';
				amount.textContent = value.toLocaleString();

				row.appendChild(label);
				row.appendChild(track);
				row.appendChild(amount);
				container.appendChild(row);
			});
		},

		/**
		 * Render the uninstall reason list with proportional bars.
		 *
		 * @function renderChurnReasons
		 * @memberof FSBI
		 * @param {Array<{reason_id: number, count: number, reason_info: string}>} reasons - Grouped reasons.
		 * @param {number} uninstallTotal - Uninstalls recorded in the selected period.
		 * @returns {void}
		 */
		renderChurnReasons: function(reasons, uninstallTotal) {
			this.churnReasons = Array.isArray(reasons) ? reasons : [];
			this.uninstallTotal = Number(uninstallTotal) || 0;
			this.paintChurnReasons();
		},

		/**
		 * Count for one reason within the active customer-type segment.
		 *
		 * @function churnSegmentCount
		 * @memberof FSBI
		 * @param {Object} item - Reason row with free/trial/paid counts.
		 * @returns {number}
		 */
		churnSegmentCount: function(item) {
			if ('all' === this.churnSegment) {
				return Number(item.count) || 0;
			}

			return Number(item[this.churnSegment]) || 0;
		},

		/**
		 * Repaint the reason list for the active segment.
		 *
		 * Filtering happens client-side so switching is instant: the payload
		 * already carries a count per segment.
		 *
		 * @function paintChurnReasons
		 * @memberof FSBI
		 * @returns {void}
		 */
		paintChurnReasons: function() {
			const list = document.getElementById('rl-fsbi-churn-reasons');
			if (!list) return;

			const segment = this.churnSegment || 'free';
			const segmentLabels = { free: 'free', trial: 'trial', paid: 'paying', all: 'any' };
			const segmentScope = {
				free: 'Free plan installs that were uninstalled, with the reason the user picked.',
				trial: 'Users who uninstalled during or after a trial.',
				paid: 'Paying customers who cancelled.',
				all: 'Every uninstall and cancellation on record.',
			};

			const scope = document.getElementById('rl-fsbi-churn-scope');
			if (scope) {
				scope.textContent = segmentScope[segment] || segmentScope.all;
			}
			const uninstallTotal = Number(this.uninstallTotal) || 0;
			const source = Array.isArray(this.churnReasons) ? this.churnReasons : [];

			const items = source.map((item) => {
				return Object.assign({}, item, { count: this.churnSegmentCount(item) });
			}).filter(function(item) {
				return Number(item.count) > 0;
			});

			list.textContent = '';

			if (!items.length) {
				const empty = document.createElement('li');
				empty.className = 'rl-fsbi-churn-empty';
				empty.textContent = uninstallTotal > 0
					? 'No uninstalls recorded for ' + (segmentLabels[segment] || segment) + ' users in this period.'
					: 'No uninstall feedback stored yet. Run a sync to collect it.';
				list.appendChild(empty);
				return;
			}

			const max = items.reduce(function(acc, item) {
				return Math.max(acc, Number(item.count) || 0);
			}, 0);

			items.forEach(function(item) {
				const count = Number(item.count) || 0;
				const info = String(item.reason_info || '').trim();
				const reasonLabel = String(item.reason_label || '').trim();
				let label = reasonLabel || info;

				if (!label) {
					label = Number(item.reason_id) > 0
						? 'Reason #' + Number(item.reason_id)
						: 'No reason provided';
				}

				const row = document.createElement('li');
				row.className = 'rl-fsbi-churn-reason';

				const text = document.createElement('span');
				text.className = 'rl-fsbi-churn-reason-label';
				text.textContent = label;

				const bar = document.createElement('span');
				bar.className = 'rl-fsbi-churn-reason-bar';
				bar.style.width = (max > 0 ? Math.round((count / max) * 100) : 0) + '%';

				const value = document.createElement('strong');
				value.className = 'rl-fsbi-churn-reason-count';
				value.textContent = count.toLocaleString();

				row.appendChild(text);
				row.appendChild(bar);
				row.appendChild(value);
				list.appendChild(row);
			});
		},

		/**
		 * Render or update all Chart.js visualizations on the dashboard.
		 *
		 * @function updateCharts
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing charts object.
		 * @returns {void}
		 */
		updateCharts: function(data) {
			const charts = data.charts || {};
			const currency = this.getDisplayCurrency();

			this.drawLineChart('salesActivity', 'rl-fsbi-sales-activity-chart', charts.sales_activity?.labels || [], [
				{ label: 'Purchases', data: charts.sales_activity?.purchases || [], borderColor: '#5f6bff', backgroundColor: 'rgba(95,107,255,0.15)' },
				{ label: 'Renewals', data: charts.sales_activity?.renewals || [], borderColor: '#2aa84a', backgroundColor: 'rgba(42,168,74,0.15)' },
				{ label: 'Trials', data: charts.sales_activity?.trials || [], borderColor: '#22c89b', backgroundColor: 'rgba(34,200,155,0.15)' },
				{ label: 'Refunds', data: charts.sales_activity?.refunds || [], borderColor: '#ff5a72', backgroundColor: 'rgba(255,90,114,0.15)' },
				{ label: 'Conversions', data: charts.sales_activity?.conversions || [], borderColor: '#f4b942', backgroundColor: 'rgba(244,185,66,0.15)' },
			], false);

			this.drawExpectedRenewalsChart(charts.expected_renewals, currency);

			this.drawBarChart('revenueOverview', 'rl-fsbi-revenue-overview-chart', charts.revenue_overview?.labels || [], [
				{ label: 'Gross', data: charts.revenue_overview?.gross || [], backgroundColor: 'rgba(95,107,255,0.75)' },
				{ label: 'Net', data: charts.revenue_overview?.net || [], backgroundColor: 'rgba(34,200,155,0.75)' },
				{ label: 'Refunds', data: charts.revenue_overview?.refunds || [], backgroundColor: 'rgba(255,90,114,0.75)' },
				{ label: 'Fees', data: charts.revenue_overview?.fees || [], backgroundColor: 'rgba(244,185,66,0.75)' },
			], currency);

			this.drawLineChart('portfolio', 'rl-fsbi-portfolio-chart', charts.revenue_overview?.labels || [], [
				{ label: 'Net Revenue', data: charts.revenue_overview?.net || [], borderColor: '#7d8cff', backgroundColor: 'rgba(125,140,255,0.18)' },
			], true);

			this.drawLineChart('forecast', 'rl-fsbi-forecast-chart', charts.revenue_forecast?.labels || [], [
				{ label: 'Forecasted Revenue', data: charts.revenue_forecast?.values || [], borderColor: '#8e5dff', backgroundColor: 'rgba(142,93,255,0.15)' },
			], true);

			this.renderForecastSummary(charts.revenue_forecast || {});

			this.drawComboChart('churn', 'rl-fsbi-churn-chart', charts.churn_trend?.labels || [], charts.churn_trend?.canceled || [], charts.churn_trend?.churn_rate || []);
			this.drawDoughnutChart('currency', 'rl-fsbi-currency-chart', charts.currency_distribution?.labels || [], charts.currency_distribution?.values || [], currency);
			this.drawDoughnutChart('country', 'rl-fsbi-country-chart', charts.country_distribution?.labels || [], charts.country_distribution?.values || [], null, false);
			this.drawDoughnutChart('plan', 'rl-fsbi-plan-chart', charts.plan_distribution?.labels || [], charts.plan_distribution?.values || [], null, false);
			this.drawLineChart('salesvolume', 'rl-fsbi-sales-volume-chart', charts.daily_sales_volume?.labels || [], [
				{ label: 'Payment events', data: charts.daily_sales_volume?.values || [], borderColor: '#22c89b', backgroundColor: 'rgba(34,200,155,0.18)' },
			], true);
		},

		/**
		 * Spoken title for a chart, keyed by the chart id it draws into.
		 *
		 * Screen-reader users get the title before the values, so it says what
		 * the chart plots and where the numbers come from rather than naming a
		 * code path.
		 *
		 * @function chartTitle
		 * @memberof FSBI
		 * @param {string} key - Chart key.
		 * @param {string} canvasId - Canvas element id.
		 * @returns {string}
		 */
		chartTitle: function(key, canvasId) {
			const titles = {
				'rl-fsbi-sales-activity-chart': 'Sales activity: purchases, renewals, trials and refunds per day',
				'rl-fsbi-sales-volume-chart': 'Freemius sales volume: payment events per day over the last 90 days',
				'rl-fsbi-portfolio-chart': 'Net revenue over the last 12 months',
				'rl-fsbi-revenue-overview-chart': 'Revenue overview over the last 12 months',
				'rl-fsbi-forecast-chart': 'Forecast revenue from upcoming renewals over the next 12 months',
				'rl-fsbi-churn-chart': 'Churn trend: cancellations and churn rate per month',
				'rl-fsbi-currency-chart': 'Revenue by billing currency',
				'rl-fsbi-country-chart': 'Revenue by customer country',
				'rl-fsbi-plan-chart': 'Active subscriptions by plan',
				'rl-fsbi-expected-renewals-chart': 'Expected renewals per day for the current month',
			};

			return titles[canvasId] || `${key} chart`;
		},

		/**
		 * Attach an accessible label and a data table to a chart.
		 *
		 * A canvas is opaque to a screen reader, so every chart gets a label and
		 * a table of the same numbers, hidden until asked for.
		 *
		 * @function attachChartTable
		 * @memberof FSBI
		 * @param {string} canvasId - Target canvas element ID.
		 * @param {string} title - Human description of the chart.
		 * @param {Array.<string>} labels - Point labels.
		 * @param {Array.<Object>} series - [{ name, values, format }] where format is 'money' | 'count' | 'percent'.
		 * @returns {void}
		 */
		attachChartTable: function(canvasId, title, labels, series) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !canvas.parentElement) {
				return;
			}

			// A chart wrap has a fixed height, so a holder appended to it paints
			// below the card instead of inside it.
			const card = canvas.closest('.rl-fsbi-card') || canvas.parentElement;
			const currency = this.getDisplayCurrency();
			const formatValue = (value, format) => {
				const n = Number(value || 0);
				if (format === 'money') return this.formatCurrency(n, currency);
				if (format === 'percent') return this.formatPercent(n, 2);
				return n.toLocaleString();
			};

			const summary = labels.map((label, i) => {
				const parts = (series || []).map((s) => `${s.name} ${formatValue((s.values || [])[i], s.format)}`);
				return `${label}: ${parts.join(', ')}`;
			}).join('. ');

			canvas.setAttribute('role', 'img');
			canvas.setAttribute('aria-label', `${title}. ${summary}`);

			// Reuse the wrapper so repeated renders do not stack tables up.
			let holder = card.querySelector(`[data-chart-table="${canvasId}"]`);
			if (!holder) {
				holder = document.createElement('div');
				holder.className = 'rl-fsbi-chart-table-holder';
				holder.setAttribute('data-chart-table', canvasId);

				const toggle = document.createElement('button');
				toggle.type = 'button';
				toggle.className = 'button-link rl-fsbi-chart-table-toggle';
				toggle.textContent = 'View as table';
				toggle.setAttribute('aria-expanded', 'false');
				toggle.setAttribute('aria-controls', `${canvasId}-table`);

				const table = document.createElement('table');
				table.id = `${canvasId}-table`;
				table.className = 'rl-fsbi-chart-table';
				table.hidden = true;

				toggle.addEventListener('click', () => {
					const showing = !table.hidden;
					table.hidden = showing;
					toggle.setAttribute('aria-expanded', showing ? 'false' : 'true');
					toggle.textContent = showing ? 'View as table' : 'Hide table';
				});

				holder.append(toggle, table);
				card.appendChild(holder);
			}

			const table = holder.querySelector('table');
			table.textContent = '';

			const thead = document.createElement('thead');
			const headRow = document.createElement('tr');
			['Point'].concat((series || []).map((s) => s.name)).forEach((heading) => {
				const th = document.createElement('th');
				th.scope = 'col';
				th.textContent = heading;
				headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);

			const tbody = document.createElement('tbody');
			labels.forEach((label, i) => {
				const row = document.createElement('tr');
				const th = document.createElement('th');
				th.scope = 'row';
				th.textContent = String(label);
				row.appendChild(th);

				(series || []).forEach((s) => {
					const td = document.createElement('td');
					td.textContent = formatValue((s.values || [])[i], s.format);
					row.appendChild(td);
				});

				tbody.appendChild(row);
			});
			table.appendChild(tbody);
		},

		/**
		 * Render a responsive line chart using Chart.js.
		 *
		 * @function drawLineChart
		 * @memberof FSBI
		 * @param {string} key - Unique chart instance identifier.
		 * @param {string} canvasId - Target canvas DOM element ID.
		 * @param {Array.<string>} labels - Array of category or date labels.
		 * @param {Array.<LineChartDataset>} datasets - Array of dataset configurations.
		 * @param {boolean} fill - Whether to fill area under curves.
		 * @returns {void}
		 */
		/**
		 * Render the Acquisition / Retention / Revenue blocks.
		 *
		 * @function updateBlocks
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing the blocks object.
		 * @returns {void}
		 */
		updateBlocks: function(data) {
			const blocks = data.blocks || {};
			const acq = blocks.acquisition || {};
			const ret = blocks.retention || {};
			const rev = blocks.revenue || {};
			const currency = this.getDisplayCurrency();
			const count = (v) => Number(v || 0).toLocaleString();
			const money = (v) => this.formatCurrency(Number(v || 0), currency);

			this.setText('rl-fsbi-acq-new', count(acq.new_customers));
			this.setText('rl-fsbi-acq-reactivation', count(acq.reactivations));

			// Free-to-paid and Churn rate are not repeated here: the Trial to paid
			// and Churn rate cards already state them for the same period.
			const origins = acq.origins || {};
			const originLabels = Array.isArray(origins.labels) ? origins.labels : [];
			this.setText(
				'rl-fsbi-acq-origin',
				originLabels.length ? originLabels[0] : 'Unknown'
			);

			this.setText('rl-fsbi-ret-active', count(ret.active));
			this.setText('rl-fsbi-ret-upcoming', count(ret.upcoming_30d));

			const atRisk = Number(ret.at_risk_30d || 0);
			this.setText(
				'rl-fsbi-ret-atrisk',
				atRisk > 0 ? `${count(atRisk)} · ${money(ret.at_risk_amount)}` : count(0)
			);
			this.setText('rl-fsbi-ret-failed', count(ret.failed_payments));
			this.setText('rl-fsbi-ret-cancellations', count(ret.cancellations_this_month));

			this.setText('rl-fsbi-rev-arr', money(rev.arr));
			this.setText('rl-fsbi-rev-gross', money(rev.gross));
			this.setText('rl-fsbi-rev-refunds', money(rev.refunds));
			this.setText('rl-fsbi-rev-fees', money(rev.fees));
			this.setText('rl-fsbi-rev-net', money(rev.net));

			const installs = data.installs || {};
			// Only the Growth slice carries installs. Writing the card from an empty
			// payload is what printed 0 for Premium and 0 for Total before the tab
			// had ever been opened.
			const hasInstalls = Object.keys(installs).length > 0;
			// Freemius reports the live install count on the product; the table only
			// holds records, most of which are historical uninstalls.
			const reportedActive = Number(installs.reported_active || 0);
			const activeInstalls = reportedActive > 0 ? reportedActive : Number(installs.active || 0);
			// All-time installs come from the product; the table's row count is
			// what the crawl reached, not what Freemius has seen. Total is always
			// active + uninstalled so the three numbers add up on screen.
			const reportedTotal = Number(installs.reported_total || 0);
			const tableUninstalled = Number(installs.uninstalled || 0);
			const uninstalled = reportedTotal > activeInstalls
				? reportedTotal - activeInstalls
				: tableUninstalled;
			const total = reportedTotal > 0 ? reportedTotal : activeInstalls + tableUninstalled;
			const premium = Number(installs.reported_premium || 0) > 0
				? Number(installs.reported_premium)
				: Number(installs.premium || 0);
			if (hasInstalls) {
				this.setText('rl-fsbi-installs-active', count(activeInstalls));
				this.setText('rl-fsbi-installs-premium', count(premium));
				this.setText('rl-fsbi-installs-uninstalled', count(uninstalled));
				this.setText('rl-fsbi-installs-total', count(total));
			}

			this.renderCoupons(data.coupons || []);
			this.updateWporgWidget(data.wporg || {});

			const lastSynced = document.getElementById('rl-fsbi-last-synced');
			if (lastSynced) {
				// Short enough to sit on the filter row without pushing the group
				// past the bar's padding.
				const syncedAt = String(data.last_synced || '');
				const syncedDate = syncedAt ? new Date(syncedAt.replace(' ', 'T')) : null;
				lastSynced.textContent = syncedDate && !Number.isNaN(syncedDate.getTime())
					? `Synced ${syncedDate.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })} ${syncedAt.slice(11, 16)}`
					: (syncedAt ? `Synced ${syncedAt}` : 'Never synced');
			}

			// A funnel with nothing in it is a row of zeros pretending to be data.
			const trialPanel = document.querySelector('.rl-fsbi-trial-panel');
			if (trialPanel) {
				const trials = Number((data.trial_conversion || {}).total_trials || 0);
				trialPanel.hidden = trials === 0;
			}
		},

		/**
		 * Render the new-paid-subscriptions target card.
		 *
		 * @function updateGrowthKpi
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing the growth block.
		 * @returns {void}
		 */
		updateGrowthKpi: function(data) {
			const growth = data.growth || {};
			const target = Number(growth.target || 0);
			const current = Number(growth.current_total || 0);
			const reactivation = Number(growth.current_reactivation || 0);

			this.setText('rl-fsbi-newsubs-current', String(current));
			this.setText('rl-fsbi-newsubs-target', target > 0 ? String(target) : '—');

			let note = 'this month vs target';
			if (target <= 0) {
				note = 'no target set';
			} else if (current >= target) {
				note = `target met · ${current - target} over`;
			} else {
				note = `${target - current} to go${reactivation > 0 ? ` · ${reactivation} reactivation${reactivation === 1 ? '' : 's'}` : ''}`;
			}
			this.setText('rl-fsbi-newsubs-note', note);

			const canvas = document.getElementById('rl-fsbi-newsubs-chart');
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts.growth) {
				this.charts.growth.destroy();
			}

			const labels = growth.labels || [];
			const newSeries = growth.new || [];
			const reSeries = growth.reactivation || [];

			if (!labels.length) {
				return;
			}

			this.attachChartTable('rl-fsbi-newsubs-chart', 'New paid subscriptions over the last six months', labels, [
				{ name: 'New customers', values: newSeries, format: 'count' },
				{ name: 'Reactivations', values: reSeries, format: 'count' },
			]);

			this.charts.growth = new Chart(canvas, {
				type: 'bar',
				data: {
					labels: labels.map((l) => String(l).slice(5)),
					datasets: [
						{ label: 'New', data: newSeries, backgroundColor: '#22c89b', borderRadius: 2, barPercentage: 0.7, categoryPercentage: 0.8 },
						{ label: 'Reactivation', data: reSeries, backgroundColor: 'rgba(34,200,155,0.38)', borderRadius: 2, barPercentage: 0.7, categoryPercentage: 0.8 },
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y}`,
							},
						},
					},
					scales: {
						x: { stacked: true, display: false },
						y: { stacked: true, display: false, beginAtZero: true, suggestedMax: Math.max(target, 1) },
					},
				},
			});
		},

		drawLineChart: function(key, canvasId, labels, datasets, fill) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts[key]) {
				this.charts[key].destroy();
			}

			const normalizedDataSets = datasets.map((set) => ({
				label: set.label,
				data: set.data,
				borderColor: set.borderColor,
				backgroundColor: set.backgroundColor,
				borderWidth: 2,
				fill: !!fill,
				tension: 0.3,
				pointRadius: 1.5,
			}));

			const isMoney = key === 'forecast' || key === 'portfolio';

			this.attachChartTable(
				canvasId,
				this.chartTitle(key, canvasId),
				labels,
				datasets.map((set) => ({ name: set.label, values: set.data, format: isMoney ? 'money' : 'count' }))
			);

			this.charts[key] = new Chart(canvas, {
				type: 'line',
				data: {
					labels: labels,
					datasets: normalizedDataSets,
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { labels: { color: '#d7defd' } },
						tooltip: {
							mode: 'index',
							intersect: false,
							callbacks: {
								label: (ctx) => {
									const value = Number(ctx.parsed.y || 0);
									const formatted = isMoney
										? this.formatCurrency(value, this.getDisplayCurrency())
										: value.toLocaleString();
									return `${ctx.dataset.label}: ${formatted}`;
								},
							},
						},
					},
					scales: {
						x: { ticks: { color: '#9ca8d6' }, grid: { color: 'rgba(255,255,255,0.08)' } },
						// Unit counts get integer ticks only, so a low-volume series
						// reads 0/1/2/3 instead of half payments.
						y: {
							beginAtZero: true,
							ticks: { color: '#9ca8d6', maxTicksLimit: 8, precision: isMoney ? undefined : 0 },
							grid: { color: 'rgba(255,255,255,0.08)' },
						},
					},
				},
			});
		},

		/**
		 * Render a responsive bar chart using Chart.js.
		 *
		 * @function drawBarChart
		 * @memberof FSBI
		 * @param {string} key - Unique chart instance identifier.
		 * @param {string} canvasId - Target canvas DOM element ID.
		 * @param {Array.<string>} labels - Array of category labels.
		 * @param {Array.<Object>} datasets - Array of dataset configurations.
		 * @param {string} currency - Active currency code for tooltip formatting.
		 * @returns {void}
		 */
		drawBarChart: function(key, canvasId, labels, datasets, currency) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts[key]) {
				this.charts[key].destroy();
			}

			this.attachChartTable(
				canvasId,
				this.chartTitle(key, canvasId),
				labels,
				datasets.map((set) => ({ name: set.label, values: set.data, format: currency ? 'money' : 'count' }))
			);

			this.charts[key] = new Chart(canvas, {
				type: 'bar',
				data: { labels: labels, datasets: datasets },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { labels: { color: '#d7defd' } },
						tooltip: {
							callbacks: {
								label: (ctx) => `${ctx.dataset.label}: ${this.formatCurrency(ctx.parsed.y || 0, currency)}`,
							},
						},
					},
					scales: {
						x: { ticks: { color: '#9ca8d6' }, grid: { color: 'rgba(255,255,255,0.08)' } },
						y: { beginAtZero: true, ticks: { color: '#9ca8d6' }, grid: { color: 'rgba(255,255,255,0.08)' } },
					},
				},
			});
		},

		/**
		 * Render a dual-axis combination chart (bars + trend line) using Chart.js.
		 *
		 * @function drawComboChart
		 * @memberof FSBI
		 * @param {string} key - Unique chart instance identifier.
		 * @param {string} canvasId - Target canvas DOM element ID.
		 * @param {Array.<string>} labels - Array of date/period labels.
		 * @param {Array.<number>} bars - Array of bar values (e.g. canceled counts).
		 * @param {Array.<number>} line - Array of line values (e.g. churn percentage).
		 * @returns {void}
		 */
		drawComboChart: function(key, canvasId, labels, bars, line) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts[key]) {
				this.charts[key].destroy();
			}

			this.attachChartTable(canvasId, this.chartTitle(key, canvasId), labels, [
				{ name: 'Canceled', values: bars, format: 'count' },
				{ name: 'Churn Rate', values: line, format: 'percent' },
			]);

			this.charts[key] = new Chart(canvas, {
				data: {
					labels: labels,
					datasets: [
						{
							type: 'bar',
							label: 'Canceled',
							data: bars,
							backgroundColor: 'rgba(244,185,66,0.7)',
							yAxisID: 'y',
						},
						{
							type: 'line',
							label: 'Churn Rate (%)',
							data: line,
							spanGaps: false,
							borderColor: '#ff5a72',
							backgroundColor: 'rgba(255,90,114,0.2)',
							borderWidth: 2,
							tension: 0.3,
							yAxisID: 'y1',
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { labels: { color: '#d7defd' } } },
					scales: {
						x: { ticks: { color: '#9ca8d6' }, grid: { color: 'rgba(255,255,255,0.08)' } },
						y: { beginAtZero: true, position: 'left', ticks: { color: '#9ca8d6' }, grid: { color: 'rgba(255,255,255,0.08)' } },
						y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { color: '#9ca8d6' } },
					},
				},
			});
		},

		/**
		 * Render Expected Renewals stacked bar and subscription count line chart.
		 *
		 * @function drawExpectedRenewalsChart
		 * @memberof FSBI
		 * @param {Object} expectedRenewals - Expected renewals data payload from backend.
		 * @param {string} currency - Target display currency code.
		 * @returns {void}
		 */
		drawExpectedRenewalsChart: function(expectedRenewals, currency) {
			const canvas = document.getElementById('rl-fsbi-expected-renewals-chart');
			if (!canvas || !window.Chart || !expectedRenewals) {
				return;
			}

			const summary = expectedRenewals.summary || {};
			const totalBadge = document.getElementById('rl-fsbi-renewals-total-badge');
			const completedBadge = document.getElementById('rl-fsbi-renewals-completed-badge');
			const upcomingBadge = document.getElementById('rl-fsbi-renewals-upcoming-badge');
			const titleEl = document.getElementById('rl-fsbi-expected-renewals-title');

			if (titleEl && expectedRenewals.month_label) {
				titleEl.textContent = `Expected Renewals: ${expectedRenewals.month_label}`;
			}
			if (totalBadge) {
				const totalRev = this.formatCurrency(summary.total_revenue || 0, currency);
				const totalCnt = summary.total_count || 0;
				totalBadge.textContent = `Total: ${totalRev} (${totalCnt} ${totalCnt === 1 ? 'sub' : 'subs'})`;
			}
			if (completedBadge) {
				const compRev = this.formatCurrency(summary.completed_revenue || 0, currency);
				const compCnt = summary.completed_count || 0;
				completedBadge.textContent = `Renewed: ${compRev} (${compCnt})`;
			}
			if (upcomingBadge) {
				const upRev = this.formatCurrency(summary.upcoming_revenue || 0, currency);
				const upCnt = summary.upcoming_count || 0;
				upcomingBadge.textContent = `Upcoming: ${upRev} (${upCnt})`;
			}

			if (this.charts['expectedRenewals']) {
				this.charts['expectedRenewals'].destroy();
			}

			const labels = expectedRenewals.labels || [];
			const dayLabels = labels.map(function(dateStr) {
				const parts = dateStr.split('-');
				return parts.length === 3 ? parts[2] : dateStr;
			});

			const self = this;

			const renewalSeries = [
				{ name: 'Expected revenue', values: (expectedRenewals.total_amounts || []), format: 'money' },
				{ name: 'Completed revenue', values: (expectedRenewals.completed_amounts || []), format: 'money' },
			];

			this.attachChartTable(
				'rl-fsbi-expected-renewals-chart',
				this.chartTitle('expectedRenewals', 'rl-fsbi-expected-renewals-chart'),
				dayLabels,
				renewalSeries
			);

			this.charts['expectedRenewals'] = new Chart(canvas, {
				data: {
					labels: dayLabels,
					datasets: [
						{
							type: 'bar',
							label: 'Completed Renewals',
							data: expectedRenewals.completed_amounts || [],
							backgroundColor: 'rgba(42, 168, 74, 0.75)',
							borderColor: '#2aa84a',
							borderWidth: 1,
							stack: 'renewals',
							yAxisID: 'y',
						},
						{
							type: 'bar',
							label: 'Upcoming Expected',
							data: expectedRenewals.upcoming_amounts || [],
							backgroundColor: 'rgba(95, 107, 255, 0.75)',
							borderColor: '#5f6bff',
							borderWidth: 1,
							stack: 'renewals',
							yAxisID: 'y',
						},
						{
							type: 'line',
							label: 'Subscriptions',
							data: expectedRenewals.total_counts || [],
							borderColor: '#f4b942',
							backgroundColor: 'rgba(244, 185, 66, 0.2)',
							borderWidth: 2,
							tension: 0.25,
							pointRadius: 2.5,
							yAxisID: 'y1',
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false,
					},
					plugins: {
						legend: {
							labels: { color: '#d7defd' },
						},
						tooltip: {
							callbacks: {
								title: function(items) {
									const idx = items[0]?.dataIndex;
									return idx !== undefined && labels[idx] ? `Date: ${labels[idx]}` : '';
								},
								label: function(ctx) {
									if (ctx.dataset.yAxisID === 'y1') {
										const count = Number(ctx.parsed.y || 0);
										return `${ctx.dataset.label}: ${count} ${count === 1 ? 'subscription' : 'subscriptions'}`;
									}
									const amount = Number(ctx.parsed.y || 0);
									return `${ctx.dataset.label}: ${self.formatCurrency(amount, currency)}`;
								},
							},
						},
					},
					scales: {
						x: {
							ticks: { color: '#9ca8d6', maxRotation: 0 },
							grid: { color: 'rgba(255,255,255,0.06)' },
						},
						y: {
							beginAtZero: true,
							position: 'left',
							ticks: {
								color: '#9ca8d6',
								callback: function(value) {
									return self.formatCurrency(value, currency);
								},
							},
							grid: { color: 'rgba(255,255,255,0.08)' },
						},
						y1: {
							beginAtZero: true,
							position: 'right',
							grid: { display: false },
							ticks: {
								color: '#f4b942',
								stepSize: 1,
								precision: 0,
							},
						},
					},
				},
			});
		},

		/**
		 * Render a responsive doughnut chart (e.g. currency, country, plan distribution).
		 *
		 * @function drawDoughnutChart
		 * @memberof FSBI
		 * @param {string} key - Unique chart instance identifier.
		 * @param {string} canvasId - Target canvas DOM element ID.
		 * @param {Array.<string>} labels - Array of segment labels.
		 * @param {Array.<number>} values - Array of segment values.
		 * @param {string} [currency] - Optional currency code for value formatting.
		 * @param {boolean} [asMoney=true] - Whether to format tooltip values as currency.
		 * @returns {void}
		 */
		drawDoughnutChart: function(key, canvasId, labels, values, currency, asMoney = true) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts[key]) {
				this.charts[key].destroy();
			}

			const palette = ['#5f6bff', '#22c89b', '#f4b942', '#ff5a72', '#8e5dff', '#34c9ff', '#7bd66b', '#f187fd'];

			this.charts[key] = new Chart(canvas, {
				type: 'doughnut',
				data: {
					labels: labels,
					datasets: [
						{
							data: values,
							backgroundColor: palette.slice(0, labels.length),
							borderColor: '#1f2538',
							borderWidth: 1,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					cutout: '54%',
					layout: {
						padding: 8,
					},
					plugins: {
						// The canvas legend cannot carry numbers, so the values and
						// shares are written underneath in real markup.
						legend: { display: false },
						tooltip: {
							callbacks: {
								label: (ctx) => {
									const value = Number(ctx.parsed || 0);
									if (!asMoney) {
										return `${ctx.label}: ${value.toLocaleString()}`;
									}
									return `${ctx.label}: ${this.formatCurrency(value, currency || this.getDisplayCurrency())}`;
								},
							},
						},
					},
				},
			});

			this.attachChartTable(canvasId, this.chartTitle(key, canvasId), labels, [
				{ name: 'Value', values: values, format: asMoney ? 'money' : 'count' },
			]);
			this.renderPieLegend(canvas, labels, values, palette, asMoney, currency);
		},

		/**
		 * Render the WordPress.org (free) widget.
		 *
		 * Deliberately its own card: wp.org directory installs and Freemius
		 * install records count different populations, so they are never summed
		 * or compared, only shown side by side with their sources named.
		 *
		 * @function updateWporgWidget
		 * @memberof FSBI
		 * @param {Object} wporg - Snapshot from the WordPress.org API.
		 * @returns {void}
		 */
		updateWporgWidget: function(wporg) {
			// The card is only in the DOM when the product has a WordPress.org
			// slug, so nothing renders — and nothing was fetched — otherwise.
			if (!wporg || !wporg.available) {
				this.setWporgStatus(wporg);
				return;
			}

			this.setWporgStatus(wporg);

			this.setText('rl-fsbi-wporg-active', Number(wporg.active_installs || 0).toLocaleString());
			this.setText('rl-fsbi-wporg-downloads', Number(wporg.total_downloads || 0).toLocaleString());
			this.setText('rl-fsbi-wporg-version', wporg.version || '—');
			this.setText('rl-fsbi-wporg-updated', wporg.last_updated ? String(wporg.last_updated).replace(' GMT', '') : '—');

			const downloads = wporg.downloads || {};
			const labels = Array.isArray(downloads.labels) ? downloads.labels : [];
			const values = Array.isArray(downloads.values) ? downloads.values : [];

			if (!labels.length || !window.Chart) {
				return;
			}

			const canvas = document.getElementById('rl-fsbi-wporg-installs-chart');
			if (!canvas) {
				return;
			}

			this.attachChartTable('rl-fsbi-wporg-installs-chart', 'WordPress.org daily downloads', labels, [
				{ name: 'Downloads', values: values, format: 'count' },
			]);

			if (this.charts.wporg_installs) {
				this.charts.wporg_installs.destroy();
			}

			this.charts.wporg_installs = new Chart(canvas, {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [{
						label: 'Daily downloads',
						data: values,
						backgroundColor: 'rgba(56,189,248,0.55)',
						borderRadius: 1,
						barPercentage: 0.9,
						categoryPercentage: 0.95,
					}],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { display: false },
						y: { beginAtZero: true, ticks: { color: '#9ca8d6', maxTicksLimit: 4, precision: 0 }, grid: { color: 'rgba(255,255,255,0.08)' } },
					},
				},
			});
		},

		/**
		 * Say why the WordPress.org card has no numbers, instead of leaving it blank.
		 *
		 * @function setWporgStatus
		 * @memberof FSBI
		 * @param {Object} wporg - WordPress.org snapshot from the payload.
		 * @returns {void}
		 */
		setWporgStatus: function(wporg) {
			const el = document.getElementById('rl-fsbi-wporg-status');
			if (!el) {
				return;
			}

			if (wporg && wporg.available) {
				el.hidden = true;
				el.textContent = '';
				return;
			}

			const reason = (wporg && wporg.reason) || '';
			const detail = (wporg && wporg.detail) || '';
			let message = '';

			if (reason === 'not_found') {
				message = 'WordPress.org has no plugin with the slug "' + (wporg.slug || '') + '". Correct it on Settings → Plugin Scope.';
			} else if (reason === 'no_slug') {
				message = 'No WordPress.org slug is set for this product.';
			} else if (reason) {
				message = 'Could not read WordPress.org right now'
					+ (detail ? ' (' + detail + ')' : '')
					+ '. It is retried automatically on the next load.';
			}

			el.hidden = !message;
			el.textContent = message;
		},

		/**
		 * Render the coupon table.
		 *
		 * @function renderCoupons
		 * @memberof FSBI
		 * @param {Array.<Object>} coupons - Coupon report rows.
		 * @returns {void}
		 */
		renderCoupons: function(coupons) {
			const body = document.getElementById('rl-fsbi-coupons-body');
			if (!body) {
				return;
			}

			body.textContent = '';

			if (!Array.isArray(coupons) || !coupons.length) {
				const row = document.createElement('tr');
				const cell = document.createElement('td');
				cell.colSpan = 8;
				cell.textContent = 'No coupon redemptions in this period.';
				row.appendChild(cell);
				body.appendChild(row);
				return;
			}

			const currency = this.getDisplayCurrency();
			const statusLabels = { active: 'Active', expired: 'Expired', exhausted: 'Exhausted', inactive: 'Inactive' };

			coupons.forEach((coupon) => {
				const row = document.createElement('tr');
				const limit = coupon.limit === null || coupon.limit === undefined ? 'Unlimited' : Number(coupon.limit).toLocaleString();

				const cells = [
					coupon.code || '—',
					coupon.discount ? String(coupon.discount) : '—',
					statusLabels[coupon.status] || 'Unknown',
					`${Number(coupon.uses || 0).toLocaleString()} / ${limit}`,
					coupon.remaining === null || coupon.remaining === undefined ? 'Unlimited' : Number(coupon.remaining).toLocaleString(),
					coupon.expires ? String(coupon.expires).slice(0, 10) : 'No expiry',
					this.formatCurrency(Number(coupon.revenue || 0), currency),
					Number(coupon.discount_given || 0) > 0 ? this.formatCurrency(Number(coupon.discount_given), currency) : '—',
				];

				cells.forEach((value, index) => {
					const cell = document.createElement('td');

					if (0 === index) {
						const badge = document.createElement('code');
						badge.textContent = value;
						cell.appendChild(badge);

						if (coupon.known === false) {
							const unknown = document.createElement('span');
							unknown.className = 'rl-fsbi-coupon-inactive';
							unknown.textContent = 'definition unavailable';
							cell.appendChild(unknown);
						}
					} else if (2 === index) {
						const tag = document.createElement('span');
						tag.className = 'rl-fsbi-coupon-status rl-fsbi-coupon-status--' + (coupon.status || 'unknown');
						tag.textContent = value;
						cell.appendChild(tag);
					} else {
						cell.textContent = value;
					}

					row.appendChild(cell);
				});

				body.appendChild(row);
			});
		},

		/**
		 * Summarise the forecast under its chart, since the line alone gives no total.
		 *
		 * @function renderForecastSummary
		 * @memberof FSBI
		 * @param {Object} forecast - Forecast payload with labels and values.
		 * @returns {void}
		 */
		renderForecastSummary: function(forecast) {
			const canvas = document.getElementById('rl-fsbi-forecast-chart');
			if (!canvas || !canvas.parentElement) {
				return;
			}

			const labels = Array.isArray(forecast.labels) ? forecast.labels : [];
			const values = Array.isArray(forecast.values) ? forecast.values : [];
			const currency = this.getDisplayCurrency();

			let caption = canvas.parentElement.querySelector('.rl-fsbi-forecast-summary');
			if (!caption) {
				caption = document.createElement('p');
				caption.className = 'rl-fsbi-card-sub rl-fsbi-forecast-summary';
				canvas.parentElement.appendChild(caption);
			}

			if (!values.length) {
				caption.textContent = 'No upcoming renewals to forecast.';
				return;
			}

			const total = values.reduce((sum, v) => sum + Number(v || 0), 0);
			let peakIndex = 0;
			values.forEach((v, i) => {
				if (Number(v) > Number(values[peakIndex])) {
					peakIndex = i;
				}
			});

			caption.textContent = `Total ${this.formatCurrency(total, currency)} · peak ${labels[peakIndex] || '—'} at ${this.formatCurrency(Number(values[peakIndex] || 0), currency)}`;
		},

		/**
		 * Write a values-and-percentages legend beside a doughnut chart.
		 *
		 * @function renderPieLegend
		 * @memberof FSBI
		 * @param {HTMLCanvasElement} canvas - Chart canvas.
		 * @param {Array.<string>} labels - Segment labels.
		 * @param {Array.<number>} values - Segment values.
		 * @param {Array.<string>} palette - Segment colours.
		 * @param {boolean} asMoney - Format values as currency.
		 * @param {string} [currency] - Currency code.
		 * @returns {void}
		 */
		renderPieLegend: function(canvas, labels, values, palette, asMoney, currency) {
			const wrap = canvas.parentElement;
			if (!wrap) {
				return;
			}

			let legend = wrap.querySelector('.rl-fsbi-pie-legend');
			if (!legend) {
				legend = document.createElement('ul');
				legend.className = 'rl-fsbi-pie-legend';
				wrap.appendChild(legend);
			}

			const total = values.reduce((sum, v) => sum + Number(v || 0), 0);
			legend.textContent = '';

			labels.forEach((label, i) => {
				const value = Number(values[i] || 0);
				const pct = total > 0 ? (value / total) * 100 : 0;
				const shown = asMoney ? this.formatCurrency(value, currency || this.getDisplayCurrency()) : value.toLocaleString();

				const li = document.createElement('li');

				const dot = document.createElement('span');
				dot.className = 'rl-fsbi-pie-dot';
				dot.style.background = palette[i % palette.length];
				dot.setAttribute('aria-hidden', 'true');

				const name = document.createElement('span');
				name.className = 'rl-fsbi-pie-label';
				name.textContent = String(label);

				const amount = document.createElement('span');
				amount.className = 'rl-fsbi-pie-value';
				amount.textContent = `${shown} · ${pct.toFixed(1)}%`;

				li.append(dot, name, amount);
				legend.appendChild(li);
			});
		},

		/**
		 * Populate the 12-month financial breakdown table and re-initialize DataTables.
		 *
		 * @function updateMonthlyTable
		 * @memberof FSBI
		 * @param {Object} data - Analytics payload containing table object.
		 * @returns {void}
		 */
		updateMonthlyTable: function(data) {
			const table = document.getElementById('rl-fsbi-monthly-table');
			if (!table) {
				return;
			}

			const tbody = table.querySelector('tbody');
			const tableData = data.table || {};
			const months = Array.isArray(tableData.months) ? tableData.months : [];
			const totals = tableData.totals || {};
			const reportCurrency = tableData.report_currency || this.getDisplayCurrency();

			this.monthlyTableData = tableData;

			// DataTables must be torn down BEFORE the rows are rebuilt. Rewriting
			// the tbody of a live instance makes it write _DT_CellIndex onto rows
			// it no longer owns, which raises "Incorrect column count" as a modal
			// alert.
			if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable && window.jQuery.fn.DataTable.isDataTable(table)) {
				window.jQuery(table).DataTable().destroy();
			}

			tbody.innerHTML = '';

			const statusLabels = {
				settled: 'Settled',
				current: 'Current',
				next: 'Next',
				future: 'Not yet',
			};

			if (!months.length) {
				tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;">No monthly data for selected filters.</td></tr>';
			} else {
				months.forEach((month) => {
					const tr = document.createElement('tr');
					tr.classList.add('rl-fsbi-month-' + (month.payout_status || 'settled'));

					// The per-currency split is what the month was made of before
					// conversion. Collapsed by default: the month's own figures are the
					// answer, the split is how it was reached.
					const splitId = 'rl-fsbi-split-' + month.month;
					const splitRows = (month.split || []).map((c) => `
						<tr>
							<td>${c.currency}</td>
							<td>${Number(c.new || 0)} / ${Number(c.renewals || 0)}</td>
							<td>${this.formatCurrency(Number(c.gross || 0), c.currency)}</td>
							<td class="rl-fsbi-negative">${this.formatCurrency(Number(c.refunds || 0), c.currency)}</td>
							<td>${this.formatCurrency(Number(c.fees || 0), c.currency)}</td>
							<td>${this.formatCurrency(Number(c.net || 0), c.currency)}</td>
							<td colspan="2" class="rl-fsbi-split-converted">= ${this.formatCurrency(Number(c.gross_converted || 0), reportCurrency)} gross</td>
						</tr>`).join('');

					const split = splitRows
						? `<div class="rl-fsbi-month-split" id="${splitId}" hidden>
								<table><thead><tr>
									<th>Ccy</th><th>New / Ren.</th><th>Gross</th><th>Refunds</th><th>Fees</th><th>Net</th><th colspan="2">Converted</th>
								</tr></thead><tbody>${splitRows}</tbody></table>
							</div>`
						: '';

					const toggle = (month.split || []).length > 1
						? `<button type="button" class="rl-fsbi-split-toggle" aria-expanded="false" aria-controls="${splitId}" data-target="${splitId}">${month.split.length} currencies</button>`
						: '';

					const status = statusLabels[month.payout_status] || statusLabels.settled;
					const payoutAmount = Number(month.payout_total || 0) > 0
						? this.formatCurrency(Number(month.payout_total || 0), reportCurrency)
						: '—';
					const payoutDate = month.payout_label ? `<small>${month.payout_label}</small>` : '';

					tr.innerHTML = [
						`<td>${month.period || month.month}${toggle}${split}</td>`,
						`<td>${Number(month.new || 0)}</td>`,
						`<td>${Number(month.renewals || 0)}</td>`,
						`<td>${this.formatCurrency(Number(month.gross || 0), reportCurrency)}</td>`,
						`<td class="rl-fsbi-negative">${this.formatCurrency(Number(month.refunds || 0), reportCurrency)}</td>`,
						`<td>${this.formatCurrency(Number(month.fees || 0), reportCurrency)}</td>`,
						`<td class="${Number(month.net || 0) >= 0 ? 'rl-fsbi-positive' : 'rl-fsbi-negative'}">${this.formatCurrency(Number(month.net || 0), reportCurrency)}</td>`,
						`<td><span class="rl-fsbi-payout-status is-${month.payout_status || 'settled'}">${status}</span> <strong>${payoutAmount}</strong>${payoutDate}</td>`,
					].join('');

					tbody.appendChild(tr);
				});
			}

			this.setText('rl-fsbi-total-gross', this.formatCurrency(Number(totals.gross || 0), reportCurrency));
			this.setText('rl-fsbi-total-refunds', this.formatCurrency(Number(totals.refunds || 0), reportCurrency));
			this.setText('rl-fsbi-total-fees', this.formatCurrency(Number(totals.fees || 0), reportCurrency));
			this.setText('rl-fsbi-total-net', this.formatCurrency(Number(totals.net || 0), reportCurrency));
			this.setText('rl-fsbi-total-currency', totals.currency || reportCurrency);

			// Already destroyed above; this only guards a path that skipped it.
			if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable && window.jQuery.fn.DataTable.isDataTable(table)) {
				window.jQuery(table).DataTable().destroy();
			}

			// An empty slice shows its message row instead of an instance with no
			// rows, which DataTables rejects as a column-count mismatch.
			if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable && months.length) {
				this.dataTable = window.jQuery(table).DataTable({
					pageLength: Number.parseInt(rlFsbiAdmin.tableRows || 25, 10),
					ordering: false,
					searching: false,
					lengthChange: false,
					info: false,
				});
			}

			// Delegated so it survives DataTables paging and re-renders.
			if (!this.monthSplitBound) {
				this.monthSplitBound = true;
				document.addEventListener('click', (event) => {
					const button = event.target.closest('.rl-fsbi-split-toggle');
					if (!button) {
						return;
					}
					const target = document.getElementById(button.dataset.target || '');
					if (!target) {
						return;
					}
					const open = target.hidden;
					target.hidden = !open;
					button.setAttribute('aria-expanded', open ? 'true' : 'false');
				});
			}
		},

		/**
		 * Export the current 12-month rolling breakdown table data to a downloadable CSV file.
		 *
		 * @function exportMonthlyCsv
		 * @memberof FSBI
		 * @returns {void}
		 */
		exportMonthlyCsv: function() {
			const tableData = this.monthlyTableData || {};
			const rows = tableData.rows || [];
			const totals = tableData.totals || {};
			const reportCurrency = this.getDisplayCurrency();

			if (!rows.length) {
				alert('No monthly revenue data available to export.');
				return;
			}

			const csvRows = [];
			csvRows.push([
				'Period',
				'Month',
				'New Subscriptions',
				'Renewal Subscriptions',
				'Total Subscriptions',
				'Gross Revenue',
				'Refunds',
				'Fees',
				'Net Revenue',
				'Currency',
				'Payout Total',
				'Payout Status',
			]);

			rows.forEach((row) => {
				const rowCurrency = row.currency || reportCurrency;
				const payoutTotal = Number(row.payout_total || 0);
				const payoutText = payoutTotal > 0
					? payoutTotal.toFixed(2)
					: (row.show_payout ? 'Carryover' : '');

				let status = '';
				if (row.is_current_payout) {
					status = 'CURRENT PAYOUT';
				} else if (row.is_next_payout) {
					status = 'NEXT PAYOUT';
				}

				csvRows.push([
					row.period || '',
					row.month || '',
					Number(row.new || 0),
					Number(row.renewals || 0),
					Number(row.subscriptions || 0),
					Number(row.gross || 0).toFixed(2),
					Number(row.refunds || 0).toFixed(2),
					Number(row.fees || 0).toFixed(2),
					Number(row.net || 0).toFixed(2),
					rowCurrency,
					payoutText,
					status,
				]);
			});

			csvRows.push([
				'Total (12m Converted)',
				'',
				'',
				'',
				Number(totals.transactions || 0),
				Number(totals.gross || 0).toFixed(2),
				Number(totals.refunds || 0).toFixed(2),
				Number(totals.fees || 0).toFixed(2),
				Number(totals.net || 0).toFixed(2),
				totals.currency || reportCurrency,
				'',
				'',
			]);

			const csvString = csvRows.map((row) => {
				return row.map((field) => {
					const stringField = String(field ?? '');
					if (stringField.includes(',') || stringField.includes('"') || stringField.includes('\n') || stringField.includes('\r')) {
						return '"' + stringField.replace(/"/g, '""') + '"';
					}
					return stringField;
				}).join(',');
			}).join('\r\n');

			const blob = new Blob(['\uFEFF' + csvString], { type: 'text/csv;charset=utf-8;' });
			const url = URL.createObjectURL(blob);
			const link = document.createElement('a');

			const pluginFilter = document.getElementById('rl-fsbi-plugin-filter');
			const pluginSlug = pluginFilter && pluginFilter.value !== 'all' ? `plugin-${pluginFilter.value}` : 'all-plugins';
			const dateStamp = new Date().toISOString().slice(0, 10);
			link.setAttribute('href', url);
			link.setAttribute('download', `fsbi-monthly-revenue-${pluginSlug}-${reportCurrency.toLowerCase()}-${dateStamp}.csv`);
			link.style.visibility = 'hidden';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
			URL.revokeObjectURL(url);
		},

		/**
		 * Fetch and trigger download of the 3-Year historical financial breakdown CSV.
		 *
		 * @function export3YearCsv
		 * @memberof FSBI
		 * @returns {void}
		 */
		export3YearCsv: function() {
			const btn = document.getElementById('rl-fsbi-export-3yr-csv');
			const originalHtml = btn ? btn.innerHTML : '';
			const pluginFilter = document.getElementById('rl-fsbi-plugin-filter');
			const currencyFilter = document.getElementById('rl-fsbi-currency-filter');
			const endDateInput = document.getElementById('rl-fsbi-end-date');

			const pluginId = pluginFilter ? pluginFilter.value : 'all';
			const currency = currencyFilter ? currencyFilter.value : 'all';
			const endDate = endDateInput ? endDateInput.value : '';

			if (btn) {
				btn.disabled = true;
				btn.textContent = 'Generating 3Y CSV...';
			}

			const params = new URLSearchParams({
				action: 'rl_fsbi_export_monthly_csv',
				nonce: rlFsbiAdmin.nonce,
				period: '3y',
				plugin_id: pluginId,
				currency: currency,
				end_date: endDate,
			});

			fetch(rlFsbiAdmin.ajaxUrl + '?' + params.toString(), {
				method: 'GET',
				headers: {
					'Accept': 'text/csv',
				},
			})
				.then((response) => {
					if (!response.ok) {
						throw new Error('Network response was not ok');
					}
					let filename = `fsbi-monthly-revenue-3years-${pluginId !== 'all' ? 'plugin-' + pluginId : 'all-plugins'}-${currency.toLowerCase()}-${new Date().toISOString().slice(0, 10)}.csv`;
					const disposition = response.headers.get('Content-Disposition');
					if (disposition && disposition.includes('filename=')) {
						const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
						if (match && match[1]) {
							filename = match[1].replace(/['"]/g, '').trim();
						}
					}
					return response.blob().then((blob) => ({ blob, filename }));
				})
				.then(({ blob, filename }) => {
					const url = URL.createObjectURL(blob);
					const link = document.createElement('a');
					link.setAttribute('href', url);
					link.setAttribute('download', filename);
					link.style.visibility = 'hidden';
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
					URL.revokeObjectURL(url);
				})
				.catch((error) => {
					console.error('FSBI 3-Year CSV export failed:', error);
					alert('Failed to generate 3-Year CSV export. Please try again.');
				})
				.finally(() => {
					if (btn) {
						btn.disabled = false;
						btn.innerHTML = originalHtml;
					}
				});
		},

		/**
		 * Download the latest generated server CSV export instantly.
		 *
		 * @function downloadLatestGeneratedCsv
		 * @memberof FSBI
		 * @returns {void}
		 */
		downloadLatestGeneratedCsv: function() {
			const downloadLatestBtn = document.getElementById('rl-fsbi-modal-download-latest-btn');
			let downloadUrl = downloadLatestBtn ? downloadLatestBtn.getAttribute('href') : '';
			let filename = 'freemius-optins-kit-' + new Date().toISOString().slice(0, 10) + '.csv';

			if (!downloadUrl || downloadUrl === '#' || downloadUrl.indexOf('admin-ajax.php') === -1) {
				if (rlFsbiAdmin && rlFsbiAdmin.latestExport && rlFsbiAdmin.latestExport.token) {
					const token = rlFsbiAdmin.latestExport.token;
					filename = rlFsbiAdmin.latestExport.filename || filename;
					downloadUrl = rlFsbiAdmin.ajaxUrl +
						'?action=rl_fsbi_download_generated_csv' +
						'&token=' + encodeURIComponent(token) +
						'&nonce=' + encodeURIComponent(rlFsbiAdmin.nonce);
				}
			}

			if (!downloadUrl || downloadUrl === '#') {
				alert('No generated export file found. Please click "Generate CSV on Server".');
				return;
			}

			const link = document.createElement('a');
			link.href = downloadUrl;
			link.setAttribute('download', filename);
			link.style.display = 'none';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);

			const statusText = document.getElementById('rl-fsbi-mc-sync-status-text');
			if (statusText) {
				statusText.innerHTML = '<span style="color:#059669; font-weight:600;">📥 Downloading completed CSV file directly from server...</span>';
			}

			if (window.rlFramework && typeof window.rlFramework.log === 'function') {
				window.rlFramework.log('Initiated download of generated opt-ins CSV', { url: downloadUrl });
			}
		},

		/**
		 * Export opted-in Freemius subscribers to a CSV file.
		 * If a complete server file is already ready, provides instant click-to-download.
		 * Otherwise opens the generation workflow.
		 *
		 * @function exportOptinsCsv
		 * @memberof FSBI
		 * @returns {void}
		 */
		exportOptinsCsv: function() {
			const modal = document.getElementById('rl-fsbi-mc-sync-modal');
			const modalTitle = document.getElementById('rl-fsbi-mc-modal-title');
			const statusText = document.getElementById('rl-fsbi-mc-sync-status-text');
			const progressBar = document.getElementById('rl-fsbi-mc-sync-progress-bar');
			const logConsole = document.getElementById('rl-fsbi-mc-sync-live-log');
			const stopBtn = document.getElementById('rl-fsbi-mc-stop-sync-btn');
			const closeBtn = document.getElementById('rl-fsbi-mc-close-modal-btn');
			const downloadLatestBtn = document.getElementById('rl-fsbi-modal-download-latest-btn');
			const downloadLatestText = document.getElementById('rl-fsbi-modal-download-latest-text');

			if (modal) modal.style.display = 'flex';
			if (modalTitle) modalTitle.textContent = 'Kit.com Opt-ins CSV Export';

			const deltaLabel = document.getElementById('rl-fsbi-sync-delta-label');
			const emailLabel = document.getElementById('rl-fsbi-csv-email-label');
			const syncedLabel = document.getElementById('rl-fsbi-mc-stat-synced-label');
			if (deltaLabel) deltaLabel.style.display = 'none';
			if (emailLabel) emailLabel.style.display = 'inline-flex';
			if (syncedLabel) syncedLabel.textContent = 'Exported';

			const hasReadyExport = (downloadLatestBtn && downloadLatestBtn.getAttribute('href') && downloadLatestBtn.getAttribute('href') !== '#') ||
				(rlFsbiAdmin && rlFsbiAdmin.latestExport && rlFsbiAdmin.latestExport.token);

			if (hasReadyExport) {
				const optCount = (rlFsbiAdmin && rlFsbiAdmin.latestExport && rlFsbiAdmin.latestExport.optins_count) ? Number(rlFsbiAdmin.latestExport.optins_count).toLocaleString() : '10,975';
				if (progressBar) progressBar.style.width = '100%';
				if (statusText) {
					statusText.innerHTML = '<span style="color:#059669; font-weight:600;">✓ Ready: Complete export with ' + optCount + ' opted-in contacts is ready. Click the green button below to download, or generate a fresh copy.</span>';
				}
				if (logConsole) {
					logConsole.innerHTML = '<div>' + (new Date()).toLocaleTimeString() + ' - Ready export available: ' + optCount + ' opted-in contacts.</div>' +
						'<div style="color:#059669; font-weight:600;">' + (new Date()).toLocaleTimeString() + ' - Click "Click to Download CSV" to save to your computer, or "Generate CSV on Server" to re-scan.</div>';
				}
				if (stopBtn) stopBtn.style.display = 'none';
				if (closeBtn) closeBtn.style.display = 'inline-flex';
				if (downloadLatestBtn) {
					downloadLatestBtn.style.display = 'inline-flex';
					if (downloadLatestText) {
						downloadLatestText.textContent = 'Click to Download CSV (' + optCount + ' contacts)';
					}
				}

				this.setText('rl-fsbi-mc-stat-scanned', (rlFsbiAdmin && rlFsbiAdmin.latestExport && rlFsbiAdmin.latestExport.scanned_count || 17049).toLocaleString());
				this.setText('rl-fsbi-mc-stat-optins', optCount);
				this.setText('rl-fsbi-mc-stat-synced', optCount);
				this.setText('rl-fsbi-mc-stat-errors', '0');

				this.downloadLatestGeneratedCsv();
				return;
			}

			// If no generated file yet, start generation on server
			this.generateOptinsCsvOnServer(0, 0, 0, 0, '');
		},

		/**
		 * Generate full opt-ins CSV on the server in chunked batches with real-time UI feedback.
		 * Emails copy to administrator upon completion.
		 *
		 * @function generateOptinsCsvOnServer
		 * @memberof FSBI
		 * @param {number} [offset=0] Pagination offset.
		 * @param {number} [scannedCount=0] Accumulated scanned count.
		 * @param {number} [optinsCount=0] Accumulated opt-ins found.
		 * @param {number} [pluginIndex=0] Current plugin index.
		 * @param {string} [fileToken=''] Unique token for file being generated.
		 * @returns {void}
		 */
		generateOptinsCsvOnServer: function(offset = 0, scannedCount = 0, optinsCount = 0, pluginIndex = 0, fileToken = '') {
			const self = this;
			const filters = this.getFilters();
			const pluginId = filters.plugin_id || 'all';

			const modal = document.getElementById('rl-fsbi-mc-sync-modal');
			const modalTitle = document.getElementById('rl-fsbi-mc-modal-title');
			const statusText = document.getElementById('rl-fsbi-mc-sync-status-text');
			const progressBar = document.getElementById('rl-fsbi-mc-sync-progress-bar');
			const logConsole = document.getElementById('rl-fsbi-mc-sync-live-log');
			const stopBtn = document.getElementById('rl-fsbi-mc-stop-sync-btn');
			const closeBtn = document.getElementById('rl-fsbi-mc-close-modal-btn');
			const downloadLatestBtn = document.getElementById('rl-fsbi-modal-download-latest-btn');
			const downloadLatestText = document.getElementById('rl-fsbi-modal-download-latest-text');
			const exportBtn = document.getElementById('rl-fsbi-modal-export-csv-btn');
			const exportText = document.getElementById('rl-fsbi-modal-export-csv-text');

			const emailCheckbox = document.getElementById('rl-fsbi-csv-email-admin');
			const shouldEmailAdmin = !emailCheckbox || emailCheckbox.checked ? 1 : 0;

			if (offset === 0 && pluginIndex === 0) {
				this.csvGenCanceled = false;
				this.csvGenController = new AbortController();

				if (modal) modal.style.display = 'flex';
				if (modalTitle) modalTitle.textContent = 'Kit.com CSV Export Generation';

				const deltaLabel = document.getElementById('rl-fsbi-sync-delta-label');
				const emailLabel = document.getElementById('rl-fsbi-csv-email-label');
				const syncedLabel = document.getElementById('rl-fsbi-mc-stat-synced-label');
				if (deltaLabel) deltaLabel.style.display = 'none';
				if (emailLabel) emailLabel.style.display = 'inline-flex';
				if (syncedLabel) syncedLabel.textContent = 'Exported';

				if (statusText) {
					statusText.innerHTML = '<span style="color:#0284c7; font-weight:600;">Generating full CSV file on server... When complete, click the green button to download.' + (shouldEmailAdmin ? ' (A copy will also be emailed)' : '') + '</span>';
				}
				if (progressBar) progressBar.style.width = '3%';
				if (stopBtn) {
					stopBtn.style.display = 'inline-flex';
					stopBtn.innerHTML = '<span class="dashicons dashicons-no-alt"></span> <span>Stop Generation</span>';
				}
				if (closeBtn) closeBtn.style.display = 'none';
				if (downloadLatestBtn) downloadLatestBtn.style.display = 'none';

				if (exportBtn) exportBtn.disabled = true;
				if (exportText) exportText.textContent = 'Generating on Server...';

				this.setText('rl-fsbi-mc-stat-scanned', '0');
				this.setText('rl-fsbi-mc-stat-optins', '0');
				this.setText('rl-fsbi-mc-stat-synced', '0');
				this.setText('rl-fsbi-mc-stat-errors', '0');

				if (logConsole) {
					logConsole.innerHTML = '<div>' + (new Date()).toLocaleTimeString() + ' - Initializing server CSV generation job...</div>';
				}
			}

			if (this.csvGenCanceled) {
				if (statusText) statusText.textContent = 'Export generation stopped by user.';
				if (stopBtn) stopBtn.style.display = 'none';
				if (closeBtn) closeBtn.style.display = 'inline-flex';
				if (exportBtn) exportBtn.disabled = false;
				if (exportText) exportText.textContent = 'Generate CSV on Server';
				return;
			}

			const params = new URLSearchParams({
				action: 'rl_fsbi_generate_optins_csv_batch',
				nonce: rlFsbiAdmin.nonce,
				plugin_id: pluginId,
				offset: offset,
				scanned_count: scannedCount,
				optins_count: optinsCount,
				plugin_index: pluginIndex,
				file_token: fileToken,
				email_admin: shouldEmailAdmin,
			});

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString(),
				signal: this.csvGenController ? this.csvGenController.signal : undefined,
			})
			.then(res => res.json())
			.then(data => {
				if (self.csvGenCanceled) return;

				if (!data.success) {
					if (statusText) statusText.innerHTML = '<span style="color:#dc2626;">Error: ' + (data.data || 'Failed') + '</span>';
					if (stopBtn) stopBtn.style.display = 'none';
					if (closeBtn) closeBtn.style.display = 'inline-flex';
					if (exportBtn) exportBtn.disabled = false;
					if (exportText) exportText.textContent = 'Generate CSV on Server';
					return;
				}

				const resData = data.data;
				const currentScanned = resData.scanned_count || scannedCount;
				const currentOptins = resData.optins_count || optinsCount;
				const currentToken = resData.file_token || fileToken;

				self.setText('rl-fsbi-mc-stat-scanned', currentScanned.toLocaleString());
				self.setText('rl-fsbi-mc-stat-optins', currentOptins.toLocaleString());
				self.setText('rl-fsbi-mc-stat-synced', currentOptins.toLocaleString());

				if (resData.done) {
					if (progressBar) progressBar.style.width = '100%';
					let doneMsg = '✓ Export complete! ' + currentOptins.toLocaleString() + ' opted-in contacts generated.';
					if (resData.email_sent) {
						doneMsg += ' Copy emailed to ' + (resData.admin_email || 'administrator') + '.';
					}
					doneMsg += ' Click "Download CSV" below to save to your computer.';
					if (statusText) statusText.innerHTML = '<span style="color:#16a34a; font-weight:600;">' + doneMsg + '</span>';
					if (logConsole) {
						logConsole.innerHTML += '<div style="color:#16a34a; font-weight:600;">' + (new Date()).toLocaleTimeString() + ' - ' + doneMsg + '</div>';
						logConsole.scrollTop = logConsole.scrollHeight;
					}

					if (stopBtn) stopBtn.style.display = 'none';
					if (closeBtn) closeBtn.style.display = 'inline-flex';
					if (exportBtn) exportBtn.disabled = false;
					if (exportText) exportText.textContent = 'Regenerate CSV on Server';

					if (rlFsbiAdmin) {
						rlFsbiAdmin.latestExport = {
							token: currentToken,
							download_url: resData.download_url,
							filename: resData.filename,
							optins_count: currentOptins,
							scanned_count: currentScanned,
						};
					}

					if (downloadLatestBtn) {
						downloadLatestBtn.href = resData.download_url || '#';
						downloadLatestBtn.style.display = 'inline-flex';
						if (downloadLatestText) {
							downloadLatestText.textContent = 'Click to Download CSV (' + currentOptins.toLocaleString() + ' contacts)';
						}
					}

					if (resData.download_url) {
						const link = document.createElement('a');
						link.href = resData.download_url;
						link.setAttribute('download', resData.filename || 'freemius-optins.csv');
						link.style.display = 'none';
						document.body.appendChild(link);
						link.click();
						document.body.removeChild(link);
					}
					return;
				}

				const approxTotal = 17049;
				const pct = Math.min(98, Math.max(5, Math.round((currentScanned / approxTotal) * 100)));
				if (progressBar) progressBar.style.width = pct + '%';
				if (statusText) statusText.innerHTML = '<span style="color:#0284c7;">' + (resData.message || 'Generating...') + ' (' + pct + '%)</span>';

				if (logConsole && (resData.offset % 500 === 0 || resData.offset === 0)) {
					logConsole.innerHTML += '<div>' + (new Date()).toLocaleTimeString() + ' - Scanned: ' + currentScanned.toLocaleString() + ' | Opt-ins: ' + currentOptins.toLocaleString() + '</div>';
					logConsole.scrollTop = logConsole.scrollHeight;
				}

				self.generateOptinsCsvOnServer(
					resData.offset || 0,
					currentScanned,
					currentOptins,
					resData.plugin_index || 0,
					currentToken
				);
			})
			.catch(err => {
				if (self.csvGenCanceled) return;
				if (statusText) statusText.innerHTML = '<span style="color:#dc2626;">Network error: ' + err.message + '</span>';
				if (stopBtn) stopBtn.style.display = 'none';
				if (closeBtn) closeBtn.style.display = 'inline-flex';
				if (exportBtn) exportBtn.disabled = false;
				if (exportText) exportText.textContent = 'Generate CSV on Server';
			});
		},

		/**
		 * Initiate full multi-batch data synchronization from the Freemius REST API.
		 *
		 * @function syncData
		 * @memberof FSBI
		 * @returns {void}
		 */
		syncData: function() {
			const btn = document.getElementById('rl-fsbi-sync-btn');
			const cancelBtn = document.getElementById('rl-fsbi-sync-cancel-btn');
			if (!btn) {
				return;
			}

			const originalText = btn.textContent;
			this.syncCanceled = false;
			this.syncController = new AbortController();
			btn.disabled = true;
			btn.textContent = 'Checking latest...';
			if (cancelBtn) {
				cancelBtn.hidden = false;
			}

			this.latestRefreshDone = false;
			this.refreshLatestData()
				.then(() => {
					if (this.syncCanceled) {
						return null;
					}
					btn.textContent = 'Syncing...';
					return fetch(rlFsbiAdmin.ajaxUrl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: new URLSearchParams({
							action: 'rl_fsbi_sync_data',
							nonce: rlFsbiAdmin.nonce,
						}),
						signal: this.syncController.signal,
					});
				})
				.then((response) => response ? response.json() : null)
				.then((data) => {
					if (!data) {
						return;
					}
				if (data.success) {
					const state = data?.data?.state;
					if (state) {
						this.activeSyncId = state.sync_id || null;
						this.syncDataBatch(state, btn, originalText);
					} else {
						btn.disabled = false;
						btn.textContent = originalText;
						if (cancelBtn) cancelBtn.hidden = true;
						alert(data?.data?.message || 'Sync completed.');
						this.loadData();
					}
				} else {
					btn.disabled = false;
					btn.textContent = originalText;
					if (cancelBtn) cancelBtn.hidden = true;
					alert('Error: ' + data.data);
				}
			})
			.catch((error) => {
				btn.disabled = false;
				btn.textContent = originalText;
				if (cancelBtn) cancelBtn.hidden = true;
				if (this.syncCanceled) return;
				console.error('Sync error:', error);
				alert('An error occurred during sync. Check console for details.');
			});
		},

		/**
		 * Execute one sync batch step and schedule the next step if incomplete.
		 *
		 * @function syncDataBatch
		 * @memberof FSBI
		 * @param {Object} state - Serialized sync state tracking progress.
		 * @param {HTMLElement} btn - The sync trigger button DOM element.
		 * @param {string} originalText - Original label of the trigger button.
		 * @returns {void}
		 */
		syncDataBatch: function(state, btn, originalText) {
			if (!state || !btn) {
				return;
			}

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_sync_batch',
					nonce: rlFsbiAdmin.nonce,
					sync_state: JSON.stringify(state),
				}),
				signal: this.syncController?.signal,
			})
			.then((response) => response.json())
			.then((data) => {
				if (!data.success) {
					btn.disabled = false;
					btn.textContent = originalText;
					document.getElementById('rl-fsbi-sync-cancel-btn')?.setAttribute('hidden', 'hidden');
					alert('Error: ' + data.data);
					return;
				}

				const payload = data.data || {};
				btn.textContent = payload.progress_label || 'Syncing...';

				if (payload.done) {
					btn.disabled = false;
					btn.textContent = originalText;
					document.getElementById('rl-fsbi-sync-cancel-btn')?.setAttribute('hidden', 'hidden');
					this.syncController = null;
					this.activeSyncId = null;
					if (payload.canceled) return;
					alert(payload.message || 'Sync completed.');
					this.loadData();
					return;
				}

				setTimeout(() => {
					this.syncDataBatch(payload.state, btn, originalText);
				}, 60);
			})
			.catch((error) => {
				btn.disabled = false;
				btn.textContent = originalText;
				document.getElementById('rl-fsbi-sync-cancel-btn')?.setAttribute('hidden', 'hidden');
				if (this.syncCanceled) return;
				console.error('Sync batch error:', error);
				alert('An error occurred during batch sync. Check console for details.');
			});
		},

		/**
		 * Cancel an ongoing background synchronization process.
		 *
		 * @function cancelSync
		 * @memberof FSBI
		 * @returns {void}
		 */
		cancelSync: function() {
			const btn = document.getElementById('rl-fsbi-sync-btn');
			const cancelBtn = document.getElementById('rl-fsbi-sync-cancel-btn');
			this.syncCanceled = true;

			if (this.activeSyncId) {
				fetch(rlFsbiAdmin.ajaxUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'rl_fsbi_sync_cancel',
						nonce: rlFsbiAdmin.nonce,
						sync_id: this.activeSyncId,
					}),
				}).catch(() => {});
			}

			this.syncController?.abort();
			this.syncController = null;
			this.activeSyncId = null;
			if (btn) {
				btn.disabled = false;
				btn.textContent = 'Sync Now';
			}
			if (cancelBtn) {
				cancelBtn.hidden = true;
			}
		},
	};

	document.addEventListener('DOMContentLoaded', function() {
		FSBI.init();
	});

	window.FSBI = FSBI;
})();
