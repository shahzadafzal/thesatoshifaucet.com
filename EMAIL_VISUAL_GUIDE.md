# Email System - Visual Guide & Quick Reference

## 🎯 System Overview

```
USER                          WEB SERVER                    BACKGROUND SCHEDULER
────────────                  ──────────                    ────────────────────

Submit LNURL
    │
    ├─────────────────────────────────────────┐
    │ Validate LNURL                          │
    │ Deduct Balance                          │
    │ Insert to DB                            │
    │                                          │
    │ INSTANT RESPONSE TO USER! ✅            │
    │ No waiting for email!                   │
    │                                          │
    └─────────────────────────────────────────┘
                                        │
                                        │ Trigger background scheduler
                                        │
                                        └──────────────┬──────────────────────────
                                                       │
                                                       ├─ Decode LNURL
                                                       ├─ Get pay data
                                                       ├─ Request invoice
                                                       │
                                                       ├─ 📧 SEND EMAIL #1
                                                       │   "Ready to Pay"
                                                       │
                                                       ├─ Process payment
                                                       │
                                                       ├─ 📧 SEND EMAIL #2
                                                       │   "✅ PAID"
                                                       │
                                                       └─ Mark as Paid
```

---

## 📋 Email Configuration Checklist

```ini
✅ What's Already Done:
   [✓] SMTP Host: mail.thesatoshifaucet.com
   [✓] SMTP Port: 465 (SSL)
   [✓] Username: admin@thesatoshifaucet.com
   [✓] Email Functions: Created & integrated
   [✓] Function Calls: Added to scheduler

❌ What You MUST Do:
   [ ] Update password in config.local.php
   [ ] Replace 'YOUR_EMAIL_PASSWORD_HERE' with actual password
   [ ] Test by submitting a claim
```

---

## 🔑 File Quick Reference

| Component | File | Edit? | Note |
|-----------|------|-------|------|
| Email Config | `config.local.php` | ✏️ **YES** | Change password |
| Main Email Function | `scheduler_process.php` | ❌ No | Already added |
| SMTP Function | `scheduler_process.php` | ❌ No | Already added |
| Call #1 (Ready) | `scheduler_process.php` | ⚠️ Optional | Comment to disable |
| Call #2 (Paid) | `scheduler_process.php` | ⚠️ Optional | Comment to disable |

---

## ⚙️ Configuration Step-by-Step

### Current State (in config.local.php):
```php
$EMAIL_SMTP_PASS = 'YOUR_EMAIL_PASSWORD_HERE';
                    └─ NEEDS YOUR PASSWORD!
```

### Change To:
```php
$EMAIL_SMTP_PASS = 'YourActualCPanelPassword';
                    └─ Your cPanel email password
```

---

## 📧 What Gets Emailed

### Email #1: Ready to Pay (Triggered immediately after invoice generation)
```
To: admin@thesatoshifaucet.com
Subject: [TheSatoshiFaucet] New Claim Request - ⏳ PROCESSING

Contains:
- Status: ready_to_pay
- Timestamp
- Wallet Provider/Domain
- User IP
- LNURL
- Amount: 100 sats
- Invoice (BOLT11)
```

### Email #2: Successfully Paid (Triggered after payment succeeds)
```
To: admin@thesatoshifaucet.com
Subject: [TheSatoshiFaucet] New Claim Request - ✅ PAID

Contains:
- Status: paid
- Timestamp
- Wallet Provider/Domain  
- User IP
- LNURL
- Amount: 100 sats
- Invoice (BOLT11)
- Payment Reference ✅ NEW
- Transaction ID ✅ NEW
```

---

## 🎛️ Control Options

### Turn Off ALL Emails (Easiest)
```php
// In config.local.php
$EMAIL_ENABLED = false;  // Set to false to disable
```

### Turn Off Specific Email Type
```php
// In scheduler_process.php, find:
// 📧 SEND EMAIL NOTIFICATION - Ready to Pay
send_claim_email( ... );
// Comment out this line:
// send_claim_email( ... );
```

### Get Full Control
```php
// In config.local.php
$EMAIL_ENABLED = true;   // Still on, but...

// In scheduler_process.php, comment individual calls:
// send_claim_email( ... );  // Ready to Pay disabled
// send_claim_email( ... );  // Paid disabled
```

---

## 🧪 Testing Procedure

### Step 1: Prepare
- Update password in `config.local.php`
- Verify `$EMAIL_ENABLED = true`

### Step 2: Submit Test Claim
- Go to faucet website
- Enter a valid LNURL
- Complete reCAPTCHA
- Click Submit

### Step 3: Check Scheduler Log
```bash
# Linux/cPanel:
tail -f scheduler.log

# Windows:
Get-Content scheduler.log -Wait
```

### Step 4: Look for Success
```
[EMAIL] Successfully sent to admin@thesatoshifaucet.com
```

### Step 5: Check Email
- Open admin@thesatoshifaucet.com inbox
- Look for email with subject: `[TheSatoshiFaucet] New Claim Request`
- Verify transaction details are correct

---

## ✅ Success Indicators

You'll know it's working when:

1. **In scheduler.log:**
   ```
   [EMAIL] Successfully sent to admin@thesatoshifaucet.com
   ```

2. **In Gmail inbox:**
   - Email from: admin@thesatoshifaucet.com
   - Subject: `[TheSatoshiFaucet] New Claim Request - ⏳ PROCESSING`
   - Full transaction details present

3. **User Experience:**
   - Still gets instant response (no slowdown)
   - Email arrives within 5-10 seconds

---

## ❌ Failure Indicators

| Issue | Check |
|-------|-------|
| No email received | Check `scheduler.log` for `[EMAIL]` messages |
| "Connection refused" | Verify SMTP host is correct |
| "Auth failed" | Check password matches cPanel email password |
| Scheduler not running | Check `$SCHEDULER_TRIGGER_ENABLED = true` |

---

## 📞 Quick Troubleshooting

### Problem: Email not sending
```bash
# Check if scheduler is even running
tail -f scheduler.log

# Should see:
# [SCHEDULER] Started at ...
# Row #1 PROCESSING: LNURL=lnurl1...
```

### Problem: Auth failed
```php
// Verify password - check if it has special characters
// Example passwords that need checking:
$EMAIL_SMTP_PASS = 'Pass@word123';     // @ is OK
$EMAIL_SMTP_PASS = 'Pass$word123';     // $ might need escaping
$EMAIL_SMTP_PASS = 'Pass&word123';     // & might need escaping

// Try single quotes to prevent PHP parsing:
$EMAIL_SMTP_PASS = 'Pass$word123';  // ✅ Correct
$EMAIL_SMTP_PASS = "Pass$word123";  // ❌ Might cause issues
```

### Problem: Still not working?
1. Check `scheduler.log` for exact error message
2. Verify SMTP credentials:
   - Host: mail.thesatoshifaucet.com ✓
   - Port: 465 ✓
   - Username: admin@thesatoshifaucet.com ✓
3. Test SMTP connection from server
4. Check cPanel email account is active

---

## 🔍 Code Locations Reference

### Email Configuration
**File:** config.local.php
```php
// Around line 30-40 (at bottom of file)
$EMAIL_ENABLED = true;
$EMAIL_ADMIN = 'admin@thesatoshifaucet.com';
// ... etc
```

### Email Function Definition
**File:** admin-panel/scheduler_process.php
```php
// Around line 405-470
function send_claim_email(...) { ... }
function send_via_smtp(...) { ... }
```

### Email Function Calls
**File:** admin-panel/scheduler_process.php
```php
// Call #1 around line 708 (marked: 📧 SEND EMAIL - Ready to Pay)
send_claim_email( ... );

// Call #2 around line 740 (marked: 📧 SEND EMAIL - Payment Successful)
send_claim_email( ... );
```

---

## 🚀 Ready to Go!

### Your Immediate Action Items:
1. ✏️ Edit `config.local.php`
2. 🔑 Replace password placeholder
3. 🧪 Test with a sample claim
4. 📧 Verify email arrives
5. ✨ Done!

---

**Questions?** See:
- 📖 `EMAIL_QUICKSTART.md` - 3-step setup
- 📚 `EMAIL_SETUP.md` - Full documentation
- 🔧 `IMPLEMENTATION_SUMMARY.md` - Technical details

