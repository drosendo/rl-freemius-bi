# Currency & Localization Settings

## Overview

The RL Freemius BI plugin includes three optional settings for currency formatting and conversion. These match the configuration in the premium `fsbi-premium` plugin.

## Available Settings

### 1. Locale Currency Format
**Field:** `rl_fsbi_locale_format`  
**Type:** Text  
**Default:** `us-US`  
**Description:** Language-sensitive number formatting for currency display

**Examples:**
- `us-US` — 1,234.56 USD (United States)
- `pt-PT` — 1.234,56 USD (Portugal)
- `de-DE` — 1.234,56 EUR (Germany)
- `ja-JP` — ¥1,234 JPY (Japan)
- `fr-FR` — 1 234,56 EUR (France)
- `en-GB` — £1,234.56 GBP (United Kingdom)

**Usage:** This setting affects how numbers are formatted on the dashboard using JavaScript's `Intl.NumberFormat` API.

---

### 2. Transferwise Token
**Field:** `rl_fsbi_transferwise_token`  
**Type:** Password  
**Default:** (empty)  
**Description:** API token for Transferwise currency conversion service

**How to Get Your Token:**
1. Visit https://wise.com/developer/api
2. Log in with your Wise (formerly Transferwise) account
3. Go to API Tokens section
4. Generate a new API token
5. Copy and paste it into this field

**Usage:** When filled in, the plugin can convert currencies using Wise's real-time exchange rates for accurate payout reporting.

---

### 3. Transferwise Conversion Currency
**Field:** `rl_fsbi_conversion_currency`  
**Type:** Select  
**Default:** `EUR`  
**Options:** EUR, USD, GBP  
**Description:** Base currency for payout display when using Transferwise conversion

**Effect:** When syncing payments from Freemius, all amounts are converted to this base currency for unified reporting:
- **EUR** (Euro) — Default, useful for European developers
- **USD** (US Dollar) — Default for US-based developers
- **GBP** (British Pound) — Default for UK-based developers

**Example:** If your plugin generates revenue in USD, EUR, and GBP, and you set this to EUR, all transactions will be displayed in EUR equivalents on the dashboard.

---

## How Sync Works

The plugin automatically syncs data from Freemius every **hour** if API credentials are configured:

1. **Automatic Sync (Hourly):** WordPress WP-Cron job runs at hourly intervals
   - Reads Freemius API credentials from Settings
   - Fetches payments, subscriptions, and licenses
   - Stores data in plugin database tables

2. **Manual Sync:** Click the "Sync Now" button on the Dashboard
   - Immediately fetches latest data from Freemius API
   - Useful after adding credentials to populate initial data

3. **Initial Setup:** After adding credentials, data won't appear until:
   - You manually click "Sync Now" button, OR
   - Wait for the next hourly cron execution

---

## Required for Functionality

| Setting | Required? | Impact if Missing |
|---------|-----------|-------------------|
| Freemius API Credentials | ✅ Yes | No data will sync |
| Locale Currency Format | ❌ No | Uses default `us-US` formatting |
| Transferwise Token | ❌ No | Currency stays as original |
| Transferwise Currency | ❌ No | Not used without token |

---

## Troubleshooting

### "Nothing shows up after adding credentials"
1. Check that all three Freemius credentials are filled:
   - Developer ID
   - Public Key
   - Secret Key
2. Click "Sync Now" button to manually trigger sync
3. Wait up to 1 hour for automatic sync to run
4. Check WordPress error logs for API errors

### "Currency formatting looks wrong"
1. Verify Locale Currency Format is set correctly
2. Ensure the format follows `xx-XX` pattern (e.g., `en-US`, `pt-PT`)
3. Clear browser cache to reload JavaScript formatting

### "Currency conversion not working"
1. Verify Transferwise Token is valid and active
2. Ensure Transferwise Conversion Currency is selected
3. Check that Wise API account has not been suspended
4. Verify your Wise API token has proper permissions

---

## Integration with Premium Version

These settings replicate the configuration options in `wp-content/plugins/fsbi-premium`:
- Locale format → Display language-sensitive numbers
- Transferwise token → Currency conversion via Wise API
- Conversion currency → Base currency for unified reporting

Both plugins follow the same configuration pattern for consistency.

---

## Access via Code

Settings are automatically loaded in the Admin class constructor:

```php
// In RL_FSBI_Admin class
$this->locale_format = $this->settings->get_option( 'rl_fsbi_locale_format' );
$this->transferwise_token = $this->settings->get_option( 'rl_fsbi_transferwise_token' );
$this->conversion_currency = $this->settings->get_option( 'rl_fsbi_conversion_currency' );

// Accessor methods
$admin->get_locale_format();
$admin->get_transferwise_token();
$admin->get_conversion_currency();
```

Use these methods to access settings in dashboard rendering or AJAX handlers.
