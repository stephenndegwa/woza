<?php
/**
 * WHMCS M-pesa STK Push Handler
 *
 * This file handles the STK push request to Safaricom M-pesa API.
 * Enhanced with comprehensive security measures
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// ========================================
// SECURITY MEASURES
// ========================================

// Start session for security tracking
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Get client IP address (considering proxies and load balancers)
function getClientIP() {
    $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$clientIP = getClientIP();

// Security: Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

// Security: Rate limiting per IP
$rateLimitKey = 'stkpush_attempts_' . md5($clientIP);
$maxAttempts = 20; // Max STK push attempts per hour
$timeWindow = 3600; // 1 hour in seconds

if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'reset_time' => time() + $timeWindow];
}

// Reset counter if time window has passed
if (time() > $_SESSION[$rateLimitKey]['reset_time']) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'reset_time' => time() + $timeWindow];
}

// Check if rate limit exceeded
if ($_SESSION[$rateLimitKey]['count'] >= $maxAttempts) {
    http_response_code(429);
    logActivity("STK Push rate limit exceeded from IP: $clientIP");
    die('Too many payment requests. Please try again later.');
}

// Increment counter for this request
$_SESSION[$rateLimitKey]['count']++;

// Security: CSRF Protection
$csrfToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';

if (empty($csrfToken) || empty($expectedToken) || !hash_equals($expectedToken, $csrfToken)) {
    http_response_code(403);
    logActivity("CSRF token validation failed for STK Push from IP: $clientIP");
    die('Invalid security token. Please refresh the page and try again.');
}

// Security: Validate User-Agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (empty($userAgent) || strlen($userAgent) < 10) {
    http_response_code(400);
    logActivity("Invalid user agent for STK Push from IP: $clientIP");
    die('Invalid user agent.');
}

// Security: Additional headers for protection
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Include the WHMCS core
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

// Get gateway variables
$gatewayModuleName = 'woza';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active
if (!$gatewayParams['type']) {
    die("Module not activated");
}

// Get POST data
$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$phoneNumber = isset($_POST['mpesa_phone']) ? $_POST['mpesa_phone'] : (isset($_POST['phone']) ? $_POST['phone'] : '');
$clientId = isset($_POST['clientId']) ? (int)$_POST['clientId'] : 0;
$returnUrl = isset($_POST['returnUrl']) ? $_POST['returnUrl'] : '';
$securityToken = isset($_POST['security_token']) ? $_POST['security_token'] : '';

// Validate required data
if (!$invoiceId || !$amount || !$phoneNumber || !$clientId) {
    logTransaction($gatewayModuleName, array_merge($_POST, ['client_ip' => $clientIP]), "Invalid Parameters");
    logActivity("STK Push with invalid parameters from IP: $clientIP - Invoice: $invoiceId, Amount: $amount, Phone: " . substr($phoneNumber, 0, 5) . "***");
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1");
    } else {
        die("Invalid parameters");
    }
    exit;
}

// Get invoice details first for validation
$invoiceData = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoiceData) {
    logTransaction($gatewayModuleName, array_merge($_POST, ['client_ip' => $clientIP]), "Invoice Not Found");
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1");
    } else {
        die("Invoice not found");
    }
    exit;
}

// Verify client owns this invoice
if ($invoiceData->userid != $clientId) {
    logTransaction($gatewayModuleName, array_merge($_POST, ['client_ip' => $clientIP]), "Invoice Ownership Mismatch");
    logActivity("STK Push invoice ownership mismatch for Invoice #$invoiceId from IP: $clientIP - Invoice belongs to user {$invoiceData->userid}, requested by user $clientId");
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1");
    } else {
        die("Unauthorized access");
    }
    exit;
}

// Security validation - verify the security token
$expectedToken = md5($invoiceId . $clientId . session_id() . 'woza_stkpush');
if (empty($securityToken) || $securityToken !== $expectedToken) {
    logTransaction($gatewayModuleName, array_merge($_POST, ['client_ip' => $clientIP]), "Security Token Validation Failed");
    
    // Log unauthorized access attempt with detailed information
    logActivity("Unauthorized STK Push attempt for Invoice #$invoiceId from IP: $clientIP, User-Agent: " . substr($userAgent, 0, 100));
    
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1&error=" . urlencode("Security validation failed"));
    } else {
        die("Security validation failed");
    }
    exit;
}

// Enhanced client session validation - handle both logged-in users and token-based access
$currentClientId = null;
$isAuthenticated = false;

// Check if user is logged in via WHMCS session
if (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
    $currentClientId = (int)$_SESSION['uid'];
    $isAuthenticated = true;
} elseif (isset($_SESSION['adminid']) && !empty($_SESSION['adminid'])) {
    // Allow admin access
    $currentClientId = $clientId;
    $isAuthenticated = true;
} else {
    // Check for token-based authentication session flag
    $tokenAuthKey = 'woza_token_auth_' . $invoiceId;
    if (isset($_SESSION[$tokenAuthKey])) {
        $tokenAuth = $_SESSION[$tokenAuthKey];
        
        // Verify token auth is still valid
        if ($tokenAuth['expires'] > time() && 
            $tokenAuth['client_id'] == $clientId && 
            $tokenAuth['ip'] == $clientIP) {
            
            $currentClientId = $clientId;
            $isAuthenticated = true;
            
            // Log this authentication method
            logActivity("STK Push authenticated via token session for Invoice #$invoiceId from IP: $clientIP");
        } else {
            // Token auth expired or invalid, remove it
            unset($_SESSION[$tokenAuthKey]);
            logActivity("Expired/invalid token auth attempted for Invoice #$invoiceId from IP: $clientIP");
        }
    }
    
    // Fallback: Check referrer if token auth not available
    if (!$isAuthenticated) {
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        $expectedReferrer = $gatewayParams['systemurl'] . '/payment.php';
        
        if (strpos($referrer, $expectedReferrer) === 0) {
            // Referrer is from payment page, allow this as authenticated
            $currentClientId = $clientId;
            $isAuthenticated = true;
            
            // Log this special case
            logActivity("STK Push allowed via referrer validation for Invoice #$invoiceId from IP: $clientIP");
        }
    }
}

if (!$isAuthenticated || $currentClientId === null || $currentClientId !== $clientId) {
    logTransaction($gatewayModuleName, array_merge($_POST, ['client_ip' => $clientIP]), "Client Session Validation Failed");
    logActivity("Unauthorized STK Push attempt for Invoice #$invoiceId - Client authentication failed from IP: $clientIP, Expected: $clientId, Got: $currentClientId, Session UID: " . ($_SESSION['uid'] ?? 'none') . ", Token Auth: " . (isset($_SESSION[$tokenAuthKey]) ? 'present' : 'missing'));
    
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1&error=" . urlencode("Authentication required"));
    } else {
        die("Authentication required");
    }
    exit;
}

// Validate amount - M-pesa only accepts whole numbers
$amount = round($amount);
if ($amount < 1) {
    logTransaction($gatewayModuleName, $_POST, "Invalid Amount - Must be at least 1");
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1&error=" . urlencode("Amount must be at least 1 KES"));
    } else {
        die("Amount must be at least 1 KES");
    }
    exit;
}

// Format phone number for M-pesa
try {
    $phoneNumber = formatPhoneNumber($phoneNumber);
} catch (Exception $e) {
    logTransaction($gatewayModuleName, $_POST, "Invalid Phone Number: " . $e->getMessage());
    if ($returnUrl) {
        header("Location: " . $returnUrl . "&paymentfailed=1&error=" . urlencode($e->getMessage()));
    } else {
        die("Invalid phone number: " . $e->getMessage());
    }
    exit;
}

// Get system URL for callback
$systemUrl = $gatewayParams['systemurl'];
$callbackUrl = !empty($gatewayParams['callbackUrl']) ? $gatewayParams['callbackUrl'] : $systemUrl . '/modules/gateways/woza/callback/woza.php';

try {
    // Prepare STK Push
    $response = initiateSTKPush(
        $gatewayParams,
        $phoneNumber,
        $amount,
        $invoiceId,
        $callbackUrl
    );

    // Parse response
    $responseData = json_decode($response, true);

    // Check for successful initiation
    if (isset($responseData['ResponseCode']) && $responseData['ResponseCode'] == '0') {
        // Store the checkout request ID for callback verification
        $checkoutRequestId = $responseData['CheckoutRequestID'];
        
        // Store transaction info in a temporary table
        storeTransactionInfo($invoiceId, $checkoutRequestId, $amount, $phoneNumber);
        
        // Construct redirect URL to payment page in waiting mode
        $redirectUrl = $systemUrl . "payment.php?invoice_id=" . $invoiceId . "&checkout_request_id=" . $checkoutRequestId;
        
        // Preserve token authentication if user accessed via token
        $tokenAuthKey = 'woza_token_auth_' . $invoiceId;
        if (isset($_SESSION[$tokenAuthKey])) {
            // User is authenticated via token, generate a new token for the redirect
            $token = md5($invoiceId . $invoiceData->userid . date('Y-m-d') . 'woza_payment_security');
            $redirectUrl .= "&token=" . $token;
        }
        
        // Only include returnUrl if it's not empty and doesn't point to client area
        if ($returnUrl && !empty($returnUrl)) {
            // Check if returnUrl is not a client area URL (to avoid redirecting away from payment page)
            if (strpos($returnUrl, '/clientarea.php') === false && 
                strpos($returnUrl, '/viewinvoice.php') === false) {
                $redirectUrl .= "&return_url=" . urlencode($returnUrl);
            }
        }
        
        logActivity("STK Push successful for Invoice #$invoiceId, redirecting to waiting mode from IP: $clientIP");
        header("Location: " . $redirectUrl);
        exit;
    } else {
        // STK push request failed
        $errorCode = isset($responseData['ResponseCode']) ? $responseData['ResponseCode'] : 'UNKNOWN';
        $errorMessage = isset($responseData['errorMessage']) ? $responseData['errorMessage'] : 
                       (isset($responseData['ResponseDescription']) ? $responseData['ResponseDescription'] : 'STK Push request failed');
        
        // Enhanced error message based on official error codes
        $userFriendlyMessage = getMpesaErrorMessage($errorCode, $errorMessage);
        
        logTransaction($gatewayModuleName, $responseData, "STK Push Failed - Code: $errorCode, Message: $errorMessage");
        
        // Redirect back to payment page with error information
        $redirectUrl = $systemUrl . "payment.php?invoice_id=" . $invoiceId;
        $redirectUrl .= "&stkpush_failed=1";
        $redirectUrl .= "&error_code=" . urlencode($errorCode);
        $redirectUrl .= "&error_message=" . urlencode($userFriendlyMessage);
        $redirectUrl .= "&retry_phone=" . urlencode($phoneNumber);
        
        // Preserve token authentication if user accessed via token
        $tokenAuthKey = 'woza_token_auth_' . $invoiceId;
        if (isset($_SESSION[$tokenAuthKey])) {
            $token = md5($invoiceId . $invoiceData->userid . date('Y-m-d') . 'woza_payment_security');
            $redirectUrl .= "&token=" . $token;
        }
        
        if ($returnUrl && !empty($returnUrl)) {
            // Check if returnUrl is not a client area URL
            if (strpos($returnUrl, '/clientarea.php') === false && 
                strpos($returnUrl, '/viewinvoice.php') === false) {
                $redirectUrl .= "&return_url=" . urlencode($returnUrl);
        }
        }
        
        header("Location: " . $redirectUrl);
        exit;
    }

} catch (Exception $e) {
    logTransaction($gatewayModuleName, $_POST, "Exception: " . $e->getMessage());
    
    // Redirect back to payment page with exception information
    $redirectUrl = $systemUrl . "payment.php?invoice_id=" . $invoiceId;
    $redirectUrl .= "&stkpush_failed=1";
    $redirectUrl .= "&error_code=EXCEPTION";
    $redirectUrl .= "&error_message=" . urlencode($e->getMessage());
    $redirectUrl .= "&retry_phone=" . urlencode($phoneNumber);
    
    // Preserve token authentication if user accessed via token
    $tokenAuthKey = 'woza_token_auth_' . $invoiceId;
    if (isset($_SESSION[$tokenAuthKey])) {
        $token = md5($invoiceId . $invoiceData->userid . date('Y-m-d') . 'woza_payment_security');
        $redirectUrl .= "&token=" . $token;
    }
    
    if ($returnUrl && !empty($returnUrl)) {
        // Check if returnUrl is not a client area URL
        if (strpos($returnUrl, '/clientarea.php') === false && 
            strpos($returnUrl, '/viewinvoice.php') === false) {
            $redirectUrl .= "&return_url=" . urlencode($returnUrl);
    }
    }
    
    header("Location: " . $redirectUrl);
    exit;
}

/**
 * Initiate STK Push to M-pesa.
 *
 * @param array $params Gateway parameters
 * @param string $phone Customer phone number
 * @param float $amount Amount to pay
 * @param int $invoiceId Invoice ID
 * @param string $callbackUrl Callback URL
 * @return string API response
 */
function initiateSTKPush($params, $phone, $amount, $invoiceId, $callbackUrl)
{
    // Gateway Configuration Parameters
    $consumerKey = $params['consumerKey'];
    $consumerSecret = $params['consumerSecret'];
    $shortcode = $params['shortcode'];
    $passkey = $params['passkey'];
    $environment = $params['environment'];
    $companyName = $params['companyname'];

    // Determine API endpoints based on environment
    if ($environment == 'production') {
        $baseUrl = 'https://api.safaricom.co.ke';
    } else {
        $baseUrl = 'https://sandbox.safaricom.co.ke';
    }

    // Authentication URL and STK Push URL
    $authUrl = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $stkPushUrl = $baseUrl . '/mpesa/stkpush/v1/processrequest';

    // Get OAuth token
    $credentials = base64_encode($consumerKey . ':' . $consumerSecret);
    
    $ch = curl_init($authUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $credentials,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    
    if (curl_error($ch)) {
        curl_close($ch);
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);
    
    $authResponse = json_decode($response, true);
    
    if (!isset($authResponse['access_token'])) {
        throw new Exception('Authentication failed: ' . $response);
    }
    
    $accessToken = $authResponse['access_token'];
    
    // Prepare timestamp
    $timestamp = date('YmdHis');
    
    // Prepare password
    $password = base64_encode($shortcode . $passkey . $timestamp);
    
    // M-pesa only accepts whole numbers (no decimals)
    $amount = (int)round($amount);
    
    // Prepare Account Reference (max 12 characters as per API documentation)
    $accountReference = 'INV' . $invoiceId;
    if (strlen($accountReference) > 12) {
        $accountReference = substr($accountReference, 0, 12);
    }
    
    // Prepare Transaction Description (max 13 characters as per API documentation)
    $transactionDesc = 'Invoice ' . $invoiceId;
    if (strlen($transactionDesc) > 13) {
        $transactionDesc = substr($transactionDesc, 0, 13);
    }
    
    // Prepare STK Push request data
    $stkPushData = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $shortcode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => $accountReference,
        'TransactionDesc' => $transactionDesc
    ];
    
    // Initiate STK Push
    $ch = curl_init($stkPushUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stkPushData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    
    if (curl_error($ch)) {
        curl_close($ch);
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);
    
    return $response;
}

/**
 * Store transaction information.
 *
 * @param int $invoiceId Invoice ID
 * @param string $checkoutRequestId M-pesa Checkout Request ID
 * @param float $amount Amount
 * @param string $phone Phone number
 */
function storeTransactionInfo($invoiceId, $checkoutRequestId, $amount, $phone)
{
    try {
        // Use WHMCS database functions
        $pdo = Illuminate\Database\Capsule\Manager::connection()->getPdo();
        
        // Create the table if it doesn't exist
        $createTable = "CREATE TABLE IF NOT EXISTS `mod_mpesa_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `invoice_id` int(11) NOT NULL,
            `checkout_request_id` varchar(255) NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `phone_number` varchar(20) NOT NULL,
            `mpesa_receipt` varchar(255) DEFAULT NULL,
            `transaction_status` varchar(50) DEFAULT 'pending',
            `response_description` text DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `invoice_id` (`invoice_id`),
            KEY `checkout_request_id` (`checkout_request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        
        $pdo->exec($createTable);
        
        // Insert transaction record
        $stmt = $pdo->prepare("INSERT INTO mod_mpesa_transactions (invoice_id, checkout_request_id, amount, phone_number) VALUES (?, ?, ?, ?)");
        $stmt->execute([$invoiceId, $checkoutRequestId, $amount, $phone]);
        
    } catch (Exception $e) {
        // Log error but continue
        logActivity("Failed to store M-pesa transaction: " . $e->getMessage());
    }
}

/**
 * Get user-friendly error message based on M-pesa error codes.
 * 
 * @param string $errorCode The error code from M-pesa API
 * @param string $originalMessage The original error message
 * @return string User-friendly error message
 */
function getMpesaErrorMessage($errorCode, $originalMessage)
{
    $errorMessages = [
        '1037' => 'Unable to reach your phone. Please ensure your phone is on and try again, or update your SIM card by dialing *234*1*6#.',
        '1025' => 'System error occurred. Please try again in a few minutes.',
        '9999' => 'System error occurred. Please try again in a few minutes.',
        '1032' => 'Payment was cancelled. Please try again.',
        '1' => 'Insufficient balance. Please top up your M-PESA account or use Fuliza when prompted.',
        '2001' => 'Invalid M-PESA PIN entered. Please try again with the correct PIN.',
        '1019' => 'Transaction expired. Please try again.',
        '1001' => 'You have another M-PESA transaction in progress. Please wait 2-3 minutes and try again.',
        '404.001.03' => 'Invalid request. Please contact support if this persists.',
    ];
    
    return isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : $originalMessage;
}

/**
 * Format phone number for M-pesa STK Push (254XXXXXXXX format).
 * 
 * @param string $phone The phone number to format
 * @return string Formatted phone number in 254XXXXXXXX format
 */
function formatPhoneNumber($phone)
{
    // Remove any non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Handle different input formats and convert to 254XXXXXXXX
    if (substr($phone, 0, 4) === '+254') {
        // Remove the + sign, keep 254XXXXXXXX
        $phone = substr($phone, 1);
    } elseif (substr($phone, 0, 3) === '254') {
        // Already in correct format 254XXXXXXXX
        $phone = $phone;
    } elseif (substr($phone, 0, 1) === '0') {
        // Convert 07XXXXXXXX or 01XXXXXXXX to 254XXXXXXXX
        $phone = '254' . substr($phone, 1);
    } elseif (strlen($phone) === 9) {
        // Handle 7XXXXXXXX or 1XXXXXXXX format
        $phone = '254' . $phone;
    } else {
        // If none of the above, assume it needs 254 prefix
        $phone = '254' . $phone;
    }
    
    // Validate the final format (should be 254 followed by 9 digits)
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        throw new Exception('Invalid phone number format. Expected format: 254XXXXXXXX');
    }
    
    return $phone;
}

 