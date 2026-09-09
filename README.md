# RL Freemius Business Intelligence

**Version:** 1.0.0  
**Author:** Rosendo Labs  
**License:** GPL-2.0+  
**Text Domain:** rl-freemius-bi

## Overview

RL Freemius Business Intelligence (FSBI) is a comprehensive WordPress plugin that provides an enterprise-grade Business Intelligence dashboard for tracking plugin sales, revenue, subscriptions, renewals, MRR, trial conversions, license usage, and net payouts across all Freemius-hosted plugins.

The plugin integrates directly with the Freemius REST API using HMAC-SHA256 authentication and provides real-time data synchronization, interactive dashboards with currency filtering, KPI tracking, trend analysis, and detailed transaction reports.

## Features

- **Multi-Plugin Dashboard:** Aggregate data across all your Freemius plugins or drill down into individual plugins
- **Freemius API Integration:** Native HMAC-SHA256 signed requests to Freemius REST endpoints
- **Real-Time Data Sync:** Scheduled hourly sync via WP-Cron with manual on-demand refresh capability
- **Multi-Currency Support:** Segregate and analyze revenue by USD, EUR, GBP, and other currencies
- **Interactive Filters:** One-click currency and plugin filters with date range selection
- **KPI Dashboards:** Visual progress bars, trend indicators, and percentage growth metrics
- **Revenue Analytics:** Daily/monthly trend charts, currency distribution pie charts
- **Transaction Reports:** DataTables-powered sortable payment history with status badges
- **Business Metrics:**
  - Gross Revenue (total transaction amount)
  - Net Revenue (after Freemius commission)
  - Monthly Recurring Revenue (MRR)
  - Active Subscription Count
  - Trial Conversion Tracking
  - Renewal vs. First-Time Purchase Analysis
  - Refund Tracking
- **Database Persistence:** 5 dedicated tables for payments, subscriptions, licenses, plans, and balances
- **Zero Data-Loss Policy:** Tables are never dropped on deactivation—historical data is preserved
- **PHP 8.2+ Ready:** Strict type handling, no dynamic properties, explicit float casting

## Installation

1. Upload the `rl-freemius-bi` folder to `/wp-content/plugins/`
2. Activate the plugin via the Plugins menu in WordPress admin
3. Navigate to **Freemius BI → Settings**
4. Enter your Freemius API credentials:
   - **Developer ID:** Your Freemius developer account ID
   - **Public API Key:** Public key (starts with `pk_`)
   - **Secret API Key:** Secret key (starts with `sk_`)
5. Save settings and click **Sync Now** to fetch your first batch of data

### Obtaining Freemius API Credentials

1. Visit [developer.freemius.com](https://developer.freemius.com)
2. Log in to your developer account
3. Navigate to **Account** → **REST API** (or similar section depending on Freemius UI)
4. Create or retrieve your public and secret API keys
5. Copy your Developer ID
6. Paste the credentials into the RL FSBI settings page

## Database Schema

The plugin creates 5 custom tables (with your site's `wp_` prefix):

### `wp_rl_fsbi_payments`
Stores all payment transactions from Freemius.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | Primary key |
| `plugin_id` | bigint | Freemius plugin ID |
| `payment_id` | bigint | Unique payment ID (UNIQUE) |
| `user_id` | bigint | Freemius user/customer ID |
| `subscription_id` | bigint | Subscription ID (nullable) |
| `transaction_date` | datetime | Payment timestamp |
| `gross` | decimal(10,2) | Gross transaction amount |
| `net` | decimal(10,2) | Net (after commission) |
| `fee` | decimal(10,2) | Freemius commission |
| `currency` | varchar(3) | ISO 4217 code (USD, EUR, etc.) |
| `payment_method` | varchar(50) | Payment gateway |
| `status` | varchar(20) | completed, pending, failed, etc. |
| `country_code` | varchar(2) | ISO 3166-1 alpha-2 code |
| `is_refund` | tinyint(1) | Boolean: is this a refund? |
| `refund_id` | bigint | Refund payment ID (if applicable) |
| `metadata` | longtext | JSON blob for future extension |
| `created_at` | datetime | Record creation timestamp |
| `updated_at` | datetime | Last update timestamp |

**Indexes:** plugin_id, user_id, transaction_date, currency, subscription_id

### `wp_rl_fsbi_subscriptions`
Stores active and historical subscription records.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | Primary key |
| `plugin_id` | bigint | Freemius plugin ID |
| `subscription_id` | bigint | Unique subscription ID (UNIQUE) |
| `user_id` | bigint | Customer ID |
| `plan_id` | bigint | Selected plan ID |
| `status` | varchar(20) | active, canceled, expired, etc. |
| `billing_cycle` | varchar(50) | monthly, annual, lifetime |
| `trial_ends` | datetime | Trial expiration (nullable) |
| `next_renewal` | datetime | Next billing date |
| `expires_at` | datetime | Subscription end date |
| `outstanding_balance` | decimal(10,2) | Due amount |
| `currency` | varchar(3) | Billing currency |
| `metadata` | longtext | Extra data (JSON) |
| `created_at` | datetime | Creation timestamp |
| `updated_at` | datetime | Update timestamp |

**Indexes:** plugin_id, user_id, status, next_renewal

### `wp_rl_fsbi_licenses`
Stores license activation and usage data.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | Primary key |
| `plugin_id` | bigint | Freemius plugin ID |
| `license_id` | bigint | Unique license ID (UNIQUE) |
| `user_id` | bigint | License owner |
| `plan_id` | bigint | Associated plan |
| `license_key` | varchar(255) | Activation key (UNIQUE) |
| `status` | varchar(20) | valid, expired, revoked |
| `quota_sites` | int(11) | Max sites allowed |
| `active_sites` | int(11) | Currently active sites |
| `expires_at` | datetime | License expiration |
| `created_at` | datetime | Creation timestamp |
| `updated_at` | datetime | Update timestamp |

**Indexes:** plugin_id, user_id, status, expires_at

### `wp_rl_fsbi_plans`
Stores pricing plan metadata.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | Primary key |
| `plugin_id` | bigint | Freemius plugin ID |
| `plan_id` | bigint | Unique plan ID (UNIQUE) |
| `plan_name` | varchar(255) | Human-readable name |
| `description` | longtext | Plan details |
| `price_usd` | decimal(10,2) | USD pricing |
| `price_eur` | decimal(10,2) | EUR pricing |
| `price_gbp` | decimal(10,2) | GBP pricing |
| `billing_cycle` | varchar(50) | Billing frequency |
| `trial_days` | int(11) | Trial period length |
| `features` | longtext | Feature list (JSON) |
| `created_at` | datetime | Creation timestamp |
| `updated_at` | datetime | Update timestamp |

**Indexes:** plugin_id

### `wp_rl_fsbi_balances`
Stores developer payout and balance information.

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint | Primary key |
| `app_id` | bigint | Freemius app ID (5172 for FSBI) |
| `developer_id` | bigint | Developer account ID |
| `processed_balance` | decimal(10,2) | Confirmed payout amount |
| `pending_balance` | decimal(10,2) | Awaiting payout |
| `commission_rate` | decimal(5,2) | Freemius commission % |
| `commission_amount` | decimal(10,2) | Total commission charged |
| `net_payout` | decimal(10,2) | Your earnings |
| `currency` | varchar(3) | Payout currency |
| `updated_at` | datetime | Last sync timestamp |

**Indexes:** developer_id, app_id

## Dashboard Pages

### Main Dashboard
**Menu:** Freemius BI → (main)

Displays:
- **KPI Cards:** Gross Revenue, Net Revenue, MRR, Active Subscriptions
- **Filter Controls:** Plugin selector, currency toggle, date range picker
- **Revenue Trend Chart:** Daily/monthly gross revenue line chart
- **Currency Distribution Chart:** Pie chart showing revenue by currency
- **Recent Payments Table:** DataTables-powered transaction history

### Settings Page
**Menu:** Freemius BI → Settings

Allows configuration of:
- Freemius Developer ID
- Public API Key
- Secret API Key

Shows:
- Database table listing
- API credential help guide
- Manual sync controls

## Freemius API Integration

### Endpoints Consumed

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/v1/developers/{id}/plugins.json` | GET | Fetch all plugins |
| `/v1/developers/{id}/plugins/{pid}/stats.json` | GET | Plugin statistics (LTV, customers, revenues) |
| `/v1/developers/{id}/plugins/{pid}/performance.json` | GET | Performance metrics (installs, active, MRR) |
| `/v1/developers/{id}/plugins/{pid}/payments.json` | GET | Payment transactions (paginated) |
| `/v1/developers/{id}/plugins/{pid}/subscriptions.json` | GET | Subscription records (paginated) |
| `/v1/developers/{id}/plugins/{pid}/licenses.json` | GET | License activations (paginated) |
| `/v1/developers/{id}/plugins/{pid}/plans.json` | GET | Pricing plan data |
| `/v1/developers/{id}/plugins/{pid}/revenues.json` | GET | Time-series revenue data |
| `/v1/apps/{app_id}/developers/{id}/balance.json` | GET | Account balance and payout info |

### Authentication Scheme

All requests are signed using **HMAC-SHA256** per Freemius specifications:

```
Authorization: FS {developer_id}:{public_key}:{signature}

Signature = Base64(HMAC-SHA256(
    secret_key,
    StringToSign
))

StringToSign = 
    HTTP_METHOD + "\n" +
    Content-MD5 + "\n" +
    Content-Type + "\n" +
    Date (RFC 2822) + "\n" +
    CanonicalizedResource (path + query)
```

### Pagination

Collection endpoints support:
- `count` (1–50, default 25)
- `offset` (pagination offset)

The plugin automatically loops through all pages until all records are fetched.

### Retry Logic

Failed requests use exponential backoff:
- Attempt 1: immediate
- Attempt 2: 1 second wait
- Attempt 3: 2 seconds wait
- Attempt 4: 4 seconds wait

## Business Logic Definitions

### New Purchase vs. Renewal
A payment is classified as a **Renewal** if the database contains prior payments for the same subscription/user ID before the transaction date. Otherwise, it's a **First-Time Purchase**.

### Refunds
Payments where `gross < 0` or linked to a refund transaction ID are flagged as `is_refund = 1`.

### Trial Conversions
Subscriptions where `trial_ends` is populated and subsequent successful payments occurred after trial end.

### Multi-Currency Segregation
All financial metrics (gross, net, MRR) are computed separately per currency. Avoid summing mixed currencies.

## WP-Cron Scheduling

By default, the plugin schedules an hourly sync event: `rl_fsbi_scheduled_sync`

To manually trigger a sync:
```bash
wp cron event run rl_fsbi_scheduled_sync
```

Or use the **Sync Now** button in the admin dashboard.

## Admin AJAX Endpoints

### `wp_ajax_rl_fsbi_sync_data`
Manually triggers data synchronization with Freemius API.

**Parameters:**
- `nonce` (required): Security token from `wp_create_nonce( 'rl_fsbi_nonce' )`

**Response:**
```json
{
  "success": true,
  "data": {
    "message": "Data synchronized successfully"
  }
}
```

### `wp_ajax_rl_fsbi_get_dashboard_data`
Fetches dashboard metrics and transaction data for display.

**Parameters:**
- `nonce` (required)
- `plugin_id` (optional): Specific plugin or `all`
- `currency` (optional): Filter by currency or `all`
- `start_date` (optional): YYYY-MM-DD format
- `end_date` (optional): YYYY-MM-DD format

**Response:**
```json
{
  "success": true,
  "data": {
    "gross_revenue": 5000.00,
    "net_revenue": 3500.00,
    "mrr": 1200.50,
    "active_subscriptions": 42,
    "payments": [...],
    "revenue_trend": {"2024-01-01": 500, ...},
    "currency_distribution": {"USD": 4000, "EUR": 1000}
  }
}
```

## Hooks and Filters

### Actions

**`rl_fsbi_scheduled_sync`**
Fired when hourly cron task runs. Use this to trigger custom sync logic.

```php
add_action( 'rl_fsbi_scheduled_sync', function() {
    // Custom sync logic
});
```

### Filters

More to be added in future versions.

## JavaScript API

Dashboard UI exposes `window.FSBI` object:

```javascript
FSBI.syncData()              // Manually trigger sync
FSBI.loadData()              // Refresh dashboard data
FSBI.getFilters()            // Get current filter values
```

## PHP 8.2+ Compatibility

- All class properties are explicitly declared
- All database values are explicitly cast to `(float)` before arithmetic operations
- No dynamic properties are created
- Strict type hints are used throughout

## Security Considerations

- **API Keys:** Stored in `wp_options` table. Protect your database!
- **Nonces:** All AJAX requests require nonces
- **Capability Checks:** Admin actions require `manage_options` capability
- **Database Queries:** All queries use `$wpdb->prepare()` with placeholders
- **Input Sanitization:** All user input is sanitized with `sanitize_text_field()` or `wp_kses_post()`
- **Output Escaping:** All output is escaped with `esc_html()`, `esc_attr()`, etc.

## Performance Tips

1. **Limit Date Ranges:** Use narrow date filters in the dashboard to reduce data load
2. **Batch Processing:** The sync runs in hourly batches; adjust via `wp_schedule_event()` if needed
3. **Database Indexing:** Indexes are automatically created on common query columns
4. **Pagination:** Table view defaults to 25 rows per page

## Troubleshooting

### "Sync failed. Check your API credentials."
- Verify Developer ID, public key, and secret key in Settings
- Ensure your Freemius account has REST API access enabled
- Check that your IP is not blocked by Freemius

### No data appears in dashboard
- Click **Sync Now** button to manually trigger data fetch
- Check browser console for JavaScript errors
- Verify database tables were created: `SELECT * FROM wp_rl_fsbi_payments;`

### "Unauthorized" error
- Ensure you're logged in as an admin
- Check browser console for 403 Forbidden responses
- Verify nonce is being passed in AJAX requests

## Support

For issues and feature requests, please refer to your plugin documentation or contact Rosendo Labs.

## License

GPL-2.0+ - See LICENSE.txt for details

## Changelog

### 1.0.0 (2024-01-15)
- Initial release
- Freemius API integration with HMAC-SHA256 signing
- Multi-currency dashboard with interactive filters
- Database schema and WP-Cron synchronization
- Admin settings and KPI tracking
- Chart.js and DataTables integration
