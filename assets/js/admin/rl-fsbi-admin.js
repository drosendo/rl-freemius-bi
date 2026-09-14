/**
 * RL Freemius BI Admin Dashboard JavaScript
 */

(function() {
	'use strict';

	const FSBI = {
		charts: {},
		dataTable: null,
		currentReportCurrency: null,
		syncController: null,
		activeSyncId: null,
		syncCanceled: false,
		latestRefreshDone: false,
		monthlyTableData: null,

		init: function() {
			this.applyDefaultFilters();
			this.bindEvents();
			this.refreshLatestData().finally(() => this.loadData());
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

			document.getElementById('rl-fsbi-currency-filter')?.addEventListener('change', function() {
				self.loadData();
			});

			document.getElementById('rl-fsbi-start-date')?.addEventListener('change', function() {
				self.loadData();
			});

			document.getElementById('rl-fsbi-end-date')?.addEventListener('change', function() {
				self.loadData();
			});

			document.getElementById('rl-fsbi-export-monthly-csv')?.addEventListener('click', function() {
				self.exportMonthlyCsv();
			});

			document.getElementById('rl-fsbi-export-3yr-csv')?.addEventListener('click', function() {
				self.export3YearCsv();
			});
		},

		toISODate: function(dateObj) {
			const pad = (v) => String(v).padStart(2, '0');
			return `${dateObj.getFullYear()}-${pad(dateObj.getMonth() + 1)}-${pad(dateObj.getDate())}`;
		},

		setText: function(id, value) {
			const node = document.getElementById(id);
			if (node) {
				node.textContent = value;
			}
		},

		setHtml: function(id, value) {
			const node = document.getElementById(id);
			if (node) {
				node.innerHTML = value;
			}
		},

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
				plugin_id: pluginId,
				currency: document.getElementById('rl-fsbi-currency-filter')?.value || 'all',
				start_date: document.getElementById('rl-fsbi-start-date')?.value || '',
				end_date: document.getElementById('rl-fsbi-end-date')?.value || '',
			};
		},

		getDisplayCurrency: function() {
			if (this.currentReportCurrency) {
				return this.currentReportCurrency;
			}
			const selectedCurrency = document.getElementById('rl-fsbi-currency-filter')?.value || rlFsbiAdmin.defaultCurrency || 'USD';
			return selectedCurrency === 'all' ? (rlFsbiAdmin.defaultCurrency || 'USD') : selectedCurrency;
		},

		formatCurrency: function(value, currency) {
			const safe = Number.isFinite(Number(value)) ? Number(value) : 0;
			return new Intl.NumberFormat(rlFsbiAdmin.localeFormat || 'en-US', {
				style: 'currency',
				currency: currency || this.getDisplayCurrency(),
				maximumFractionDigits: 2,
			}).format(safe);
		},

		formatPercent: function(value, decimals = 1) {
			const safe = Number.isFinite(Number(value)) ? Number(value) : 0;
			return `${safe.toFixed(decimals)}%`;
		},

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

		loadData: function() {
			const self = this;
			const filters = this.getFilters();

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_get_dashboard_data',
					nonce: rlFsbiAdmin.nonce,
					...filters,
				}),
			})
			.then((response) => response.json())
			.then((res) => {
				if (!res.success) {
					alert('Error: ' + (res.data || 'Failed to load dashboard data'));
					return;
				}

				self.renderDashboard(res.data || {});
			})
			.catch((error) => {
				console.error('Error loading dashboard data:', error);
				alert('Failed to load dashboard data.');
			});
		},

		renderDashboard: function(data) {
			this.currentReportCurrency = data.report_currency || data?.summary?.report_currency || null;
			this.updatePageHeader(data);
			this.updateTopCards(data);
			this.updateMiniKpis(data);
			this.updateMiniStats(data);
			this.updateTrialPanel(data);
			this.updateCharts(data);
			this.updateMonthlyTable(data);
		},

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

		updateTopCards: function(data) {
			const currency = this.getDisplayCurrency();
			const summary = data.summary || {};
			const netRevenue = Number(summary.net_revenue || 0);
			const previousNet = Number(summary.previous_net_revenue || 0);
			const deltaPct = previousNet > 0 ? (((netRevenue - previousNet) / previousNet) * 100) : 0;
			const deltaSign = deltaPct > 0 ? '+' : '';

			this.setText('rl-fsbi-net-revenue', this.formatCurrency(netRevenue, currency));
			this.setText('rl-fsbi-net-comparison', `${deltaSign}${deltaPct.toFixed(1)}% vs previous period`);
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

		updateMiniKpis: function(data) {
			const currency = this.getDisplayCurrency();
			const kpis = data.kpis || {};
			const mrr = Number(kpis.mrr_converted ?? kpis.mrr ?? 0);
			const arr = Number(kpis.arr_converted ?? kpis.arr ?? (mrr * 12));

			this.setText('rl-fsbi-mrr', this.formatCurrency(mrr, currency));
			this.setText('rl-fsbi-arr', this.formatCurrency(arr, currency));
			this.setText('rl-fsbi-mrr-as-of', `As of ${kpis.metric_as_of || 'selected period end'}`);
			this.setText('rl-fsbi-arr-as-of', `Annualized from ${kpis.metric_as_of || 'selected period end'}`);
			this.setText('rl-fsbi-aov', this.formatCurrency(Number(kpis.aov || 0), currency));
			this.setText('rl-fsbi-refund-rate', this.formatPercent(Number(kpis.refund_rate || 0), 1));
			this.setText('rl-fsbi-churn-rate', this.formatPercent(Number(kpis.churn_rate || 0), 1));
			this.setText('rl-fsbi-health-score', String(Math.round(Number(kpis.health_score || 0))));
			this.setText('rl-fsbi-portfolio-revenue', this.formatCurrency(Number(data.summary?.net_revenue || 0), currency));
			this.setText('rl-fsbi-portfolio-mrr', this.formatCurrency(mrr, currency));
			this.setText('rl-fsbi-portfolio-arr', this.formatCurrency(arr, currency));
			this.setText('rl-fsbi-portfolio-subs', Number(kpis.active_subscriptions || 0).toLocaleString());
			this.setText('rl-fsbi-portfolio-health', `${Math.round(Number(kpis.health_score || 0))}/100`);
		},

		updateMiniStats: function(data) {
			const stats = data.stats || {};
			this.setText('rl-fsbi-stat-purchases', Number(stats.purchases || 0).toLocaleString());
			this.setText('rl-fsbi-stat-trials-conversions', `${Number(stats.trials || 0)}/${Number(stats.conversions || 0)}`);
			this.setText('rl-fsbi-stat-refunds', Number(stats.refunds || 0).toLocaleString());
			this.setText('rl-fsbi-stat-renewals', Number(stats.renewals || 0).toLocaleString());
		},

		updateTrialPanel: function(data) {
			const trial = data.trial_conversion || {};
			this.setText('rl-fsbi-trials-total', Number(trial.total_trials || 0).toLocaleString());
			this.setText('rl-fsbi-trials-converted', Number(trial.converted || 0).toLocaleString());
			this.setText('rl-fsbi-trials-rate', this.formatPercent(Number(trial.rate || 0), 1));
		},

		updateCharts: function(data) {
			const charts = data.charts || {};
			const currency = this.getDisplayCurrency();

			console.log(charts.sales_activity)

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

			this.drawComboChart('churn', 'rl-fsbi-churn-chart', charts.churn_trend?.labels || [], charts.churn_trend?.canceled || [], charts.churn_trend?.churn_rate || []);
			this.drawDoughnutChart('currency', 'rl-fsbi-currency-chart', charts.currency_distribution?.labels || [], charts.currency_distribution?.values || [], currency);
			this.drawDoughnutChart('country', 'rl-fsbi-country-chart', charts.country_distribution?.labels || [], charts.country_distribution?.values || [], null, false);
			this.drawDoughnutChart('plan', 'rl-fsbi-plan-chart', charts.plan_distribution?.labels || [], charts.plan_distribution?.values || [], null, false);
			this.drawLineChart('wporg', 'rl-fsbi-wporg-chart', charts.wporg_growth?.labels || [], [
				{ label: 'Daily Downloads / Activity', data: charts.wporg_growth?.values || [], borderColor: '#22c89b', backgroundColor: 'rgba(34,200,155,0.18)' },
			], true);
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
									const formatted = key === 'forecast' || key === 'portfolio'
										? this.formatCurrency(value, this.getDisplayCurrency())
										: value.toLocaleString();
									return `${ctx.dataset.label}: ${formatted}`;
								},
							},
						},
					},
					scales: {
						x: { ticks: { color: '#9ca8d6' }, grid: { color: 'rgba(255,255,255,0.08)' } },
						y: { beginAtZero: true, ticks: { color: '#9ca8d6', maxTicksLimit: 8 }, grid: { color: 'rgba(255,255,255,0.08)' } },
					},
				},
			});
		},

		drawBarChart: function(key, canvasId, labels, datasets, currency) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts[key]) {
				this.charts[key].destroy();
			}

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

		drawComboChart: function(key, canvasId, labels, bars, line) {
			const canvas = document.getElementById(canvasId);
			if (!canvas || !window.Chart) {
				return;
			}

			if (this.charts[key]) {
				this.charts[key].destroy();
			}

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
						legend: {
							labels: { color: '#d7defd' },
							position: key === 'country' ? 'right' : 'bottom',
						},
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
		},

		updateMonthlyTable: function(data) {
			const table = document.getElementById('rl-fsbi-monthly-table');
			if (!table) {
				return;
			}

			const tbody = table.querySelector('tbody');
			const tableData = data.table || {};
			const rows = tableData.rows || [];
			const totals = tableData.totals || {};
			const reportCurrency = this.getDisplayCurrency();

			this.monthlyTableData = tableData;

			tbody.innerHTML = '';

			if (!rows.length) {
				tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;">No monthly data for selected filters.</td></tr>';
			} else {
				rows.forEach((row) => {
					const tr = document.createElement('tr');
					if (row.is_next_payout) {
						tr.classList.add('rl-fsbi-month-next');
					}
					if (row.is_current_payout) {
						tr.classList.add('rl-fsbi-month-current');
					}

					const payoutTotalText = Number(row.payout_total || 0) > 0
						? this.formatCurrency(Number(row.payout_total || 0), reportCurrency)
						: 'Carryover';
					const badges = [
						row.show_period_badges && row.is_next_payout ? `<span class="rl-fsbi-month-badge rl-fsbi-badge-next">NEXT PAYOUT ${payoutTotalText}</span>` : '',
						row.show_period_badges && row.is_current_payout ? `<span class="rl-fsbi-month-badge rl-fsbi-badge-current">CURRENT PAYOUT ${payoutTotalText}</span>` : '',
					].join('');

					const periodCell = row.show_period_badges
						? `${row.period || row.month || ''} ${badges}`
						: '';

					const subsCell = [
						`<span class="rl-fsbi-subs-pill rl-fsbi-subs-new">${Number(row.new || 0)} NEW</span>`,
						`<span class="rl-fsbi-subs-pill rl-fsbi-subs-renew">${Number(row.renewals || 0)} RENEWALS</span>`,
					].join('');

					let payoutCell = '';
					if (row.show_payout) {
						const nativeBreakdown = row.payout_breakdown_native || {};
						const payoutBreakdown = Object.entries(row.payout_breakdown || {})
							.filter(([, value]) => Number(value || 0) > 0)
							.map(([code, value]) => {
								const isConverted = code !== reportCurrency;
								const nativeVal = nativeBreakdown[code];
								const formattedVal = this.formatCurrency(Number(value || 0), reportCurrency);
								const nativeFormatted = (nativeVal !== undefined && nativeVal !== null)
									? this.formatCurrency(Number(nativeVal || 0), code)
									: '';

								let iconHtml = '';
								if (isConverted) {
									const tooltip = nativeFormatted
										? `Converted from ${nativeFormatted} into ${reportCurrency}`
										: `Conversion Value (${code} \u2192 ${reportCurrency})`;

									iconHtml = ` <span class="rl-fsbi-fx-indicator" title="${tooltip}" data-tippy-content="${tooltip}"><svg class="rl-fsbi-fx-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg></span>`;
								}

								return `<div class="rl-fsbi-payout-line ${isConverted ? 'rl-fsbi-payout-converted' : ''}"><span class="rl-fsbi-payout-code">${code}${iconHtml}</span><strong>${formattedVal}</strong></div>`;
							})
							.join('');

						if (payoutBreakdown) {
							payoutCell = `${payoutBreakdown}<div class="rl-fsbi-payout-total">PAYOUT: ${this.formatCurrency(Number(row.payout_total || 0), reportCurrency)}</div>`;
						} else {
							payoutCell = '<span class="rl-fsbi-payout-carry">Below threshold, carryover.</span>';
						}
					}

					const rowCurrency = row.currency || reportCurrency;

					tr.innerHTML = [
						`<td>${periodCell}</td>`,
						`<td>${subsCell}</td>`,
						`<td>${this.formatCurrency(Number(row.gross || 0), rowCurrency)}</td>`,
						`<td class="rl-fsbi-negative">${this.formatCurrency(Number(row.refunds || 0), rowCurrency)}</td>`,
						`<td>${this.formatCurrency(Number(row.fees || 0), rowCurrency)}</td>`,
						`<td class="${Number(row.net || 0) >= 0 ? 'rl-fsbi-positive' : 'rl-fsbi-negative'}">${this.formatCurrency(Number(row.net || 0), rowCurrency)}</td>`,
						`<td><strong>${rowCurrency}</strong></td>`,
						`<td>${payoutCell || '-'}</td>`,
					].join('');

					tbody.appendChild(tr);
				});
			}

			this.setText('rl-fsbi-total-gross', this.formatCurrency(Number(totals.gross || 0), reportCurrency));
			this.setText('rl-fsbi-total-refunds', this.formatCurrency(Number(totals.refunds || 0), reportCurrency));
			this.setText('rl-fsbi-total-fees', this.formatCurrency(Number(totals.fees || 0), reportCurrency));
			this.setText('rl-fsbi-total-net', this.formatCurrency(Number(totals.net || 0), reportCurrency));
			this.setText('rl-fsbi-total-currency', totals.currency || reportCurrency);

			if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable && window.jQuery.fn.DataTable.isDataTable(table)) {
				window.jQuery(table).DataTable().destroy();
			}

			if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
				this.dataTable = window.jQuery(table).DataTable({
					pageLength: Number.parseInt(rlFsbiAdmin.tableRows || 25, 10),
					ordering: false,
					searching: false,
					lengthChange: false,
					info: false,
				});
			}

			if (typeof window.tippy === 'function') {
				try {
					window.tippy('.rl-fsbi-fx-indicator', {
						theme: 'light-border',
						placement: 'top',
						arrow: true,
					});
				} catch (e) {}
			}
		},

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
