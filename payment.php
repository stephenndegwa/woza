<?php
/**
 * WHMCS M-pesa Payment Page
 *
 * This page provides a dedicated interface for M-pesa payments
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

// Security: Block suspicious IPs and known bad actors
$blockedIPs = [
    // Add any known malicious IPs here
    '127.0.0.1', // Example - remove this
];

// Remove the example IP
$blockedIPs = array_filter($blockedIPs, function($ip) { return $ip !== '127.0.0.1'; });

if (in_array($clientIP, $blockedIPs)) {
    http_response_code(403);
    die('Access denied from your IP address.');
}

// Security: Rate limiting per IP
$rateLimitKey = 'payment_attempts_' . md5($clientIP);
$maxAttempts = 50; // Max attempts per hour
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
    die('Too many requests. Please try again later.');
}

// Increment counter for this request
$_SESSION[$rateLimitKey]['count']++;

// Security: Basic request validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate Content-Type for POST requests
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_contains($contentType, 'application/x-www-form-urlencoded') && 
        !str_contains($contentType, 'multipart/form-data')) {
        http_response_code(400);
        die('Invalid content type.');
    }
    
    // Check for common attack patterns in POST data
    $postData = json_encode($_POST);
    $suspiciousPatterns = [
        '/<script/i', '/javascript:/i', '/vbscript:/i', '/onload/i', '/onerror/i',
        '/union.*select/i', '/drop.*table/i', '/insert.*into/i', '/delete.*from/i',
        '/exec/i', '/system/i', '/cmd/i', '/eval/i', '/base64_decode/i'
    ];
    
    foreach ($suspiciousPatterns as $pattern) {
        if (preg_match($pattern, $postData)) {
            http_response_code(400);
            logActivity("Suspicious POST data detected from IP $clientIP: " . substr($postData, 0, 200));
            die('Invalid request data.');
        }
    }
}

// Security: Validate User-Agent
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (empty($userAgent) || strlen($userAgent) < 10) {
    http_response_code(400);
    die('Invalid user agent.');
}

// Security: Block common bot patterns
$botPatterns = [
    '/bot/i', '/crawler/i', '/spider/i', '/scraper/i', '/curl/i', '/wget/i'
];

foreach ($botPatterns as $pattern) {
    if (preg_match($pattern, $userAgent)) {
        // Allow legitimate bots but log them
        logActivity("Bot access detected from IP $clientIP: $userAgent");
        break;
    }
}

// Security: CSRF Protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($csrfToken) || empty($expectedToken) || !hash_equals($expectedToken, $csrfToken)) {
        http_response_code(403);
        die('Invalid security token. Please refresh the page and try again.');
    }
}

// Generate CSRF token for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Security: Check for SSL/HTTPS in production
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']);
    if (!$isLocalhost) {
        // Redirect to HTTPS in production
        $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header("Location: $redirectURL", true, 301);
        exit;
    }
}

// Security: Additional headers for protection
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Include the WHMCS core
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

// Get parameters from URL
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$returnUrl = isset($_GET['return_url']) ? $_GET['return_url'] : '';
$retryPhone = isset($_GET['retry_phone']) ? $_GET['retry_phone'] : '';
$checkoutRequestId = isset($_GET['checkout_request_id']) ? $_GET['checkout_request_id'] : '';
$waitingMode = !empty($checkoutRequestId);

// Handle STK Push failure
$stkPushFailed = isset($_GET['stkpush_failed']) && $_GET['stkpush_failed'] == '1';
$errorCode = isset($_GET['error_code']) ? $_GET['error_code'] : '';
$errorMessage = isset($_GET['error_message']) ? $_GET['error_message'] : '';

// Track if user is accessing via token (for returnUrl handling)
$isTokenAccess = isset($_GET['token']) && !empty($_GET['token']);

// Validate invoice ID
if (!$invoiceId) {
    die('Invalid invoice ID');
}

// Get invoice details
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    die('Invoice not found');
}

// Get client details
$client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
if (!$client) {
    die('Client not found');
}

// Security Check: Verify client authorization
$currentClientId = null;

// Check if user is logged in via WHMCS session
if (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
    $currentClientId = (int)$_SESSION['uid'];
} elseif (isset($_SESSION['adminid']) && !empty($_SESSION['adminid'])) {
    // Allow admin access to any invoice
    $currentClientId = $invoice->userid;
} else {
    // Check if client is accessing via direct link with security token
    $securityToken = isset($_GET['token']) ? $_GET['token'] : '';
    if (!empty($securityToken)) {
        // Verify security token - check today's token first, then yesterday's for timezone differences
        $expectedTokenToday = md5($invoiceId . $invoice->userid . date('Y-m-d') . 'woza_payment_security');
        $expectedTokenYesterday = md5($invoiceId . $invoice->userid . date('Y-m-d', strtotime('-1 day')) . 'woza_payment_security');
        
        if ($securityToken === $expectedTokenToday || $securityToken === $expectedTokenYesterday) {
            $currentClientId = $invoice->userid;
            // Set session flag for token-based authentication
            $_SESSION['woza_token_auth_' . $invoiceId] = [
                'client_id' => $invoice->userid,
                'expires' => time() + 3600, // 1 hour
                'ip' => $clientIP
            ];
        }
    }
}

// If no valid authentication found, deny access
if ($currentClientId === null || $currentClientId !== $invoice->userid) {
    // Log unauthorized access attempt
    logActivity("Unauthorized payment page access attempt for Invoice #$invoiceId from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
    
    // Redirect to client login or show error
    if (!isset($_SESSION['uid'])) {
        header("Location: " . $systemUrl . "login.php?goto=" . urlencode($_SERVER['REQUEST_URI']));
        exit;
    } else {
        die('Access denied. You can only view your own invoices.');
    }
}

// Get gateway parameters
$gatewayParams = getGatewayVariables('woza');
if (!$gatewayParams['type']) {
    die('M-pesa gateway is not activated');
}

// Validate required gateway configuration
if (empty($gatewayParams['consumerKey']) || empty($gatewayParams['consumerSecret']) || 
    empty($gatewayParams['shortcode']) || empty($gatewayParams['passkey'])) {
    die('M-pesa gateway is not properly configured');
}

// Get system URL
$systemUrl = $gatewayParams['systemurl'];
$companyName = $gatewayParams['companyname'];

// Handle returnUrl for token-based access
if ($isTokenAccess) {
    // Token-based access - redirect to viewinvoice.php with paymentsuccess=1 
    // WHMCS will handle authentication automatically for token users
    $token = $_GET['token'];
    $invoiceReturnUrl = $systemUrl . "payment.php?invoice_id=" . $invoiceId . "&token=" . $token;
    $successReturnUrl = $systemUrl . "viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=1";
} elseif (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
    // Logged in client - return to client area invoice view
    $invoiceReturnUrl = $systemUrl . "viewinvoice.php?id=" . $invoiceId;
    $successReturnUrl = $systemUrl . "viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=1";
} elseif (isset($_SESSION['adminid']) && !empty($_SESSION['adminid'])) {
    // Admin access - return to admin invoice view
    $invoiceReturnUrl = $systemUrl . "invoices.php?action=edit&id=" . $invoiceId;
    $successReturnUrl = $systemUrl . "invoices.php?action=edit&id=" . $invoiceId . "&paymentsuccess=1";
} else {
    // Fallback - use provided returnUrl or default to payment page
    $invoiceReturnUrl = $returnUrl ?: ($systemUrl . "payment.php?invoice_id=" . $invoiceId);
    $successReturnUrl = $returnUrl ? ($returnUrl . (strpos($returnUrl, '?') !== false ? '&' : '?') . 'paymentsuccess=1') : ($systemUrl . "payment.php?invoice_id=" . $invoiceId . "&payment_complete=1");
}

// Get transaction timeout from gateway settings
$transactionTimeout = isset($gatewayParams['transactionTimeout']) ? (int)$gatewayParams['transactionTimeout'] : 60;

// Get transaction details if in waiting mode
$transaction = null;
$originalPhoneNumber = '';
if ($waitingMode && $checkoutRequestId) {
    $transaction = Capsule::table('mod_mpesa_transactions')
        ->where('checkout_request_id', $checkoutRequestId)
        ->where('invoice_id', $invoiceId)
        ->first();
    $originalPhoneNumber = $transaction ? $transaction->phone_number : '';
}

// Format phone number for display
function formatPhoneForDisplay($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 4) === '+254') {
        $phone = '0' . substr($phone, 4);
    } elseif (substr($phone, 0, 3) === '254') {
        $phone = '0' . substr($phone, 3);
    } elseif (substr($phone, 0, 1) !== '0') {
        $phone = '0' . $phone;
    }
    return $phone;
}

// Use retry phone if provided (from retry or STK push failure), otherwise use client's default phone
$clientPhone = $retryPhone ? formatPhoneForDisplay($retryPhone) : formatPhoneForDisplay($client->phonenumber);

// Handle offline payment submission
$returnData = "";
if (isset($_POST['submit_offline_payment'])) {
    // Additional security checks for POST requests
    if ($currentClientId !== $invoice->userid) {
        // Log unauthorized access attempt with details
        logActivity("Unauthorized offline payment attempt for Invoice #$invoiceId from IP: $clientIP, Session: " . session_id());
        $returnData = "<div class='alert alert-danger'><strong>Security Error:</strong> Unauthorized access attempt.</div>";
    } else {
        // Additional rate limiting for payment submissions
        $paymentRateLimitKey = 'payment_submissions_' . md5($clientIP . $invoiceId);
        $maxPaymentAttempts = 10; // Max payment attempts per hour per invoice
        
        if (!isset($_SESSION[$paymentRateLimitKey])) {
            $_SESSION[$paymentRateLimitKey] = ['count' => 0, 'reset_time' => time() + 3600];
        }
        
        if (time() > $_SESSION[$paymentRateLimitKey]['reset_time']) {
            $_SESSION[$paymentRateLimitKey] = ['count' => 0, 'reset_time' => time() + 3600];
        }
        
        if ($_SESSION[$paymentRateLimitKey]['count'] >= $maxPaymentAttempts) {
            logActivity("Payment submission rate limit exceeded for Invoice #$invoiceId from IP: $clientIP");
            $returnData = "<div class='alert alert-warning'><strong>Rate Limit:</strong> Too many payment attempts. Please wait before trying again.</div>";
        } else {
            $_SESSION[$paymentRateLimitKey]['count']++;
            
    try {
        $mpesaCode = trim($_POST['mpesa_code'] ?? '');
        $paymentPhone = trim($_POST['payment_phone'] ?? '');
        $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
        
        // Enhanced input validation and sanitization
        if (empty($mpesaCode)) {
            $returnData = "<div class='alert alert-danger'><strong>Error:</strong> Please enter the M-pesa transaction code.</div>";
        } elseif (!preg_match('/^[A-Z0-9]{8,12}$/i', $mpesaCode)) {
            logActivity("Invalid M-pesa code format attempted for Invoice #$invoiceId from IP: $clientIP: " . substr($mpesaCode, 0, 20));
            $returnData = "<div class='alert alert-danger'><strong>Error:</strong> Invalid M-pesa transaction code format.</div>";
        } else {
            // Sanitize M-pesa code
            $mpesaCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $mpesaCode));
            
            // Check if this M-pesa code has already been used in WHMCS payments
            $existingPayment = Capsule::table('tblaccounts')
                ->where('transid', $mpesaCode)
                ->first();
            
            if ($existingPayment) {
                logActivity("Duplicate M-pesa code attempted for Invoice #$invoiceId from IP: $clientIP: $mpesaCode");
                $returnData = "<div class='alert alert-danger'><strong>Error:</strong> This M-pesa transaction code has already been used for payment.</div>";
            } else {
                // Look up the transaction in C2B confirmations database
                $c2bConfirmation = Capsule::table('mod_mpesa_c2b_confirmations')
                    ->where('trans_id', $mpesaCode)
                    ->first();
                
                if ($c2bConfirmation) {
                    // Found the transaction in C2B confirmations
                    $confirmedAmount = (float)$c2bConfirmation->trans_amount;
                    $billRefNumber = $c2bConfirmation->bill_ref_number;
                    $customerName = $c2bConfirmation->first_name;
                    
                    // Enhanced security: Check transaction age (prevent old transaction reuse)
                    $transactionAge = time() - strtotime($c2bConfirmation->created_at);
                    $maxTransactionAge = 7 * 24 * 3600; // 7 days
                    
                    if ($transactionAge > $maxTransactionAge) {
                        logActivity("Old M-pesa transaction attempted for Invoice #$invoiceId from IP: $clientIP: $mpesaCode (Age: " . round($transactionAge/86400) . " days)");
                        $returnData = "<div class='alert alert-warning'><strong>Transaction Too Old:</strong> This M-pesa transaction is too old to process automatically. Please contact support.</div>";
                    } else {
                        // Verify the amount matches (allow small differences due to fees)
                        $amountDifference = abs($confirmedAmount - $invoice->total);
                        $maxAllowedDifference = 5.00; // Allow up to 5 KES difference
                        
                        if ($amountDifference > $maxAllowedDifference) {
                            $returnData = "<div class='alert alert-warning'><strong>Amount Mismatch:</strong> The confirmed amount (KES " . number_format($confirmedAmount, 2) . ") doesn't match the invoice total (KES " . number_format($invoice->total, 2) . "). Please contact support if this is correct.</div>";
                        } else {
                            // Check if this transaction is for the correct invoice
                            $isCorrectInvoice = false;
                            
                            // Check if bill reference matches this invoice
                            if (is_numeric($billRefNumber) && (int)$billRefNumber == $invoiceId) {
                                $isCorrectInvoice = true;
                            } elseif (preg_match('/(?:inv|invoice)(\d+)/i', $billRefNumber, $matches) && (int)$matches[1] == $invoiceId) {
                                $isCorrectInvoice = true;
                            }
                            
                            if (!$isCorrectInvoice && !empty($billRefNumber)) {
                                $returnData = "<div class='alert alert-warning'><strong>Invoice Mismatch:</strong> This M-pesa payment was made for reference '$billRefNumber' but you're trying to apply it to Invoice #$invoiceId. Please verify this is correct or contact support.</div>";
                            } else {
                                // All checks passed - process the payment automatically
                                $success = addInvoicePayment(
                                    $invoiceId,
                                    $mpesaCode,
                                    $confirmedAmount,
                                    0, // No fees
                                    'woza'
                                );
                                
                                if ($success) {
                                    // Update the C2B confirmation record
                                    Capsule::table('mod_mpesa_c2b_confirmations')
                                        ->where('trans_id', $mpesaCode)
                                        ->update([
                                            'processed' => 1,
                                            'invoice_id' => $invoiceId,
                                            'payment_added' => 1,
                                            'notes' => 'Processed via offline payment form by client from IP: ' . $clientIP,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ]);
                                    
                                    // Log successful processing with security details
                                    logTransaction('woza', [
                                        'invoice_id' => $invoiceId,
                                        'trans_id' => $mpesaCode,
                                        'amount' => $confirmedAmount,
                                        'customer_name' => $customerName,
                                        'bill_ref' => $billRefNumber,
                                        'processed_by' => 'offline_form',
                                        'client_ip' => $clientIP,
                                        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)
                                    ], 'Offline Payment Processed Successfully');
                                    
                                    $returnData = "<div class='alert alert-success'><strong>Payment Processed!</strong> Your M-pesa payment of KES " . number_format($confirmedAmount, 2) . " has been successfully applied to Invoice #$invoiceId. Transaction ID: $mpesaCode</div>";
                                    
                                    // Add auto-redirect after successful payment
                                    echo "<script>setTimeout(function(){ window.location.href = '" . $successReturnUrl . "'; }, 3000);</script>";
                                } else {
                                    logActivity("Payment processing failed for Invoice #$invoiceId, M-pesa code: $mpesaCode from IP: $clientIP");
                                    $returnData = "<div class='alert alert-danger'><strong>Processing Error:</strong> Payment verification successful but failed to add payment to your account. Please contact support with transaction code: $mpesaCode</div>";
                                }
                            }
                        }
                    }
                } else {
                    // Transaction not found in C2B confirmations - fallback to manual verification
                    if (empty($paymentPhone) || $paymentAmount <= 0) {
                        $returnData = "<div class='alert alert-warning'><strong>Transaction Not Found:</strong> We couldn't automatically verify this M-pesa code. Please provide your phone number and payment amount for manual verification.</div>";
                    } else {
                        // Enhanced validation for manual verification
                        if (!preg_match('/^(0[17][0-9]{8}|254[17][0-9]{8})$/', preg_replace('/[^0-9]/', '', $paymentPhone))) {
                            $returnData = "<div class='alert alert-danger'><strong>Error:</strong> Please enter a valid phone number format.</div>";
                        } elseif ($paymentAmount < 1 || $paymentAmount > 1000000) {
                            $returnData = "<div class='alert alert-danger'><strong>Error:</strong> Please enter a valid payment amount.</div>";
                        } else {
                            // Store for manual verification with enhanced security logging
                            Capsule::table('mod_mpesa_offline_payments')->insert([
                                'invoice_id' => $invoiceId,
                                'mpesa_code' => $mpesaCode,
                                'phone_number' => $paymentPhone,
                                'amount' => $paymentAmount,
                                'status' => 'pending_verification',
                                'submitted_at' => date('Y-m-d H:i:s'),
                                'client_id' => $invoice->userid
                            ]);
                            
                            // Log manual verification request
                            logActivity("Manual payment verification requested for Invoice #$invoiceId, M-pesa code: $mpesaCode from IP: $clientIP");
                            
                            $returnData = "<div class='alert alert-info'><strong>Submitted for Verification:</strong> Your payment details have been submitted for manual verification. This may take a few minutes to process. You will be notified once verified.</div>";
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        logActivity("Payment processing error for Invoice #$invoiceId from IP: $clientIP - " . $e->getMessage());
        $returnData = "<div class='alert alert-danger'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
        } // End of rate limiting check
    } // End of security check for offline payment
}

// Handle payment verification
if (isset($_POST['verifypayment']) && $_POST['verifypayment']) {
    // Additional security check for POST requests
    if ($currentClientId !== $invoice->userid) {
        $returnData = "<div class='alert alert-danger'><strong>Security Error:</strong> Unauthorized access attempt.</div>";
    } else {
    try {
        // Check if invoice is already paid
        $currentInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($currentInvoice && $currentInvoice->status == 'Paid') {
            $returnData = "<div class='alert alert-success'><strong>Success!</strong> Payment verified and processed. Redirecting...</div>";
            echo "<script>setTimeout(function(){ window.location.href = '" . $successReturnUrl . "'; }, 2000);</script>";
        } else {
            // Check for pending M-pesa transactions
            $transaction = Capsule::table('mod_mpesa_transactions')
                ->where('invoice_id', $invoiceId)
                ->where('transaction_status', 'pending')
                ->orderBy('created_at', 'desc')
                ->first();
            
            if (!$transaction) {
                $returnData = "<div class='alert alert-warning'><strong>Notice:</strong> No pending M-pesa transaction found. Please initiate payment first.</div>";
            } else {
                $returnData = "<div class='alert alert-info'><strong>Info:</strong> Payment verification is in progress. Please wait a moment and try again.</div>";
            }
        }
    } catch (Exception $e) {
        $returnData = "<div class='alert alert-danger'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    } // End of security check for payment verification
}

// Create offline payments table if it doesn't exist
try {
    $pdo = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $createOfflineTable = "CREATE TABLE IF NOT EXISTS `mod_mpesa_offline_payments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_id` int(11) NOT NULL,
        `client_id` int(11) NOT NULL,
        `mpesa_code` varchar(255) NOT NULL,
        `phone_number` varchar(20) NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `status` varchar(50) DEFAULT 'pending_verification',
        `submitted_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `verified_at` timestamp NULL DEFAULT NULL,
        `verified_by` int(11) NULL DEFAULT NULL,
        `notes` text DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `invoice_id` (`invoice_id`),
        KEY `mpesa_code` (`mpesa_code`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    $pdo->exec($createOfflineTable);
} catch (Exception $e) {
    // Continue if table creation fails
}

// Handle payment status check via AJAX
if (isset($_POST['check_payment_status']) && $_POST['check_payment_status'] == '1') {
    header('Content-Type: application/json');
    
    $debugInfo = [];
    
    try {
        // Check if invoice is already paid
        $currentInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        $debugInfo['invoice_status'] = $currentInvoice ? $currentInvoice->status : 'not_found';
        $debugInfo['invoice_total'] = $currentInvoice ? $currentInvoice->total : 0;
        
        if ($currentInvoice && $currentInvoice->status == 'Paid') {
            $debugInfo['result'] = 'invoice_already_paid';
            echo json_encode([
                'status' => 'paid',
                'message' => 'Payment completed successfully',
                'redirect_url' => $successReturnUrl,
                'debug' => $debugInfo
            ]);
        } else {
            // Check for recent payments in the last 10 minutes
            $recentPayment = Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->where('date', '>=', date('Y-m-d H:i:s', strtotime('-10 minutes')))
                ->first();
            
            $debugInfo['recent_payment_found'] = $recentPayment ? true : false;
            
            if ($recentPayment) {
                $debugInfo['result'] = 'recent_payment_found';
                $debugInfo['payment_id'] = $recentPayment->id;
                $debugInfo['payment_amount'] = $recentPayment->amountin;
                
                echo json_encode([
                    'status' => 'paid',
                    'message' => 'Payment found and processed',
                    'redirect_url' => $successReturnUrl,
                    'debug' => $debugInfo
                ]);
            } else {
                // Check for pending C2B confirmations that match this invoice
                $pendingPayment = Capsule::table('mod_mpesa_c2b_confirmations')
                    ->where('bill_ref_number', 'INV' . $invoiceId)
                    ->where('processed', 0)
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 minutes')))
                    ->first();
                
                $debugInfo['pending_payment_found'] = $pendingPayment ? true : false;
                
                if ($pendingPayment) {
                    // Found unprocessed payment - validate before processing
                    $confirmedAmount = (float)$pendingPayment->trans_amount;
                    $amountDifference = abs($confirmedAmount - $invoice->total);
                    
                    $debugInfo['confirmed_amount'] = $confirmedAmount;
                    $debugInfo['invoice_amount'] = $invoice->total;
                    $debugInfo['amount_difference'] = $amountDifference;
                    
                    if ($amountDifference <= 5.00) { // Allow 5 KES difference
                        // Additional validation: Check if this transaction hasn't been processed already
                        $existingPayment = Capsule::table('tblaccounts')
                            ->where('transid', $pendingPayment->trans_id)
                            ->first();
                        
                        if ($existingPayment) {
                            $debugInfo['result'] = 'transaction_already_processed';
                            echo json_encode([
                                'status' => 'paid',
                                'message' => 'Payment already processed',
                                'redirect_url' => $successReturnUrl,
                                'debug' => $debugInfo
                            ]);
                        } else {
                            $success = addInvoicePayment(
                                $invoiceId,
                                $pendingPayment->trans_id,
                                $confirmedAmount,
                                0,
                                'woza'
                            );
                            
                            $debugInfo['payment_add_success'] = $success;
                            
                            if ($success) {
                                // Mark as processed
                                Capsule::table('mod_mpesa_c2b_confirmations')
                                    ->where('trans_id', $pendingPayment->trans_id)
                                    ->update([
                                        'processed' => 1,
                                        'invoice_id' => $invoiceId,
                                        'payment_added' => 1,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ]);
                                
                                $debugInfo['result'] = 'payment_processed_successfully';
                                echo json_encode([
                                    'status' => 'paid',
                                    'message' => 'Payment found and processed automatically',
                                    'redirect_url' => $successReturnUrl,
                                    'debug' => $debugInfo
                                ]);
                            } else {
                                $debugInfo['result'] = 'payment_processing_failed';
                                echo json_encode([
                                    'status' => 'pending',
                                    'message' => 'Payment found but processing failed',
                                    'debug' => $debugInfo
                                ]);
                            }
                        }
                    } else {
                        $debugInfo['result'] = 'amount_mismatch';
                        echo json_encode([
                            'status' => 'pending',
                            'message' => 'Payment amount mismatch - manual verification needed',
                            'debug' => $debugInfo
                        ]);
                    }
                } else {
                    $debugInfo['result'] = 'no_payment_found';
                    echo json_encode([
                        'status' => 'not_found',
                        'message' => 'No payment found yet',
                        'debug' => $debugInfo
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        $debugInfo['error'] = $e->getMessage();
        $debugInfo['result'] = 'exception_occurred';
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Error checking payment status',
            'debug' => $debugInfo
        ]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M-pesa Payment - <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .payment-container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .payment-header {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .payment-body {
            padding: 40px;
        }
        .mpesa-logo {
            max-width: 120px;
            margin-bottom: 20px;
        }
        .invoice-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 5px solid #4CAF50;
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25);
        }
        .btn-mpesa {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            border: none;
            border-radius: 10px;
            padding: 15px 30px;
            font-size: 18px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-mpesa:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
            color: white;
        }
        .btn-verify {
            background: #6c757d;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-verify:hover {
            background: #5a6268;
            transform: translateY(-1px);
            color: white;
        }
        .payment-steps {
            background: #e8f5e8;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .step {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        .step:last-child {
            margin-bottom: 0;
        }
        .step-number {
            background: #4CAF50;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        .back-link {
            color: #6c757d;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            margin-bottom: 20px;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #4CAF50;
        }
        .security-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
        }
        .payment-method-tabs {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 30px;
        }
        .payment-tab {
            background: none;
            border: none;
            padding: 15px 30px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
        }
        .payment-tab.active {
            color: #4CAF50;
            border-bottom-color: #4CAF50;
        }
        .payment-tab:hover {
            color: #4CAF50;
        }
        .payment-method-content {
            display: none;
        }
        .payment-method-content.active {
            display: block;
        }
        .offline-steps {
            background: #e8f4fd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 5px solid #007bff;
        }
        .btn-offline {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border: none;
            border-radius: 10px;
            padding: 15px 30px;
            font-size: 18px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-offline:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.4);
            color: white;
        }
        .status-container {
            padding: 25px;
            margin: 25px 0;
            border-radius: 10px;
            background-color: rgba(76, 175, 80, 0.1);
            border: 2px solid rgba(76, 175, 80, 0.3);
            transition: all 0.3s ease;
        }
        .status-container.error {
            background-color: rgba(244, 67, 54, 0.1);
            border-color: rgba(244, 67, 54, 0.3);
        }
        .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid rgba(76, 175, 80, 0.3);
            border-radius: 50%;
            border-top-color: #4CAF50;
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 15px;
        }
        .timer {
            margin-top: 20px;
            font-size: 16px;
            color: #666;
        }
        .error-icon, .success-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .error-icon {
            color: #f44336;
        }
        .success-icon {
            color: #4CAF50;
        }
        .error-details {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
            text-align: left;
        }
        .error-code {
            font-weight: bold;
            color: #856404;
        }
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        .hidden {
            display: none !important;
        }
        
        /* Auto-Detection Styles */
        #auto-detection-container {
            border-left: 4px solid #17a2b8;
            background: #e3f2fd;
        }
        
        #auto-detection-container .spinner-border {
            color: #17a2b8;
        }
        
        .btn-group-payment-check {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
        }
        
        .btn-check-payment {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-check-payment:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.4);
            color: white;
        }
        
        .btn-auto-detect {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-auto-detect:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        #payment-found-alert {
            border-left: 4px solid #28a745;
            background: #d4edda;
            animation: slideInDown 0.5s ease-out;
        }
        
        #manual-entry-form {
            border: 2px dashed #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            background: #fffbf0;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }
        
        .btn-pulse {
            animation: pulse 2s infinite;
        }
        
        /* Mobile responsiveness for new buttons */
        @media (max-width: 768px) {
            .btn-group-payment-check {
                flex-direction: column;
            }
            
            .btn-group-payment-check .btn {
                width: 100%;
                margin: 5px 0;
            }
        }
        
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <img src="<?php echo $systemUrl; ?>/img/mpesa.png" alt="M-pesa Logo" class="mpesa-logo">
            <h2 id="page-title">
                <i class="fas fa-mobile-alt me-2"></i>
                <?php echo $waitingMode ? 'M-pesa Payment in Progress' : 'M-pesa Payment'; ?>
            </h2>
            <p class="mb-0"><?php echo $waitingMode ? 'Processing your payment...' : 'Secure Mobile Money Payment'; ?></p>
        </div>
        
        <div class="payment-body">
            <?php if ($invoiceReturnUrl && !$paymentComplete): ?>
            <a href="<?php echo htmlspecialchars($invoiceReturnUrl); ?>" class="back-link">
                <i class="fas fa-arrow-left me-2"></i>Back to Invoice
            </a>
            <?php endif; ?>
            
            <?php echo $returnData; ?>
            
            <?php if ($stkPushFailed): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>STK Push Failed:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                <?php if ($errorCode): ?>
                <br><small><strong>Error Code:</strong> <?php echo htmlspecialchars($errorCode); ?></small>
                <?php endif; ?>
                <br><small>Please try again or use a different phone number.</small>
            </div>
            <?php endif; ?>
            
            <?php if ($retryPhone && !$stkPushFailed): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Retry Payment:</strong> Using phone number <?php echo htmlspecialchars(formatPhoneForDisplay($retryPhone)); ?>
                <a href="?invoice_id=<?php echo $invoiceId; ?><?php echo $isTokenAccess ? '&token=' . urlencode($_GET['token']) : ''; ?><?php echo $returnUrl ? '&return_url=' . urlencode($returnUrl) : ''; ?>" class="btn btn-sm btn-outline-primary ms-2">
                    <i class="fas fa-undo me-1"></i>Use Original Number
                </a>
            </div>
            <?php elseif ($retryPhone && $stkPushFailed): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Retrying with:</strong> <?php echo htmlspecialchars(formatPhoneForDisplay($retryPhone)); ?>
                <a href="?invoice_id=<?php echo $invoiceId; ?><?php echo $isTokenAccess ? '&token=' . urlencode($_GET['token']) : ''; ?><?php echo $returnUrl ? '&return_url=' . urlencode($returnUrl) : ''; ?>" class="btn btn-sm btn-outline-primary ms-2">
                    <i class="fas fa-undo me-1"></i>Use Original Number
                </a>
            </div>
            <?php endif; ?>
            
            <div class="invoice-details">
                <h5><i class="fas fa-file-invoice me-2"></i>Invoice Details</h5>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice->id); ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Amount:</strong> <?php echo number_format($invoice->total, 2); ?> KES
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-6">
                        <strong>Client:</strong> <?php echo htmlspecialchars($client->firstname . ' ' . $client->lastname); ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Status:</strong> 
                        <span class="badge bg-<?php echo $invoice->status == 'Paid' ? 'success' : 'warning'; ?>">
                            <?php echo htmlspecialchars($invoice->status); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <?php 
            // Check if user is returning after payment completion
            $paymentComplete = isset($_GET['payment_complete']) && $_GET['payment_complete'] == '1';
            
            if ($paymentComplete): ?>
            <div class="alert alert-success text-center">
                <i class="fas fa-check-circle fa-3x mb-3"></i>
                <h4>Payment Completed Successfully!</h4>
                <p>Your M-pesa payment has been processed and submitted for verification.</p>
                <p><strong>Invoice #<?php echo $invoiceId; ?></strong> - <?php echo number_format($invoice->total, 2); ?> KES</p>
                
                <div class="mt-4">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-info-circle me-2"></i>What happens next?</h6>
                            <ul class="text-start">
                                <li>Payment verification is automatic (usually within 1-5 minutes)</li>
                                <li>You'll receive an email confirmation once verified</li>
                                <li>Your services will be activated automatically</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-phone me-2"></i>Need Help?</h6>
                            <p class="text-start">If you don't receive confirmation within 30 minutes, please contact our support team with your M-pesa transaction code.</p>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <a href="<?php echo $systemUrl; ?>/payment.php?invoice_id=<?php echo $invoiceId; ?><?php echo $isTokenAccess ? '&token=' . urlencode($_GET['token']) : ''; ?>" class="btn btn-primary me-2">
                        <i class="fas fa-refresh me-2"></i>Check Payment Status
                    </a>
                    <?php if (!$isTokenAccess): ?>
                    <a href="<?php echo $systemUrl; ?>/clientarea.php" class="btn btn-secondary">
                        <i class="fas fa-home me-2"></i>Return to Client Area
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i>
                        Payment processed at <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
            </div>
            <?php elseif ($invoice->status != 'Paid'): ?>
            
            <!-- Waiting Mode Container (Hidden by default) -->
            <div id="waiting-mode-container" class="<?php echo $waitingMode ? '' : 'hidden'; ?>">
                <div class="payment-steps">
                    <h6><i class="fas fa-clock me-2"></i>Payment in Progress</h6>
                    <div class="step">
                        <div class="step-number"><i class="fas fa-mobile-alt"></i></div>
                        <div>Check your phone for M-pesa payment request</div>
                    </div>
                    <div class="step">
                        <div class="step-number"><i class="fas fa-key"></i></div>
                        <div>Enter your M-pesa PIN to confirm payment</div>
                    </div>
                    <div class="step">
                        <div class="step-number"><i class="fas fa-check"></i></div>
                        <div>Wait for automatic payment confirmation</div>
                    </div>
                </div>

                <div class="status-container" id="processing-container">
                    <div class="spinner" id="processing-spinner"></div>
                    <div class="status-message" id="status-message">
                        Please check your phone and enter your M-pesa PIN when prompted
                    </div>
                    <div class="timer" id="timer">
                        Time remaining: <span id="countdown"><?php echo $transactionTimeout; ?></span> seconds
                    </div>
                </div>

                <!-- Error Container -->
                <div class="status-container error hidden" id="error-container">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="status-message" id="error-message">Payment failed</div>
                    <div class="error-details" id="error-details">
                        <div class="error-code" id="error-code"></div>
                        <div id="error-description"></div>
                    </div>
                    <div class="error-actions" id="error-actions"></div>
                </div>

                <!-- Success Container -->
                <div class="status-container hidden" id="success-container">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="status-message">
                        <strong>Payment Successful!</strong> Redirecting...
                    </div>
                </div>
            </div>
            
            <!-- Payment Method Tabs -->
            <div class="payment-method-tabs <?php echo $waitingMode ? 'hidden' : ''; ?>">
                <button class="payment-tab active" onclick="switchPaymentMethod('stkpush')">
                    <i class="fas fa-mobile-alt me-2"></i>STK Push (Automatic)
                </button>
                <button class="payment-tab" onclick="switchPaymentMethod('offline')">
                    <i class="fas fa-receipt me-2"></i>Offline Payment
                </button>
            </div>

            <!-- STK Push Payment Method -->
            <div id="stkpush-method" class="payment-method-content <?php echo $waitingMode ? 'hidden' : 'active'; ?>">
                <div class="payment-steps">
                    <h6><i class="fas fa-list-ol me-2"></i>STK Push Payment Steps</h6>
                    <div class="step">
                        <div class="step-number">1</div>
                        <div>Enter your M-pesa registered phone number below</div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div>Click "Pay Now" to receive STK Push notification</div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div>Enter your M-pesa PIN when prompted on your phone</div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div>Wait for automatic payment confirmation</div>
                    </div>
                </div>

                <form method="post" action="<?php echo $systemUrl; ?>/stkpush.php" id="mpesaForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
                    <input type="hidden" name="invoice_id" value="<?php echo $invoiceId; ?>" />
                    <input type="hidden" name="amount" value="<?php echo $invoice->total; ?>" />
                    <input type="hidden" name="clientId" value="<?php echo $invoice->userid; ?>" />
                    <input type="hidden" name="returnUrl" value="<?php echo htmlspecialchars($returnUrl); ?>" />
                    <input type="hidden" name="security_token" value="<?php echo md5($invoiceId . $invoice->userid . session_id() . 'woza_stkpush'); ?>" />
                    
                    <div class="mb-4">
                        <label for="mpesa_phone" class="form-label">
                            <i class="fas fa-phone me-2"></i><strong>M-pesa Phone Number</strong>
                        </label>
                        <input type="text" name="mpesa_phone" id="mpesa_phone" class="form-control" 
                               value="<?php echo htmlspecialchars($clientPhone); ?>" required 
                               placeholder="e.g., 0712345678" />
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Accepted formats: 07XXXXXXXX, 01XXXXXXXX, or 254XXXXXXXXX
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-mpesa">
                        <i class="fas fa-mobile-alt me-2"></i>Pay Now - <?php echo number_format($invoice->total, 2); ?> KES
                    </button>
                </form>
                
                <div class="text-center mt-4">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
                        <button type="submit" name="verifypayment" value="1" class="btn btn-verify">
                            <i class="fas fa-check-circle me-2"></i>Verify Payment
                        </button>
                    </form>
                    <div class="form-text mt-2">
                        Click "Verify Payment" if you have completed the payment
                    </div>
                </div>
            </div>

            <!-- Offline Payment Method -->
            <div id="offline-method" class="payment-method-content <?php echo $waitingMode ? 'hidden' : ''; ?>">
                <div class="offline-steps">
                    <h6><i class="fas fa-list-ol me-2"></i>Offline Payment Steps</h6>
                    <div class="step">
                        <div class="step-number">1</div>
                        <div>Send money to Paybill: <strong><?php echo htmlspecialchars($gatewayParams['shortcode']); ?></strong></div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div>Account Number: <strong>INV<?php echo $invoiceId; ?></strong></div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div>Amount: <strong><?php echo number_format($invoice->total, 2); ?> KES</strong></div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div>Wait for automatic detection or click "Check Payment" below</div>
                    </div>
                </div>

                <!-- Auto-Detection Status -->
                <div id="auto-detection-container" class="alert alert-info" style="display: none;">
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        <div>
                            <strong>Auto-detecting payment...</strong>
                            <div class="small">We're automatically checking for your payment. This usually takes 1-3 minutes.</div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small>
                            <i class="fas fa-clock me-1"></i>
                            Checking for <span id="detection-timer">180</span> seconds
                        </small>
                    </div>
                </div>

                <!-- Payment Check Buttons -->
                <div class="text-center mb-4">
                    <div class="alert alert-info">
                        <i class="fas fa-magic me-2"></i>
                        <strong>Auto-Detection Active:</strong> We're automatically checking for your payment every 30 seconds. You can also check manually below.
                    </div>
                    
                    <div class="btn-group-payment-check">
                        <button type="button" id="check-payment-btn" class="btn btn-check-payment" onclick="checkPaymentStatus()">
                            <i class="fas fa-search me-2"></i>Check Payment Now
                        </button>
                        <button type="button" id="restart-auto-detection-btn" class="btn btn-auto-detect" onclick="restartAutoDetection()" style="display: none;">
                            <i class="fas fa-play me-2"></i>Restart Auto-Detection
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="showManualEntry()">
                            <i class="fas fa-keyboard me-2"></i>Enter Code Manually
                        </button>
                    </div>
                </div>

                <!-- Manual Entry Form (Initially Hidden) -->
                <div id="manual-entry-form" style="display: none;">
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Manual Entry:</strong> Use this only if automatic detection fails.
                    </div>

                    <form method="post" id="offlineForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
                        <input type="hidden" name="submit_offline_payment" value="1" />
                        
                        <div class="mb-3">
                            <label for="mpesa_code" class="form-label">
                                <i class="fas fa-barcode me-2"></i><strong>M-pesa Transaction Code</strong>
                            </label>
                            <input type="text" name="mpesa_code" id="mpesa_code" class="form-control" 
                                   required placeholder="e.g., NLJ7RT61SV" style="text-transform: uppercase;" />
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Enter the M-pesa confirmation code you received via SMS
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="payment_phone" class="form-label">
                                <i class="fas fa-phone me-2"></i>Phone Number Used for Payment <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="text" name="payment_phone" id="payment_phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($clientPhone); ?>" 
                                   placeholder="e.g., 0712345678 (only if payment can't be auto-verified)" />
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Only required if automatic verification fails
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="payment_amount" class="form-label">
                                <i class="fas fa-money-bill me-2"></i>Amount Paid <span class="text-muted">(Optional)</span>
                            </label>
                            <input type="number" name="payment_amount" id="payment_amount" class="form-control" 
                                   value="<?php echo $invoice->total; ?>" step="0.01" min="0.01" 
                                   placeholder="Only if automatic verification fails" />
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Only required if automatic verification fails
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-offline">
                            <i class="fas fa-search me-2"></i>Verify Payment Manually
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" onclick="hideManualEntry()">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                    </form>
                </div>
                
                <div class="alert alert-success mt-4" style="display: none;" id="payment-found-alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Payment Found!</strong> Your payment has been detected and is being processed.
                </div>
                
                <div class="alert alert-info mt-4">
                    <i class="fas fa-magic me-2"></i>
                    <strong>How it works:</strong>
                    <ul class="mb-0 mt-2">
                        <li><strong>Automatic Detection:</strong> Starts automatically and checks every 30 seconds for up to 3 minutes</li>
                        <li><strong>Manual Check:</strong> Click "Check Payment Now" for an instant check anytime</li>
                        <li><strong>Manual Entry:</strong> Enter transaction code if automatic methods fail</li>
                        <li><strong>Smart Detection:</strong> Works even if you switch between tabs or refresh the page</li>
                    </ul>
                </div>
            </div>
            
            <div class="security-note">
                <i class="fas fa-shield-alt me-2"></i>
                <strong>Security Notice:</strong> Your payment is processed securely through Safaricom M-pesa. 
                Never share your M-pesa PIN with anyone.
            </div>
            <?php else: ?>
            <div class="alert alert-success text-center">
                <i class="fas fa-check-circle fa-3x mb-3"></i>
                <h4>Payment Completed!</h4>
                <p>This invoice has been paid successfully.</p>
                <?php if ($invoiceReturnUrl && !$paymentComplete): ?>
                <a href="<?php echo htmlspecialchars($invoiceReturnUrl); ?>" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Return to Invoice
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Payment method switching
        function switchPaymentMethod(method) {
            // Remove active class from all tabs and contents
            document.querySelectorAll('.payment-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.payment-method-content').forEach(content => content.classList.remove('active'));
            
            // Add active class to selected tab and content
            event.target.classList.add('active');
            document.getElementById(method + '-method').classList.add('active');
            
            // Auto-start detection when switching to offline method
            if (method === 'offline') {
                setTimeout(function() {
                    startAutoDetection();
                }, 1000); // Small delay to ensure UI is ready
            } else if (method === 'stkpush') {
                // Stop auto-detection when switching away from offline
                stopAutoDetection();
            }
        }

        // Auto-format phone numbers as user types
        function formatPhoneInput(inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/[^0-9]/g, '');
                    if (value.length > 10) {
                        value = value.substring(0, 10);
                    }
                    e.target.value = value;
                });
            }
        }

        // Apply phone formatting to both phone inputs
        formatPhoneInput('mpesa_phone');
        formatPhoneInput('payment_phone');

        // Auto-format M-pesa code to uppercase
        const mpesaCodeInput = document.getElementById('mpesa_code');
        if (mpesaCodeInput) {
            mpesaCodeInput.addEventListener('input', function(e) {
                e.target.value = e.target.value.toUpperCase();
            });
        }

        // STK Push form validation and loading state
        const mpesaForm = document.getElementById('mpesaForm');
        if (mpesaForm) {
            mpesaForm.addEventListener('submit', function(e) {
                const phone = document.getElementById('mpesa_phone').value;
                const phoneRegex = /^(0[17][0-9]{8}|254[17][0-9]{8})$/;
                
                if (!phoneRegex.test(phone.replace(/[^0-9]/g, ''))) {
                    e.preventDefault();
                    alert('Please enter a valid M-pesa phone number (07XXXXXXXX, 01XXXXXXXX, or 254XXXXXXXXX)');
                    return false;
                }
                
                // Mark that user has started a payment attempt
                sessionStorage.setItem('mpesa_payment_started', 'true');
                sessionStorage.setItem('mpesa_payment_time', Date.now());
                
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing Payment...';
                submitButton.disabled = true;
                
                // Re-enable button after 10 seconds as fallback
                setTimeout(function() {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                }, 10000);
            });
        }

        // Offline payment form validation
        const offlineForm = document.getElementById('offlineForm');
        if (offlineForm) {
            offlineForm.addEventListener('submit', function(e) {
                const mpesaCode = document.getElementById('mpesa_code').value.trim();
                const phone = document.getElementById('payment_phone').value.trim();
                const amount = parseFloat(document.getElementById('payment_amount').value);
                
                // Validate M-pesa code format (usually 10 characters alphanumeric)
                if (mpesaCode.length < 8) {
                    e.preventDefault();
                    alert('Please enter a valid M-pesa transaction code (at least 8 characters)');
                    return false;
                }
                
                // Validate phone number only if provided
                if (phone && phone.length > 0) {
                    const phoneRegex = /^(0[17][0-9]{8}|254[17][0-9]{8})$/;
                    if (!phoneRegex.test(phone.replace(/[^0-9]/g, ''))) {
                        e.preventDefault();
                        alert('Please enter a valid phone number format');
                        return false;
                    }
                }
                
                // Validate amount only if provided
                if (amount && amount <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid payment amount');
                    return false;
                }
                
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verifying...';
                submitButton.disabled = true;
                
                // Re-enable button after 10 seconds as fallback
                setTimeout(function() {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                }, 10000);
            });
        }
        
        // Waiting mode functionality
        let waitingMode = <?php echo $waitingMode ? 'true' : 'false'; ?>;
        let checkoutRequestId = '<?php echo $checkoutRequestId; ?>';
        let invoiceId = <?php echo $invoiceId; ?>;
        let transactionTimeout = <?php echo $transactionTimeout; ?>;
        let systemUrl = '<?php echo $systemUrl; ?>';
        let returnUrl = '<?php echo $returnUrl; ?>';
        let invoiceReturnUrl = '<?php echo $invoiceReturnUrl; ?>';
        let successReturnUrl = '<?php echo $successReturnUrl; ?>';
        let isTokenAccess = <?php echo $isTokenAccess ? 'true' : 'false'; ?>;
        
        if (waitingMode && checkoutRequestId) {
            document.addEventListener('DOMContentLoaded', function() {
                startWaitingMode();
            });
        }
        
        function startWaitingMode() {
            // Hide payment forms
            document.querySelectorAll('.payment-method-tabs, .payment-method-content').forEach(el => {
                el.style.display = 'none';
            });
            
            // Show waiting container
            document.getElementById('waiting-mode-container').classList.remove('hidden');
            
            let timeRemaining = transactionTimeout;
            let countdownTimer;
            let statusChecker;
            
            // Start countdown
            function startCountdown() {
                countdownTimer = setInterval(function() {
                    timeRemaining--;
                    document.getElementById('countdown').textContent = timeRemaining;
                    
                    if (timeRemaining <= 0) {
                        clearInterval(countdownTimer);
                        clearInterval(statusChecker);
                        handleTimeout();
                    }
                }, 1000);
            }
            
            // Check payment status
            function startStatusCheck() {
                statusChecker = setInterval(function() {
                    fetch(systemUrl + '/check-status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'checkout_request_id=' + encodeURIComponent(checkoutRequestId) + 
                              '&invoice_id=' + encodeURIComponent(invoiceId)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'completed') {
                            clearInterval(countdownTimer);
                            clearInterval(statusChecker);
                            showSuccess();
                        } else if (data.status === 'failed') {
                            clearInterval(countdownTimer);
                            clearInterval(statusChecker);
                            handlePaymentError(data);
                        }
                    })
                    .catch(error => {
                        console.log('Status check error:', error);
                    });
                }, 3000);
            }
            
            function handleTimeout() {
                showError('1019', 'Transaction Expired', 'The payment request has expired.', {
                    canRetry: true,
                    canChangePhone: true,
                    suggestion: 'Please try again.'
                });
            }
            
            function handlePaymentError(response) {
                let errorCode = response.error_code || 'UNKNOWN';
                let errorInfo = getErrorInfo(errorCode, response.message || 'Payment failed');
                showError(errorCode, errorInfo.title, errorInfo.description, errorInfo.actions);
            }
            
            function getErrorInfo(errorCode, originalMessage) {
                const errorMap = {
                    '1037': {
                        title: 'Unable to Reach Your Phone',
                        description: 'The M-PESA request could not reach your phone.',
                        actions: { canRetry: true, canChangePhone: true, suggestion: 'Please ensure your phone is online and try again.' }
                    },
                    '1032': {
                        title: 'Payment Cancelled',
                        description: 'The payment request was cancelled.',
                        actions: { canRetry: true, canChangePhone: true, suggestion: 'You can try again.' }
                    },
                    '1': {
                        title: 'Insufficient Balance',
                        description: 'Your M-PESA account does not have sufficient funds.',
                        actions: { canRetry: true, canChangePhone: true, suggestion: 'Please top up your M-PESA account.' }
                    }
                };
                
                return errorMap[errorCode] || {
                    title: 'Payment Failed',
                    description: originalMessage,
                    actions: { canRetry: true, canChangePhone: true, suggestion: 'Please try again.' }
                };
            }
            
            function showError(errorCode, title, description, actions) {
                document.getElementById('processing-container').classList.add('hidden');
                document.getElementById('error-code').textContent = 'Error Code: ' + errorCode;
                document.getElementById('error-message').textContent = title;
                document.getElementById('error-description').textContent = description;
                
                let actionHtml = '<p><strong>What would you like to do?</strong></p>';
                actionHtml += '<div style="margin-bottom: 15px;">' + actions.suggestion + '</div>';
                actionHtml += '<button class="btn btn-primary" onclick="location.reload()"><i class="fas fa-redo"></i> Try Again</button>';
                
                // Use appropriate return URL based on context
                if (invoiceReturnUrl) {
                    actionHtml += '<a href="' + invoiceReturnUrl + '" class="btn btn-secondary ms-2"><i class="fas fa-arrow-left"></i> Return to Invoice</a>';
                }
                
                document.getElementById('error-actions').innerHTML = actionHtml;
                document.getElementById('error-container').classList.remove('hidden');
            }
            
            function showSuccess() {
                document.getElementById('processing-container').classList.add('hidden');
                document.getElementById('success-container').classList.remove('hidden');
                
                // Auto-redirect after successful payment (longer delay for better UX)
                setTimeout(function() {
                    if (successReturnUrl) {
                        window.location.href = successReturnUrl;
                    } else if (invoiceReturnUrl) {
                        window.location.href = invoiceReturnUrl;
                    } else {
                        location.reload();
                    }
                }, 5000); // Increased from 2000 to 5000 milliseconds (5 seconds)
            }
            
            startCountdown();
            startStatusCheck();
        }

        // Add entrance animation
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.payment-container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            container.style.transition = 'all 0.5s ease';
            
            setTimeout(function() {
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        });

        // Offline Payment Auto-Detection Functions
        let autoDetectionTimer = null;
        let autoDetectionInterval = null;
        let detectionTimeRemaining = 180; // 3 minutes

        // Check payment status manually
        function checkPaymentStatus() {
            const button = document.getElementById('check-payment-btn');
            const originalText = button.innerHTML;
            
            // Show loading state
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Checking...';
            button.disabled = true;
            
            // Make request to check for payments using JSON API
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>') + 
                      '&check_payment_status=1'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Payment check result:', data); // Debug log
                
                if (data.status === 'paid') {
                    // Payment found - show success and redirect
                    document.getElementById('payment-found-alert').style.display = 'block';
                    document.getElementById('payment-found-alert').innerHTML = 
                        '<i class="fas fa-check-circle me-2"></i><strong>Payment Found!</strong> ' + data.message;
                    
                    setTimeout(function() {
                        window.location.href = data.redirect_url || successReturnUrl;
                    }, 2000);
                } else if (data.status === 'pending') {
                    // Payment found but needs manual processing
                    button.innerHTML = '<i class="fas fa-clock me-2"></i>Payment Pending';
                    setTimeout(function() {
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }, 3000);
                } else if (data.status === 'not_found') {
                    // No payment found yet
                    button.innerHTML = '<i class="fas fa-info-circle me-2"></i>No Payment Found Yet';
                    setTimeout(function() {
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }, 3000);
                } else {
                    // Error occurred
                    button.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Check Failed';
                    setTimeout(function() {
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Payment check error:', error);
                button.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Check Failed';
                setTimeout(function() {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 3000);
            });
        }

        // Start automatic payment detection
        function startAutoDetection() {
            // Show auto-detection container
            document.getElementById('auto-detection-container').style.display = 'block';
            
            // Hide the restart button and show it's active
            const restartBtn = document.getElementById('restart-auto-detection-btn');
            if (restartBtn) {
                restartBtn.style.display = 'none';
            }
            
            detectionTimeRemaining = 180; // Reset to 3 minutes
            
            // Update timer display
            function updateTimer() {
                document.getElementById('detection-timer').textContent = detectionTimeRemaining;
                detectionTimeRemaining--;
                
                if (detectionTimeRemaining < 0) {
                    stopAutoDetection();
                    return;
                }
            }
            
            // Start timer countdown
            autoDetectionTimer = setInterval(updateTimer, 1000);
            
            // Start checking for payment every 30 seconds using JSON API
            autoDetectionInterval = setInterval(function() {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>') + 
                          '&check_payment_status=1'
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Auto-detection check result:', data); // Debug log
                    
                    if (data.status === 'paid') {
                        // Payment found!
                        stopAutoDetection();
                        document.getElementById('payment-found-alert').style.display = 'block';
                        document.getElementById('payment-found-alert').innerHTML = 
                            '<i class="fas fa-check-circle me-2"></i><strong>Payment Found!</strong> ' + data.message;
                        
                        setTimeout(function() {
                            window.location.href = data.redirect_url || successReturnUrl;
                        }, 2000);
                    }
                    // For 'pending', 'not_found', or 'error' status, continue checking
                })
                .catch(error => {
                    console.error('Auto-detection error:', error);
                    // Continue checking even on error
                });
            }, 30000); // Check every 30 seconds
            
            // Initial check after 5 seconds
            setTimeout(function() {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token']; ?>') + 
                          '&check_payment_status=1'
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Initial auto-detection check result:', data); // Debug log
                    
                    if (data.status === 'paid') {
                        stopAutoDetection();
                        document.getElementById('payment-found-alert').style.display = 'block';
                        document.getElementById('payment-found-alert').innerHTML = 
                            '<i class="fas fa-check-circle me-2"></i><strong>Payment Found!</strong> ' + data.message;
                        
                        setTimeout(function() {
                            window.location.href = data.redirect_url || successReturnUrl;
                        }, 2000);
                    }
                })
                .catch(error => {
                    console.error('Initial check error:', error);
                });
            }, 5000);
        }

        // Stop automatic detection
        function stopAutoDetection() {
            if (autoDetectionTimer) {
                clearInterval(autoDetectionTimer);
                autoDetectionTimer = null;
            }
            if (autoDetectionInterval) {
                clearInterval(autoDetectionInterval);
                autoDetectionInterval = null;
            }
            
            document.getElementById('auto-detection-container').style.display = 'none';
            
            // Show restart button
            const restartBtn = document.getElementById('restart-auto-detection-btn');
            if (restartBtn) {
                restartBtn.style.display = 'inline-block';
            }
        }
        
        // Restart auto-detection (user manually requested)
        function restartAutoDetection() {
            console.log('Restarting auto-detection by user request');
            startAutoDetection();
        }

        // Show manual entry form
        function showManualEntry() {
            document.getElementById('manual-entry-form').style.display = 'block';
            document.getElementById('mpesa_code').focus();
            
            // Scroll to form
            document.getElementById('manual-entry-form').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center' 
            });
        }

        // Hide manual entry form
        function hideManualEntry() {
            document.getElementById('manual-entry-form').style.display = 'none';
        }

        // Auto-start detection if user just switched to offline method
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we should auto-start detection (e.g., if coming from a payment attempt)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_detect') === '1') {
                // Switch to offline tab and start detection
                switchPaymentMethod('offline');
                setTimeout(startAutoDetection, 1000);
            } else {
                // Check if offline method is already active (default or user preference)
                const offlineTab = document.querySelector('.payment-tab[onclick*="offline"]');
                const offlineContent = document.getElementById('offline-method');
                
                if (offlineContent && offlineContent.classList.contains('active')) {
                    // Offline tab is active, auto-start detection
                    setTimeout(function() {
                        console.log('Auto-starting detection - offline tab is active');
                        startAutoDetection();
                    }, 2000); // 2 second delay to let user see the interface first
                }
            }
            
            // Auto-start if user has been waiting and switches tabs
            let hasStartedPayment = sessionStorage.getItem('mpesa_payment_started');
            if (hasStartedPayment) {
                setTimeout(function() {
                    const offlineContent = document.getElementById('offline-method');
                    if (offlineContent && offlineContent.classList.contains('active')) {
                        console.log('Auto-starting detection - user has previous payment activity');
                        startAutoDetection();
                    }
                }, 1500);
            }
        });

    </script>
</body>
</html> 