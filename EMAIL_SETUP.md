# Email Notification Setup

## Overview
This faucet can send email notifications to your admin email whenever someone requests Sats. Emails include full transaction details, invoice status, and payment information.

**Key Features:**
- ✅ Asynchronous sending (does NOT slow down user experience)
- ✅ Full transaction details in each email
- ✅ Easy on/off toggle
- ✅ Clearly marked in code for easy disabling

---

## Configuration

### Step 1: Update Email Settings in `config.local.php`

Edit `config.local.php` and update these settings:

```php
$EMAIL_ENABLED = true;                              // Set to false to disable emails
$EMAIL_ADMIN = 'admin@thesatoshifaucet.com';        // Your admin email
$EMAIL_FROM = 'admin@thesatoshifaucet.com';         // Sending address
$EMAIL_SMTP_HOST = 'mail.thesatoshifaucet.com';     // cPanel SMTP server (from your settings)
$EMAIL_SMTP_PORT = 465;                             // SSL/TLS port (or 587 for TLS)
$EMAIL_SMTP_USER = 'admin@thesatoshifaucet.com';    // SMTP username
$EMAIL_SMTP_PASS = 'YOUR_EMAIL_PASSWORD_HERE';      // ⚠️ CHANGE THIS to your email password
$EMAIL_SMTP_SECURE = 'ssl';                         // 'ssl' for 465, 'tls' for 587
```

### Step 2: Set Your Email Password

**IMPORTANT:** Change `YOUR_EMAIL_PASSWORD_HERE` to your actual cPanel email password:

```php
$EMAIL_SMTP_PASS = 'YourActualEmailPasswordHere';   // Your cPanel email password
```

### Your cPanel SMTP Settings (Already Configured)

These match the settings you provided in cPanel:

| Setting | Value |
|---------|-------|
| **Incoming Server (IMAP)** | mail.thesatoshifaucet.com:993 (SSL) |
| **Incoming Server (POP3)** | mail.thesatoshifaucet.com:995 (SSL) |
| **Outgoing Server (SMTP)** | mail.thesatoshifaucet.com:465 (SSL) |
| **Username** | admin@thesatoshifaucet.com |
| **Password** | Your cPanel email password |

---

## Email Notifications Flow

### When Email is Sent

Emails are sent during the **background scheduler** processing:

1. **User submits LNURL** → Instant response (no email yet, no wait for user)
2. **Scheduler processes** (background) → **Email #1: "Ready to Pay"**
   - Status: ready_to_pay
   - Includes: Invoice details, LNURL, domain
3. **After payment succeeds** → **Email #2: "✅ PAID"**
   - Status: paid
   - Includes: Payment reference, transaction ID

### Email Content

Each email includes:
- Transaction status (pending, ready_to_pay, paid)
- Timestamp
- Receiver details (domain, wallet provider)
- User's IP address
- LNURL/Invoice information
- Amount (in sats)
- Payment reference & transaction ID

Example:
```
=============================================================
         SATOSHI FAUCET - CLAIM NOTIFICATION
=============================================================

TRANSACTION STATUS: ready_to_pay
Timestamp: 2024-01-15 14:23:45 UTC

=============================================================
RECEIVER DETAILS
=============================================================
Domain/Wallet Provider: wallet.example.com
User IP Address: 192.168.1.100
LNURL: lnurl1dp68gup69p...

=============================================================
REWARD DETAILS
=============================================================
Amount Requested: 100 sats
Status: ready_to_pay

...
```

---

## Enabling/Disabling Emails

### Option 1: Global Toggle (Recommended)

In `config.local.php`:
```php
$EMAIL_ENABLED = false;  // Turn off all emails
```

### Option 2: Comment Out Function Calls

In `admin-panel/scheduler_process.php`:

**For "Ready to Pay" emails:**
```php
// Comment out the next line to disable this email:
// send_claim_email( ... );
```

**For "Paid" emails:**
```php
// Comment out the next line to disable this email:
// send_claim_email( ... );
```

---

## Testing Email Delivery

### Manual Test (via Command Line)

```bash
# Test SMTP connection from your server
telnet mail.thesatoshifaucet.com 465

# Or use curl (if telnet not available)
curl -v --ssl smtp://mail.thesatoshifaucet.com:465 \
  --user admin@thesatoshifaucet.com:YOUR_PASSWORD
```

### Check Scheduler Logs

Monitor email sending in the scheduler log:

```bash
tail -f scheduler.log | grep EMAIL
```

Success messages:
```
[EMAIL] Successfully sent to admin@thesatoshifaucet.com
```

Error messages (if any):
```
[EMAIL] SMTP connection failed
[EMAIL] SMTP authentication failed
[EMAIL] Message rejected
```

---

## Troubleshooting

### Emails Not Sending

1. **Check if emails are enabled:**
   ```php
   $EMAIL_ENABLED = true;  // in config.local.php
   ```

2. **Verify email password is correct:**
   - Password must match your cPanel email account
   - Special characters may need escaping

3. **Check SMTP credentials:**
   ```php
   $EMAIL_SMTP_HOST = 'mail.thesatoshifaucet.com'
   $EMAIL_SMTP_USER = 'admin@thesatoshifaucet.com'
   ```

4. **Verify SMTP port:**
   - Port 465 = SSL (recommended)
   - Port 587 = TLS/STARTTLS

5. **Check scheduler is running:**
   ```bash
   tail -f scheduler.log
   ```
   Look for: `Row #X PROCESSING:` entries

6. **Check PHP error logs:**
   - cPanel → Metrics → Raw Access Logs
   - Look for SMTP or stream errors

### Common Errors

| Error | Solution |
|-------|----------|
| "Connection refused" | Check SMTP host and port |
| "AUTH not supported" | Verify SMTP credentials |
| "Authentication failed" | Check email password (case-sensitive) |
| "Connection timeout" | Server may be blocking port 465 |

---

## Security Notes

⚠️ **Important Security Recommendations:**

1. **Store password securely:**
   - Never commit `config.local.php` to public repositories
   - Add to `.gitignore`:
     ```
     config.local.php
     scheduler.log
     ```

2. **Use cPanel's email account:**
   - Don't use personal Gmail credentials
   - cPanel-hosted email is more reliable and secure

3. **Rotate credentials periodically:**
   - Change email password every 3-6 months
   - Update `config.local.php` accordingly

4. **Monitor email logs:**
   - Check `scheduler.log` regularly
   - Report any unauthorized send attempts

---

## Function Locations in Code

If you need to modify email behavior:

### Email Configuration
- **File:** `config.local.php`
- **Lines:** After `$SCHEDULER_FILE = ...`

### Email Functions
- **File:** `admin-panel/scheduler_process.php`
- **Main Function:** `send_claim_email()` (around line 405)
- **SMTP Helper:** `send_via_smtp()` (around line 470)

### Email Function Calls
- **File:** `admin-panel/scheduler_process.php`
- **"Ready to Pay" Email:** Around line 708 (marked with `// 📧 SEND EMAIL`)
- **"Paid" Email:** Around line 740 (marked with `// 📧 SEND EMAIL`)

---

## Version History

- **v1.0** (Jan 2024): Initial email notification system
  - cPanel SMTP with SSL/TLS support
  - Two notification types: ready_to_pay, paid
  - Asynchronous sending (no user experience impact)
  - Fallback to PHP mail() function

