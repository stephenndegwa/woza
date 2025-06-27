# WHMCS Woza M-pesa Gateway - Installation Guide

## 📦 **Quick Installation Guide**

This guide will walk you through installing the Woza M-pesa payment gateway module for WHMCS in under 10 minutes.

---

## ⚡ **Prerequisites**

Before installation, ensure you have:

### **WHMCS Requirements**
- ✅ WHMCS 7.0 or higher
- ✅ PHP 7.4 or higher  
- ✅ MySQL 5.7 or higher
- ✅ SSL certificate (required for production)
- ✅ Admin access to WHMCS

### **M-pesa Requirements**
- ✅ Active Safaricom M-pesa Paybill account
- ✅ Safaricom Developer Portal account
- ✅ API credentials (Consumer Key, Consumer Secret, Passkey)

### **Server Requirements**
- ✅ cURL extension enabled
- ✅ OpenSSL extension enabled
- ✅ Outbound HTTPS access (to Safaricom APIs)
- ✅ Inbound HTTPS access (for callbacks)

---

## 🚀 **Step-by-Step Installation**

### **Step 1: Download & Extract Files**

1. **Download** the module package
2. **Extract** the ZIP file to a temporary folder
3. **Review** the file structure:

```
whmcs-woza-mpesa/
├── modules/gateways/woza.php
├── payment.php
├── stkpush.php
├── check-status.php
├── README.md
└── INSTALLATION_GUIDE.md
```

### **Step 2: Upload Files**
Upload these files to your WHMCS installation:

```
/modules/gateways/
├── woza.php                  # Main gateway file
├── woza/
│   └── callback/
│       └── woza.php          # Callback handler
/woza/
├── confirmation.php          # Payment confirmation handler
├── validation.php            # Payment validation handler
└── register_urls.php         # URL registration script
/
├── payment.php               # Enhanced payment page
├── stkpush.php              # STK Push handler
├── check-status.php         # Payment status checker
```

**File Permissions:**
```bash
chmod 644 modules/gateways/woza.php
chmod 644 payment.php
chmod 644 stkpush.php
chmod 644 check-status.php
```

### **Step 3: Database Setup**

The module automatically creates required tables on first use:

- `mod_mpesa_transactions` - Transaction tracking
- `mod_mpesa_c2b_confirmations` - Payment confirmations  
- `mod_mpesa_offline_payments` - Manual payment submissions

**Manual Database Setup (if needed):**
```sql
-- Run these queries if automatic setup fails
CREATE TABLE IF NOT EXISTS `mod_mpesa_transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL,
    `checkout_request_id` varchar(255) DEFAULT NULL,
    `merchant_request_id` varchar(255) DEFAULT NULL,
    `phone_number` varchar(20) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `status` varchar(50) DEFAULT 'pending',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `mod_mpesa_c2b_confirmations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `trans_id` varchar(255) NOT NULL,
    `trans_time` varchar(50) NOT NULL,
    `trans_amount` decimal(10,2) NOT NULL,
    `business_short_code` varchar(20) NOT NULL,
    `bill_ref_number` varchar(255) NOT NULL,
    `invoice_number` varchar(255) DEFAULT NULL,
    `msisdn` varchar(20) NOT NULL,
    `first_name` varchar(100) DEFAULT NULL,
    `middle_name` varchar(100) DEFAULT NULL,
    `last_name` varchar(100) DEFAULT NULL,
    `processed` tinyint(1) DEFAULT 0,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `trans_id` (`trans_id`)
);

CREATE TABLE IF NOT EXISTS `mod_mpesa_offline_payments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL,
    `phone_number` varchar(20) NOT NULL,
    `amount` decimal(10,2) NOT NULL,
    `transaction_code` varchar(255) DEFAULT NULL,
    `status` varchar(50) DEFAULT 'pending',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
);
```

### **Step 4: Configure Callback URL**
Set your callback URL in Safaricom Developer Portal:
```
https://yourdomain.com/modules/gateways/woza/callback/woza.php
```

### **Step 5: Activate Gateway in WHMCS**

1. **Login** to WHMCS Admin Panel
2. **Navigate** to `Setup → Payments → Payment Gateways`
3. **Click** "All Payment Gateways"
4. **Find** "Woza" in the list
5. **Click** "Activate"

### **Step 6: Configure Gateway Settings**

Fill in the following settings:

| Field | Value | Description |
|-------|-------|-------------|
| **Display Name** | `Woza M-pesa` | Name shown to customers |
| **Consumer Key** | `[Your Key]` | From Safaricom Developer Portal |
| **Consumer Secret** | `[Your Secret]` | From Safaricom Developer Portal |
| **Business Shortcode** | `[Your Paybill]` | Your M-pesa paybill number |
| **Passkey** | `[Your Passkey]` | From Safaricom Developer Portal |
| **Environment** | `sandbox` or `production` | Start with sandbox for testing |
| **Auto-redirect** | `on` | Enable automatic redirect |
| **Debug Mode** | `off` | Enable only for troubleshooting |

### **Step 7: Register Callback URLs (CRITICAL)**

**⚠️ IMPORTANT:** This step is mandatory for the gateway to work properly.

#### **Why URL Registration is Required**
- Enables automatic payment detection
- Allows real-time payment notifications
- Required for C2B (Customer to Business) payments
- Without this, all payments will remain "pending"

#### **Method 1: Automatic Registration (Recommended)**

```bash
# Navigate to your WHMCS root directory
cd /var/www/html

# Run the registration script
php woza/register_urls.php

# Expected output:
# ✅ Testing SSL certificate...
# ✅ Testing callback URL accessibility...
# ✅ Registering confirmation URL...
# ✅ Registering validation URL...
# ✅ URL registration completed successfully!
```

#### **Method 2: Manual Registration**

If automatic registration fails, manually configure in Safaricom Developer Portal:

1. **Login** to [Safaricom Developer Portal](https://developer.safaricom.co.ke/)
2. **Select** your M-pesa application
3. **Navigate** to "C2B URLs" section
4. **Configure** these URLs:

| URL Type | URL |
|----------|-----|
| **Confirmation URL** | `https://yourdomain.com/modules/gateways/woza/callback/woza.php` |
| **Validation URL** | `https://yourdomain.com/modules/gateways/woza/callback/woza.php` |

#### **Verify Registration**

```bash
# Test callback URL accessibility
curl -X POST https://yourdomain.com/modules/gateways/woza/callback/woza.php \
  -H "Content-Type: application/json" \
  -d '{"test": "true"}'

# Should return HTTP 200 with JSON response
```

### **Step 8: Test Installation**

#### **Test in Sandbox Mode**

1. **Set Environment** to "sandbox"
2. **Use Test Credentials** from Safaricom
3. **Test Phone Number:** 254708374149
4. **Create Test Invoice** in WHMCS
5. **Attempt Payment** using test phone number

#### **Test STK Push**
```bash
# Expected flow:
1. Customer selects Woza M-pesa
2. Enters phone number: 254708374149
3. Clicks "Pay Now"
4. Receives STK push prompt
5. Enters PIN: 1234
6. Payment processes automatically
```

#### **Test Offline Payment**
```bash
# Expected flow:
1. Customer selects offline payment
2. Auto-detection starts automatically
3. Customer sends money to paybill
4. System detects payment within 30 seconds
5. Automatic redirect to success page
```

#### **Test Callback**
```bash
# Verify callback URL
curl -X POST https://yourdomain.com/modules/gateways/woza/callback/woza.php
# Should return HTTP 200
```

---

## 🧪 **Testing Your Installation**

### **Test in Sandbox Mode**

1. **Set Environment** to "sandbox"
2. **Use Test Credentials** from Safaricom
3. **Test Phone Number:** 254708374149
4. **Create Test Invoice** in WHMCS
5. **Attempt Payment** using test phone number

### **Test STK Push**
```bash
# Expected flow:
1. Customer selects Woza M-pesa
2. Enters phone number: 254708374149
3. Clicks "Pay Now"
4. Receives STK push prompt
5. Enters PIN: 1234
6. Payment processes automatically
```

### **Test Offline Payment**
```bash
# Expected flow:
1. Customer selects offline payment
2. Auto-detection starts automatically
3. Customer sends money to paybill
4. System detects payment within 30 seconds
5. Automatic redirect to success page
```

### **Test Callback**
```bash
# Verify callback URL
curl -X POST https://yourdomain.com/modules/gateways/woza/callback/woza.php
# Should return HTTP 200
```

---

## 🔧 **Troubleshooting Installation**

### **Common Issues**

#### **Gateway Not Appearing**
```bash
# Check file permissions
ls -la modules/gateways/woza.php
# Should show: -rw-r--r--

# Check file syntax
php -l modules/gateways/woza.php
# Should show: No syntax errors detected
```

#### **Database Tables Not Created**
```bash
# Check database connection
# Check WHMCS error logs
tail -f /path/to/whmcs/logs/activity.log

# Manually create tables using SQL above
```

#### **Callback URL Not Working**
```bash
# Verify callback URL
curl -X POST https://yourdomain.com/modules/gateways/woza/callback/woza.php
# Should return HTTP 200
```

#### **URL Registration Failed**
```bash
# Common causes and solutions:

1. SSL Certificate Issues:
   - Ensure certificate is from trusted CA
   - Check certificate is not expired
   - Verify certificate chain is complete

2. Firewall Issues:
   - Allow inbound HTTPS traffic (port 443)
   - Ensure URL is publicly accessible
   - Test from external network

3. DNS Issues:
   - Verify domain resolves correctly
   - Check DNS propagation
   - Test with different DNS servers

4. API Credential Issues:
   - Verify consumer key and secret
   - Check environment setting (sandbox vs production)
   - Ensure credentials are for correct environment
```

#### **STK Push Not Received**
```bash
# Verify phone number format
# Correct: 254712345678
# Wrong: 0712345678, +254712345678

# Check Safaricom API credentials
# Verify environment setting (sandbox vs production)
```

#### **Auto-Detection Not Working**
```bash
# Check URL registration status
php woza/register_urls.php --verify

# Monitor callback logs
tail -f modules/gateways/woza/logs/callbacks.log

# Test manual payment notification
curl -X POST https://yourdomain.com/modules/gateways/woza/callback/woza.php \
  -H "Content-Type: application/json" \
  -d '{
    "TransactionType": "Pay Bill",
    "TransID": "TEST123456789",
    "TransTime": "20240115120000",
    "TransAmount": "100.00",
    "BusinessShortCode": "174379",
    "BillRefNumber": "INV12345",
    "MSISDN": "254712345678",
    "FirstName": "John",
    "LastName": "Doe"
  }'
```

---

## ✅ **Post-Installation Checklist**

- [ ] Files uploaded to correct directories
- [ ] File permissions set correctly (644 for files, 755 for directories)
- [ ] Database tables created successfully
- [ ] Gateway activated in WHMCS admin
- [ ] All configuration fields completed
- [ ] **Callback URLs registered with Safaricom** ⚠️ **CRITICAL**
- [ ] SSL certificate valid and working
- [ ] Callback URL accessibility tested
- [ ] Test transaction completed successfully
- [ ] Auto-detection working for offline payments
- [ ] Error logging enabled for monitoring
- [ ] Registration verification completed

### **URL Registration Verification**
```bash
# Run comprehensive verification
php woza/register_urls.php --verify --verbose

# Check specific components
curl -I https://yourdomain.com/modules/gateways/woza/callback/woza.php  # SSL test
php -f woza/register_urls.php  # Re-register if needed
tail -f modules/gateways/woza/logs/registration.log  # Check logs
```

---

## 🚀 **Go Live Checklist**

Before switching to production:

- [ ] **Test thoroughly** in sandbox environment
- [ ] **Verify URL registration** in sandbox
- [ ] **Switch to production** credentials
- [ ] **Update environment** setting to "production"
- [ ] **Re-register URLs** for production environment
- [ ] **Verify callback URLs** are accessible in production
- [ ] **Test with small amounts** first
- [ ] **Monitor logs** for any issues
- [ ] **Train staff** on new payment process
- [ ] **Update customer documentation**

### **Production URL Registration**
```bash
# Switch to production environment in gateway settings first
# Then re-register URLs for production
php woza/register_urls.php --environment=production

# Verify production registration
curl -X POST https://yourdomain.com/modules/gateways/woza/callback/woza.php \
  -H "Content-Type: application/json" \
  -d '{"test": "production"}'
```

---

## 📞 **Need Help?**

If you encounter issues during installation:

1. **Check the troubleshooting section** above
2. **Review WHMCS error logs**
3. **Test callback URL accessibility**
4. **Verify Safaricom API credentials**
5. **Ensure URL registration is completed**
6. **Contact support** with specific error messages

### **Support Information**
- **Email:** [Your Support Email]
- **Documentation:** [Your Documentation URL]
- **Support Portal:** [Your Support Portal]

### **When Contacting Support, Include:**
- WHMCS version
- PHP version
- Error messages (exact text)
- Steps to reproduce the issue
- URL registration status
- Callback URL test results
- Debug logs (if available)

---

**🎉 Congratulations! Your Woza M-pesa gateway is now ready to process payments.**

*Next: Configure your gateway settings, register your URLs, and start accepting M-pesa payments from your customers.* 