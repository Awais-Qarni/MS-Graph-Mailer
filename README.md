# MS Graph Mailer for WordPress

**MS Graph Mailer** is a robust, production-ready WordPress plugin that replaces the default `wp_mail()` function with a high-performance integration for **Microsoft 365 (Microsoft Graph API)**. 

Designed for reliability and transparency, it provides a full suite of tools to track, debug, and resend your outgoing emails.

---

## ✨ Key Features

- **Reliable Sending**: Bypass typical server mail restrictions by using Microsoft's professional infrastructure.
- **Enterprise Security**: Uses the **Client Credentials Flow** (App-only permissions) for secure, long-term authentication without requiring a user to stay signed in.
- **Email Analytics Dashboard**: A clean dashboard widget showing "At-a-glance" stats for:
    - Emails sent today, last 7 days, and all time.
    - Success vs. Failure rates.
- **Advanced Logging**: Full audit trail of every email attempt, including recipients, subjects, and specific API error messages.
- **Smart Resending**: One-click "Resend" for failed emails with a built-in retry counter to avoid duplication.
- **Production Hardened**: Includes database indexing for speed, attachment size safeguards (10MB limit), and strict admin security checks.

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

### 2. Plugin Configuration
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

### Maintenance
- **Auto-Cleanup**: The plugin automatically prunes old logs based on your "Log Retention" setting (default: 30 days).
- **Manual Cleanup**: Use the "Clear All Logs" button in settings for immediate data removal.

---

## 💻 Development & Architecture

- **Core Logic**: Intercepts `wp_mail` via the `pre_wp_mail` filter.
- **Authentication**: Token-based auth with automatic caching in WordPress transients and options to minimize API calls.
- **Database**: Custom table `{wp_prefix}msgraph_email_logs` with optimized indexing for high-performance reporting.
- **Security**: Strict capability checks (`manage_options`) and nonce validation on all administrative endpoints.

---

## 📜 License
GPLv2 or later. Developed by **Muhammad Awais**.
