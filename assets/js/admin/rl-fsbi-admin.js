/**
 * RL Freemius BI Admin Dashboard JavaScript
 */

(function() {
	'use strict';

	const FSBI = {
		charts: {},
		dataTable: null,

		init: function() {
			this.bindEvents();
			this.loadInitialData();
		},

		bindEvents: function() {
			const self = this;

			// Sync button
			document.getElementById('rl-fsbi-sync-btn')?.addEventListener('click', function() {
				self.syncData();
			});

			// Filters
			document.getElementById('rl-fsbi-plugin-filter')?.addEventListener('change', function() {
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
		},

		getFilters: function() {
			return {
				plugin_id: document.getElementById('rl-fsbi-plugin-filter')?.value || 'all',
				currency: document.getElementById('rl-fsbi-currency-filter')?.value || 'all',
				start_date: document.getElementById('rl-fsbi-start-date')?.value || '',
				end_date: document.getElementById('rl-fsbi-end-date')?.value || '',
			};
		},

		loadInitialData: function() {
			this.loadData();
		},

		loadData: function() {
			const self = this;
			const filters = this.getFilters();

			// Fetch data via AJAX
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
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					self.updateKPIs(data.data);
					self.updateCharts(data.data);
					self.updateTable(data.data);
				}
			})
			.catch(error => console.error('Error loading data:', error));
		},

		updateKPIs: function(data) {
			const formatCurrency = (value, currency = 'USD') => {
				return new Intl.NumberFormat('en-US', {
					style: 'currency',
					currency: currency,
				}).format(value);
			};

			// Gross Revenue
			const grossRevenue = document.getElementById('rl-fsbi-gross-revenue');
			if (grossRevenue) {
				grossRevenue.textContent = formatCurrency(data.gross_revenue || 0);
			}

			// Net Revenue
			const netRevenue = document.getElementById('rl-fsbi-net-revenue');
			if (netRevenue) {
				netRevenue.textContent = formatCurrency(data.net_revenue || 0);
			}

			// MRR
			const mrr = document.getElementById('rl-fsbi-mrr');
			if (mrr) {
				mrr.textContent = formatCurrency(data.mrr || 0);
			}

			// Active Subscriptions
			const activeSubs = document.getElementById('rl-fsbi-active-subs');
			if (activeSubs) {
				activeSubs.textContent = (data.active_subscriptions || 0).toLocaleString();
			}
		},

		updateCharts: function(data) {
			this.updateRevenueChart(data);
			this.updateCurrencyChart(data);
		},

		updateRevenueChart: function(data) {
			const ctx = document.getElementById('rl-fsbi-revenue-chart');
			if (!ctx) return;

			// Destroy existing chart if it exists
			if (this.charts.revenue) {
				this.charts.revenue.destroy();
			}

			const chartData = data.revenue_trend || {};
			const labels = Object.keys(chartData).sort();
			const values = labels.map(date => chartData[date] || 0);

			this.charts.revenue = new Chart(ctx, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [{
						label: 'Daily Gross Revenue',
						data: values,
						borderColor: '#1f8d5c',
						backgroundColor: 'rgba(31, 141, 92, 0.1)',
						borderWidth: 2,
						fill: true,
						tension: 0.4,
					}],
				},
				options: {
					responsive: true,
					plugins: {
						legend: {
							display: true,
							position: 'top',
						},
					},
					scales: {
						y: {
							beginAtZero: true,
							ticks: {
								callback: function(value) {
									return '$' + value.toFixed(0);
								},
							},
						},
					},
				},
			});
		},

		updateCurrencyChart: function(data) {
			const ctx = document.getElementById('rl-fsbi-currency-chart');
			if (!ctx) return;

			// Destroy existing chart if it exists
			if (this.charts.currency) {
				this.charts.currency.destroy();
			}

			const currencyData = data.currency_distribution || {};
			const labels = Object.keys(currencyData);
			const values = labels.map(currency => currencyData[currency] || 0);

			const colors = ['#1f8d5c', '#0288d1', '#fbc02d', '#e91e63'];

			this.charts.currency = new Chart(ctx, {
				type: 'doughnut',
				data: {
					labels: labels,
					datasets: [{
						data: values,
						backgroundColor: colors.slice(0, labels.length),
						borderColor: '#fff',
						borderWidth: 2,
					}],
				},
				options: {
					responsive: true,
					plugins: {
						legend: {
							position: 'bottom',
						},
						tooltip: {
							callbacks: {
								label: function(context) {
									const label = context.label || '';
									const value = '$' + context.parsed.toFixed(2);
									const total = context.dataset.data.reduce((a, b) => a + b, 0);
									const percentage = ((context.parsed / total) * 100).toFixed(1);
									return `${label}: ${value} (${percentage}%)`;
								},
							},
						},
					},
				},
			});
		},

		updateTable: function(data) {
			const table = document.getElementById('rl-fsbi-payments-table');
			if (!table) return;

			const tbody = table.querySelector('tbody');
			const payments = data.payments || [];

			// Clear existing rows
			tbody.innerHTML = '';

			if (payments.length === 0) {
				tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px;">No payments found for selected filters.</td></tr>';
				return;
			}

			payments.forEach(payment => {
				const row = document.createElement('tr');
				const date = new Date(payment.transaction_date).toLocaleDateString();
				const statusClass = 'rl-fsbi-status ' + payment.status;

				row.innerHTML = `
					<td>${payment.payment_id}</td>
					<td>${date}</td>
					<td>$${parseFloat(payment.gross).toFixed(2)}</td>
					<td>$${parseFloat(payment.net).toFixed(2)}</td>
					<td>${payment.currency}</td>
					<td><span class="${statusClass}">${payment.status}</span></td>
				`;

				tbody.appendChild(row);
			});

			// Reinitialize DataTable if it exists
			if (this.dataTable) {
				this.dataTable.destroy();
			}

			this.dataTable = new DataTable(table, {
				pageLength: 25,
				order: [[1, 'desc']],
			});
		},

		syncData: function() {
			const btn = document.getElementById('rl-fsbi-sync-btn');
			if (!btn) return;

			const originalText = btn.textContent;
			btn.disabled = true;
			btn.textContent = 'Syncing...';

			fetch(rlFsbiAdmin.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: new URLSearchParams({
					action: 'rl_fsbi_sync_data',
					nonce: rlFsbiAdmin.nonce,
				}),
			})
			.then(response => response.json())
			.then(data => {
				btn.disabled = false;
				btn.textContent = originalText;

				if (data.success) {
					alert(data.data.message);
					this.loadData();
				} else {
					alert('Error: ' + data.data);
				}
			})
			.catch(error => {
				btn.disabled = false;
				btn.textContent = originalText;
				console.error('Sync error:', error);
				alert('An error occurred during sync. Check console for details.');
			});
		},
	};

	// Initialize when DOM is ready
	document.addEventListener('DOMContentLoaded', function() {
		FSBI.init();
	});

	// Expose FSBI to global scope for debugging
	window.FSBI = FSBI;

})();
