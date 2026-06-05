# Email Notifications - Quick Start Guide

## 🚀 Setup in 3 Steps

### Step 1: Update `config.local.php`

Find this section in your `config.local.php`:

```php
$EMAIL_ENABLED = true;
$EMAIL_ADMIN = 'admin@thesatoshifaucet.com';
$EMAIL_FROM = 'admin@thesatoshifaucet.com';
$EMAIL_SMTP_HOST = 'mail.thesatoshifaucet.com';
$EMAIL_SMTP_PORT = 465;
$EMAIL_SMTP_USER = 'admin@thesatoshifaucet.com';
$EMAIL_SMTP_PASS = 'YOUR_EMAIL_PASSWORD_HERE';     // ⬅️ CHANGE THIS!
$EMAIL_SMTP_SECURE = 'ssl';
```

**Replace `YOUR_EMAIL_PASSWORD_HERE` with your actual cPanel email password.**

### Step 2: Test Your Configuration

1. Submit a test claim through your faucet
2. Check scheduler log:
   ```bash
   tail -f scheduler.log
   ```
   
   Look for success message:
   ```
   [EMAIL] Successfully sent to admin@thesatoshifaucet.com
   ```

### Step 3: Monitor Incoming Emails

You should receive emails with:
- ✅ Transaction status
- ✅ Invoice details
- ✅ Payment reference
- ✅ User information

---

## 🔧 Where Email Calls Are Located

### Ready to Pay Email
- **File:** `admin-panel/scheduler_process.php`
- **Around line:** 708
- **Marked with:** `// 📧 SEND EMAIL NOTIFICATION - Ready to Pay`

### Payment Successful Email  
- **File:** `admin-panel/scheduler_process.php`
- **Around line:** 740
- **Marked with:** `// 📧 SEND EMAIL NOTIFICATION - Payment Successful`

---

## 🛑 Quick Disable

### Disable All Emails
In `config.local.php`:
```php
$EMAIL_ENABLED = false;  // Turn off all emails
```

### Disable Specific Email
Comment out the function call in `scheduler_process.php`:
```php
// send_claim_email( ... );  // Commented out = disabled
```

---

## ⚠️ Important: Update Your Password!

Your email configuration needs the actual cPanel email password. 

**Current setting:**
```php
$EMAIL_SMTP_PASS = 'YOUR_EMAIL_PASSWORD_HERE';  // ❌ Still needs updating!
```

**Required:**
```php
$EMAIL_SMTP_PASS = 'YourActualPassword123';     // ✅ Your real cPanel email password
```

---

## 📋 Implementation Checklist

- [ ] Updated `$EMAIL_SMTP_PASS` in `config.local.php`
- [ ] Verified `$EMAIL_ADMIN` is correct
- [ ] Tested with a sample claim
- [ ] Checked `scheduler.log` for success messages
- [ ] Received test email in inbox
- [ ] (Optional) Reviewed `EMAIL_SETUP.md` for advanced options

---

## 📧 Email Details Sent

Each email notification includes:

| Field | Example |
|-------|---------|
| Status | ready_to_pay / paid |
| Timestamp | 2024-01-15 14:23:45 UTC |
| Domain | wallet.example.com |
| User IP | 192.168.1.100 |
| Amount | 100 sats |
| Payment Ref | abc123def456 |
| Invoice | lnurl1... |

---

## 💡 How It Works

1. User submits LNURL → **Instant response** (NO wait for email)
2. Scheduler processes in background → **Email sent** (doesn't slow user)
3. Your admin email notified with full details
4. Process is 100% non-blocking

**User Experience:** Unchanged ✅
**Admin Notifications:** Enabled ✅

---

## 🆘 Need Help?

Check `EMAIL_SETUP.md` for:
- Detailed troubleshooting
- Error messages explained
- Security best practices
- Advanced configuration options

