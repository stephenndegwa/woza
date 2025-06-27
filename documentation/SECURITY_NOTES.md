# M-pesa Payment Gateway Security Implementation

## Overview
The M-pesa payment gateway has been secured with multiple layers of authentication and authorization to ensure users can only access their own invoices and payment information.

## Security Features Implemented

### 1. Client Session Validation
- **File**: `payment.php`, `stkpush.php`, `check-status.php`
- **Description**: Validates that the user is logged into WHMCS with a valid session
- **Mechanism**: Checks `$_SESSION['uid']` for client sessions or `$_SESSION['adminid']` for admin access

### 2. Invoice Ownership Verification
- **File**: `payment.php`, `check-status.php`
- **Description**: Ensures users can only access invoices that belong to them
- **Mechanism**: Cross-references invoice `userid` with the current session's client ID

### 3. Security Token System
- **File**: `modules/gateways/woza.php`, `payment.php`
- **Description**: Provides secure access to payment pages via tokenized URLs
- **Mechanism**: 
  - Token generated: `md5($invoiceId . $clientId . date('Y-m-d') . 'woza_payment_security')`
  - Tokens are valid for today and yesterday (timezone tolerance)
  - Format: `payment.php?invoice_id=123&token=abc123...`

### 4. STK Push Form Protection
- **File**: `payment.php`, `stkpush.php`
- **Description**: Prevents unauthorized STK push requests
- **Mechanism**: 
  - Form token: `md5($invoiceId . $clientId . session_id() . 'woza_stkpush')`
  - Validates both token and session before processing STK push

### 5. Access Logging
- **File**: All payment files
- **Description**: Logs unauthorized access attempts for security monitoring
- **Mechanism**: Uses WHMCS `logActivity()` function to record suspicious activity

## Access Control Matrix

| User Type | Payment Page | STK Push | Status Check | Admin Functions |
|-----------|-------------|----------|--------------|----------------|
| **Logged Client** | ✅ Own invoices only | ✅ Own invoices only | ✅ Own invoices only | ❌ |
| **Admin** | ✅ All invoices | ✅ All invoices | ✅ All invoices | ✅ |
| **Anonymous** | ✅ With valid token | ❌ | ❌ | ❌ |
| **Wrong Client** | ❌ | ❌ | ❌ | ❌ |

## Security Flows

### Payment Page Access
1. Check if user has valid WHMCS session
2. If no session, check for valid security token
3. Verify invoice ownership (client ID matches)
4. Grant or deny access accordingly

### STK Push Request
1. Validate security token from form
2. Verify client session matches invoice owner
3. Process STK push if authorized
4. Log unauthorized attempts

### Status Check
1. Verify client session
2. Check invoice ownership
3. Return status only for authorized requests

## Token Security Notes

- **Token Lifespan**: 24-48 hours (today + yesterday tolerance)
- **Token Scope**: Specific to invoice and client combination
- **Token Entropy**: MD5 hash provides sufficient security for this use case
- **Token Transmission**: Via GET parameter in URL (HTTPS recommended)

## Implementation Benefits

1. **Prevents Invoice Enumeration**: Users cannot access random invoice IDs
2. **Session Hijacking Protection**: Tokens are tied to specific invoices
3. **Audit Trail**: All access attempts are logged
4. **Admin Override**: Administrators can access any invoice when needed
5. **Graceful Degradation**: Token system allows access even without active sessions

## Recommended Deployment

1. **HTTPS Only**: Ensure all payment pages use SSL/TLS
2. **Session Security**: Configure WHMCS with secure session settings
3. **Log Monitoring**: Regular review of security logs for suspicious activity
4. **Token Rotation**: Consider implementing shorter token lifespans for high-security environments

## Example Secure URLs

```
# Client logged in - direct access
https://yourdomain.com/payment.php?invoice_id=12345

# Token-based access (no session required)
https://yourdomain.com/payment.php?invoice_id=12345&token=a1b2c3d4e5f6...

# Invalid access attempts (will be blocked)
https://yourdomain.com/payment.php?invoice_id=99999  # Wrong invoice
https://yourdomain.com/payment.php?invoice_id=12345&token=invalid  # Wrong token
```

## Security Testing

To test the security implementation:

1. **Try accessing another client's invoice** - Should redirect to login or show access denied
2. **Access without session or token** - Should redirect to login
3. **Use expired/invalid tokens** - Should deny access
4. **Check admin access** - Should work for all invoices
5. **Monitor logs** - Verify unauthorized attempts are logged

This multi-layered security approach ensures that the M-pesa payment system is both secure and user-friendly while maintaining compatibility with WHMCS standards.