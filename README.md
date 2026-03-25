# QuickBooks Payments Module for Zen Cart

A payment gateway module that integrates Intuit QuickBooks Payments with Zen Cart, enabling your store to accept credit card payments processed through the QuickBooks Payments API.

## Features

- **Credit Card Processing** - Accept Visa, Mastercard, American Express, and Discover
- **Authorize & Capture** - Choose between immediate charge or authorize-only (capture later)
- **Void Transactions** - Cancel authorized or captured payments before settlement
- **Refunds** - Full or partial refunds on captured transactions
- **OAuth 2.0** - Secure token-based authentication with automatic token refresh
- **Encrypted Storage** - OAuth tokens and client secrets encrypted with AES-256-CBC in the database
- **AVS Verification** - Optional Address Verification System (AVS) checking with automatic void on mismatch
- **CVV Validation** - Optional Card Verification Value requirement
- **PCI Compliant** - Card data tokenized before charging; full card numbers never stored; CVV never persisted
- **Decline Notifications** - Optional email alerts to store owner when transactions are declined
- **Debug Logging** - Configurable logging with automatic sensitive data masking
- **Admin Transaction Panel** - View transaction history, capture, void, and refund directly from the Zen Cart admin order page
- **Standalone Admin Tool** - Dedicated admin page for quick void/refund operations
- **Sandbox Support** - Test with Intuit's sandbox environment before going live
- **Checkout Enhancements** - Real-time card type detection, number formatting, and inline Luhn validation

## Requirements

- **Zen Cart** 2.0.0 or later (tested on 2.1.0)
- **PHP** 7.4 or later (8.x recommended)
- **PHP Extensions:**
  - `curl` with SSL support
  - `openssl`
  - `json`
- **SSL Certificate** - Your store must have an active SSL certificate (required by Intuit's Terms of Service and PCI DSS)
- **Intuit Developer Account** - Free account at [developer.intuit.com](https://developer.intuit.com)

## File Structure

```
YOUR_ADMIN/
    quickbooks_admin.php                          -- Admin void/refund tool

includes/
    modules/
        payment/
            quickbooks_payments.php               -- Core payment module

    languages/
        english/
            modules/
                payment/
                    lang.quickbooks_payments.php   -- Language definitions
```

## Installation

### Step 1: Upload Files

Upload the plugin files to your Zen Cart installation, preserving the directory structure:

1. Copy `includes/modules/payment/quickbooks_payments.php` to your Zen Cart's `includes/modules/payment/` directory.

2. Copy `includes/languages/english/modules/payment/lang.quickbooks_payments.php` to your Zen Cart's `includes/languages/english/modules/payment/` directory.

3. Copy `YOUR_ADMIN/quickbooks_admin.php` to your Zen Cart's **admin folder**. This is the folder with the unique name you chose during Zen Cart installation (e.g., `admin_XXXXX/`). Do **not** create a folder called `YOUR_ADMIN`.

### Step 2: Create an Intuit Developer App

1. Go to [developer.intuit.com](https://developer.intuit.com) and sign in (or create an account).
2. Navigate to **My Apps** > **Create an app**.
3. Select **QuickBooks Online Payments** as the platform.
4. Under **Keys & credentials**, note your:
   - **Client ID**
   - **Client Secret**
5. Set your **Redirect URI** to a page you control (this is used during the one-time OAuth authorization flow to obtain your initial tokens).
6. Under **Scopes**, ensure **Payments** (`com.intuit.quickbooks.payment`) is enabled.

### Step 3: Obtain OAuth Tokens

You need an initial **Access Token** and **Refresh Token**. The simplest way:

1. In the Intuit Developer portal, go to your app's settings.
2. Use the **OAuth 2.0 Playground** (available in the developer portal) to authorize your app and obtain tokens.
3. Select the **Payments** scope.
4. Complete the authorization flow.
5. Copy the **Access Token** and **Refresh Token** from the playground.

> **Note:** Access tokens expire after 1 hour. The module automatically refreshes them using the refresh token. Refresh tokens expire after 100 days, so you must re-authorize before they expire. The module will log errors when refresh fails.

### Step 4: Enable the Module in Zen Cart

1. Log in to your Zen Cart admin panel.
2. Go to **Modules** > **Payment**.
3. Find **QuickBooks Payments** in the list and click **Install**.
4. Configure the module settings (see Configuration section below).

### Step 5: Verify Encryption Key

On first use, the module creates an encryption key file (`qb_payments.key`) in your Zen Cart cache directory (`DIR_FS_SQL_CACHE`). Verify:

- The key file exists and is readable by the web server.
- File permissions are set to `0600` (owner read/write only).
- The cache directory is **outside your web root** for best security.

If you see encryption warnings in your PHP error log, check these permissions.

## Configuration

After installation, configure these settings in **Modules** > **Payment** > **QuickBooks Payments**:

| Setting | Description | Default |
|---------|-------------|---------|
| **Enable QuickBooks Payments** | Activate/deactivate the module | True |
| **Test Mode** | `Sandbox` for testing, `Production` for live transactions | Sandbox |
| **Client ID** | Your Intuit app's Client ID | (empty) |
| **Client Secret** | Your Intuit app's Client Secret (encrypted on save) | (empty) |
| **Access Token** | OAuth Access Token (auto-refreshed by the module) | (empty) |
| **Refresh Token** | OAuth Refresh Token (used to obtain new access tokens) | (empty) |
| **Token Expiry** | Auto-managed Unix timestamp for token expiration | 0 |
| **Authorization Type** | `Authorize` (capture later) or `Authorize/Capture` (charge immediately) | Authorize/Capture |
| **Request CVV Number** | Require the card's CVV/CVC security code | True |
| **Require AVS Match** | Reject transactions where address verification fails (auto-voids on mismatch) | True |
| **Decline Email Notification** | Email the store owner when a transaction is declined | True |
| **Debug Mode** | `Off`, `Log File`, or `Log and Email` | Off |
| **Set Order Status** | Order status after successful payment | Default |
| **Payment Zone** | Restrict to a specific geographic zone | All Zones |
| **Sort Order** | Display order among payment methods | 0 |

### Important Configuration Notes

- **Client Secret, Access Token, and Refresh Token** are automatically encrypted with AES-256-CBC when saved. You can enter them as plain text; the module encrypts them on first load.
- **Test Mode** must be set to `Production` before accepting real payments. Always test in `Sandbox` first.
- **AVS Check** is highly recommended for fraud protection. When enabled, if the billing address does not match the card, the module automatically voids/refunds the charge and shows the customer an appropriate message.

## Testing with Sandbox

1. Set **Test Mode** to `Sandbox` in the module configuration.
2. Use the Intuit sandbox test card numbers:
   - **Visa:** 4111 1111 1111 1111
   - **Mastercard:** 5500 0000 0000 0004
   - **Amex:** 3400 0000 0000 009
   - **Discover:** 6011 0000 0000 0004
3. Use any future expiration date and any 3-digit CVV (4-digit for Amex).
4. Place a test order and verify the transaction appears in your Intuit Developer sandbox dashboard.
5. Test void and refund operations from the admin panel.
6. When satisfied, switch to `Production` mode and enter your production OAuth credentials.

## Admin Features

### Order Details Panel

When viewing an order in the Zen Cart admin (**Customers** > **Orders** > select an order), the module displays:

- **Transaction History** - All transactions (auth, capture, void, refund) with timestamps, status, charge IDs, and auth codes.
- **Capture Button** - For authorize-only transactions, capture the payment.
- **Void Button** - Cancel an authorized or captured payment.
- **Refund Form** - Issue a full or partial refund with a configurable amount.

Each action requires checking a confirmation checkbox before submitting.

### Standalone Admin Tool (Optional)

The `quickbooks_admin.php` file provides a standalone page for quick transaction management:

1. Access it at `https://yoursite.com/YOUR_ADMIN_FOLDER/quickbooks_admin.php`
2. Enter an Order ID to look up transaction details.
3. Void or refund transactions directly from this page.

This tool requires admin login and includes CSRF protection. Void and refund actions require a browser confirmation dialog before processing.

## Database

The module creates a `quickbooks_payments` table on installation to store transaction records:

| Column | Type | Description |
|--------|------|-------------|
| id | int (PK) | Auto-increment ID |
| customer_id | int | Zen Cart customer ID |
| order_id | int | Zen Cart order ID |
| trans_type | varchar(50) | Auth, Sale, Capture, Void, or Refund |
| response_code | varchar(10) | 0 = success, 1 = failure |
| status | varchar(50) | API status (AUTHORIZED, CAPTURED, VOIDED, etc.) |
| message | text | Error message (if any) |
| auth_code | varchar(50) | Authorization code from gateway |
| charge_id | varchar(100) | Intuit charge ID |
| capture_id | varchar(100) | Intuit capture/refund ID |
| request_id | varchar(100) | Unique request ID (for idempotency) |
| dtime | datetime | Transaction timestamp |
| session_id | varchar(255) | PHP session ID |

Configuration settings are stored in the standard Zen Cart `configuration` table.

## Security

- **Encryption:** OAuth tokens and client secret are encrypted at rest using AES-256-CBC with a key stored outside the web root.
- **PCI Compliance:** Card numbers are tokenized via the Intuit API before any charge is made. Full card numbers are never stored in the database. CVV is never persisted after authorization.
- **CSRF Protection:** The admin tool uses session-based CSRF tokens on all state-changing forms.
- **SSL Enforcement:** All API communication uses TLS with certificate verification enforced.
- **Input Sanitization:** All database inputs use Zen Cart's `zen_db_input()` or integer casting. All HTML output uses `htmlspecialchars()`.
- **Sensitive Data Masking:** Debug logs automatically mask card numbers, tokens, CVV, and cardholder names.
- **Concurrent Token Refresh:** MySQL advisory locks prevent race conditions when multiple requests attempt to refresh the OAuth token simultaneously.

## Troubleshooting

### "Payment processing error: Unable to authenticate with payment gateway"
- Your access token has expired and the refresh token could not obtain a new one.
- Go to the Intuit Developer portal, re-authorize your app, and update the Access Token and Refresh Token in the module settings.
- Check that your Client ID and Client Secret are correct.

### "(Not Configured)" appears next to the module name in admin
- The Client ID or Client Secret is empty or invalid.
- Enter your credentials from the Intuit Developer portal.

### Transactions succeed in Sandbox but fail in Production
- Ensure **Test Mode** is set to `Production`.
- Verify you are using **production** OAuth credentials (not sandbox credentials).
- Confirm your Intuit app has been approved for production use.

### AVS failures causing declined transactions
- If legitimate customers are being declined due to address mismatches, you can disable **Require AVS Match** in the module settings.
- Note: Disabling AVS reduces fraud protection.

### Debug logging
- Set **Debug Mode** to `Log File` to write transaction details to your Zen Cart cache directory.
- Log files are named `QuickBooks_Debug_*.log` and `QuickBooks_Error_*.log`.
- Sensitive data (card numbers, tokens, CVV) is automatically masked in logs.
- Remember to set Debug Mode back to `Off` in production to conserve disk space.

### Encryption key errors
- If you see "encryption key unavailable" errors, check that the Zen Cart cache directory (`DIR_FS_SQL_CACHE`) is writable.
- If the key file was deleted, existing encrypted tokens will be unrecoverable. Re-enter your OAuth tokens in the module settings.

## Uninstallation

1. Go to **Modules** > **Payment** > **QuickBooks Payments**.
2. Click **Remove**. This will:
   - Delete all module configuration settings from the database.
   - Create a timestamped backup of the transaction table (e.g., `quickbooks_payments_backup_20260101_120000`).
   - Drop the `quickbooks_payments` table.
3. Delete the three plugin files from your server:
   - `includes/modules/payment/quickbooks_payments.php`
   - `includes/languages/english/modules/payment/lang.quickbooks_payments.php`
   - `YOUR_ADMIN_FOLDER/quickbooks_admin.php`
4. Optionally delete the encryption key file (`qb_payments.key`) from your cache directory.

## Version History

- **1.0** - Initial release
  - Full QuickBooks Payments API integration (tokenization, charges, voids, refunds)
  - OAuth 2.0 with automatic token refresh
  - AES-256-CBC encryption for stored credentials
  - AVS verification with automatic void
  - Admin transaction management panel
  - Standalone admin tool with CSRF protection
  - Decline email notifications
  - Debug logging with sensitive data masking

## License

This module is released under the GNU General Public License v2.0, consistent with Zen Cart's licensing.
See [http://www.zen-cart.com/license/2_0.txt](http://www.zen-cart.com/license/2_0.txt) for details.

## Credits

- QuickBooks Payments API by [Intuit](https://developer.intuit.com).
