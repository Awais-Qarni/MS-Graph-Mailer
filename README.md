# MS Graph Mailer for WordPress

**MS Graph Mailer** is a robust, production-ready WordPress plugin that replaces the default `wp_mail()` function with a high-performance integration for **Microsoft 365 (Microsoft Graph API)**. 

Designed for reliability and transparency, it provides a full suite of tools to track, debug, and resend your outgoing emails.

---

## 📋 Requirements

- **WordPress**: 5.8 or higher
- **PHP**: 7.4 or higher
- **Microsoft Professional Subscription**: Business or Enterprise plan with access to Azure Portal.

---

## ✨ Key Features

- **Reliable Sending**: Bypass typical server mail restrictions by using Microsoft's professional infrastructure.
- **Enterprise Security**: Uses the **Client Credentials Flow** (App-only permissions) for secure, long-term authentication without requiring a user to stay signed in.
- **Email Analytics Dashboard**: A clean dashboard widget showing "At-a-glance" stats for:
    - Emails sent today, last 7 days, and all time.
    - Success vs. Failure rates.
- **Advanced Logging**: Full audit trail of every email attempt, including recipients, subjects, and specific API error messages.
- **Smart Resending**: One-click "Resend" for failed emails with a built-in retry counter to avoid duplication.
- **Accurate Failure Reporting**: `wp_mail()` returns the real result and the `wp_mail_failed` action fires, so other plugins can detect and react to delivery problems.
- **Throttling Aware**: Retries transient `429` and `5xx` responses from Microsoft Graph, honouring `Retry-After`.
- **Production Hardened**: Indexed log table, attachment safeguards matched to the Microsoft Graph limits (3MB per file, 4MB per message), and strict capability and nonce checks on every admin action.

---

## 🚀 Setup Guide

### 1. Azure App Registration (Required)
To use this plugin, you must register an application in your **Microsoft Entra ID (Azure AD)** tenant:

1. Log in to the [Azure Portal](https://portal.azure.com/).
2. Go to **Microsoft Entra ID** > **App registrations** > **New registration**.
3. Name it (e.g., "WordPress Mailer") and click **Register**.
4. **API Permissions**:
    - Click **API permissions** > **Add a permission** > **Microsoft Graph**.
    - Choose **Application permissions**.
    - Search for and add `Mail.Send`.
    - **IMPORTANT**: Click "**Grant admin consent for [Your Tenant]**".
5. **Credentials**:
    - Go to **Certificates & secrets** > **New client secret**.
    - Copy the **Secret Value** immediately (you won't see it again).
6. **IDs**:
    - Copy the **Application (client) ID** and **Directory (tenant) ID** from the Overview page.

### 2. Keeping Credentials Out of the Database (Optional but Recommended)

Any of these constants in `wp-config.php` take precedence over the values stored in the database, so your secret never touches the options table:

```php
define('MSGRAPH_CLIENT_ID',     'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('MSGRAPH_CLIENT_SECRET', 'your-secret-value');
define('MSGRAPH_TENANT_ID',     'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('MSGRAPH_FROM_EMAIL',    'noreply@yourdomain.com');
```

Fields backed by a constant appear as locked on the settings screen.

### 3. Plugin Configuration
1. Install and activate the plugin in WordPress.
2. Go to **Settings** > **MS Graph Mailer**.
3. Enter your **Client ID**, **Client Secret**, and **Tenant ID**.
4. Enter the **From Email Address** (must be a valid mailbox in your M365 tenant).
5. Click **Save Settings**.
6. Use the **Send Test Email** tool to verify the connection.

---

## 🛠️ Usage & Maintenance

### Monitoring Emails
Navigate to the **Email Logs** tab to see the history of all outgoing mail. 
- **View Content**: If enabled in settings, you can read the full body of logged emails.
- **Resend**: Click the "Resend" link next to any failed entry to try again.

### Dashboard Widget
The "MS Graph Mailer Stats" widget on the WordPress Dashboard gives you instant insight into your email health. Green icons indicate success, while red icons highlight failures that may need attention.

### Privacy
Message bodies are stored so that "Resend" can reproduce them. If that is more data than you want to keep, turn off **Store Email Content** in settings — logging of recipients, subjects and errors continues, but resending will no longer be able to reproduce the message body.

### Maintenance
- **Auto-Cleanup**: The plugin automatically prunes old logs based on your "Log Retention" setting (default: 30 days).
- **Manual Cleanup**: Use "Clear All Logs" in settings, or select rows in the log table and use the Delete bulk action.

---

## 🔌 Hooks for Developers

| Hook | Type | Purpose |
| --- | --- | --- |
| `msgraph_mailer_payload` | filter | Modify the Graph `sendMail` body before it is sent. Receives the payload and the original `wp_mail()` arguments. |
| `msgraph_mailer_save_to_sent_items` | filter | Return `true` to keep a copy in the mailbox's Sent Items. Defaults to `false`. |
| `msgraph_mailer_report_failures` | filter | Return `false` to make `wp_mail()` report success even when a send fails (the pre-2.2 behaviour). |
| `msgraph_mailer_sent` | action | Fires after Microsoft Graph accepts a message. Receives the `wp_mail()` arguments and the log ID. |

Sending under a different address requires **SendAs** permission on the mailbox; use `msgraph_mailer_payload` to set `message.from` yourself if your tenant is configured for it.

---

## 💻 Development & Architecture

- **Core Logic**: Intercepts `wp_mail` via the `pre_wp_mail` filter.
- **Authentication**: Client credentials flow. Tokens are cached per request and in a non-autoloaded option, so a bearer token is never loaded into `alloptions` on front-end requests.
- **Database**: Custom table `{wp_prefix}msgraph_email_logs`, indexed on `status`, `created_at` and the pair, with a versioned schema that upgrades in place.
- **Security**: Capability checks (`manage_options`) and nonce validation on every administrative endpoint; all SQL fragments derived from request input come from fixed whitelists.

---

## 📜 License
GPLv2 or later. Developed by **Muhammad Awais**.
