<?php
/**
 * WHMCS M-pesa Status Check Handler
 *
 * This file handles checking the status of an M-pesa payment.
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Include the WHMCS core
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';

use WHMCS\Database\Capsule;

// Get gateway variables
$gatewayModuleName = 'woza';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active
if (!$gatewayParams['type']) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Module not activated']);
    exit;
}

// Get POST parameters
$checkoutRequestId = isset($_POST['checkout_request_id']) ? $_POST['checkout_request_id'] : '';
$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;

// Validate parameters
if (empty($checkoutRequestId) || empty($invoiceId)) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameters'
    ]);
    exit;
}

// Security validation - verify client authorization
$currentClientId = null;
if (isset($_SESSION['uid']) && !empty($_SESSION['uid'])) {
    $currentClientId = (int)$_SESSION['uid'];
} elseif (isset($_SESSION['adminid']) && !empty($_SESSION['adminid'])) {
    // Allow admin access - we'll verify later against invoice
    $currentClientId = -1; // Special value for admin
}

if ($currentClientId === null) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Authentication required'
    ]);
    exit;
}

// Verify client owns the invoice (unless admin)
if ($currentClientId !== -1) {
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first();
    
    if (!$invoice || $invoice->userid !== $currentClientId) {
        // Log unauthorized access attempt
        logActivity("Unauthorized payment status check attempt for Invoice #$invoiceId from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Access denied. You can only check your own invoices.'
        ]);
        exit;
    }
}

// Get transaction from database
$transaction = Capsule::table('mod_mpesa_transactions')
    ->where('checkout_request_id', $checkoutRequestId)
    ->where('invoice_id', $invoiceId)
    ->first();

if (!$transaction) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Transaction not found'
    ]);
    exit;
}

// Check invoice status - if already paid, report success
$invoice = Capsule::table('tblinvoices')
    ->where('id', $invoiceId)
    ->first();

if ($invoice && $invoice->status == 'Paid') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'completed',
        'message' => 'Payment completed successfully'
    ]);
    exit;
}

// Check transaction status
if ($transaction->transaction_status == 'completed') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'completed',
        'message' => 'Payment completed successfully'
    ]);
    exit;
} else if ($transaction->transaction_status == 'failed') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'failed',
        'message' => $transaction->response_description ?: 'Payment failed',
        'error_code' => extractErrorCodeFromDescription($transaction->response_description),
        'phone_number' => $transaction->phone_number
    ]);
    exit;
}

// If we have a recent transaction, we need to check via API
if (time() - strtotime($transaction->created_at) < 300) { // Within 5 minutes
    $queryStatus = checkTransactionStatus($gatewayParams, $checkoutRequestId, $invoiceId);
    if ($queryStatus['status'] != 'pending') {
        header('Content-Type: application/json');
        echo json_encode($queryStatus);
        exit;
    }
}

// Default response for pending transactions
header('Content-Type: application/json');
echo json_encode([
    'status' => 'pending',
    'message' => 'Payment is still processing'
]);
exit;

/**
 * Extract error code from response description.
 *
 * @param string $description The response description
 * @return string The extracted error code or 'UNKNOWN'
 */
function extractErrorCodeFromDescription($description)
{
    if (empty($description)) {
        return 'UNKNOWN';
    }
    
    // Try to extract numeric error code
    if (preg_match('/(?:Code:|Error)\s*(\d+)/i', $description, $matches)) {
        return $matches[1];
    }
    
    // Check for specific error patterns
    $description = strtolower($description);
    if (strpos($description, 'canceled') !== false || strpos($description, 'cancelled') !== false) {
        return '1032';
    } elseif (strpos($description, 'insufficient') !== false) {
        return '1';
    } elseif (strpos($description, 'timeout') !== false || strpos($description, 'cannot be reached') !== false) {
        return '1037';
    } elseif (strpos($description, 'invalid pin') !== false) {
        return '2001';
    } elseif (strpos($description, 'expired') !== false) {
        return '1019';
    } elseif (strpos($description, 'transaction is already in progress') !== false) {
        return '1001';
    } elseif (strpos($description, 'system error') !== false) {
        return '1025';
    }
    
    return 'UNKNOWN';
}

/**
 * Check the status of a transaction with Safaricom API.
 *
 * @param array $params Gateway parameters
 * @param string $checkoutRequestId The checkout request ID
 * @param int $invoiceId The invoice ID
 * @return array Status information
 */
function checkTransactionStatus($params, $checkoutRequestId, $invoiceId)
{
    // Gateway Configuration Parameters
    $consumerKey = $params['consumerKey'];
    $consumerSecret = $params['consumerSecret'];
    $shortcode = $params['shortcode'];
    $passkey = $params['passkey'];
    $environment = $params['environment'];

    // Determine API endpoints based on environment
    if ($environment == 'production') {
        $baseUrl = 'https://api.safaricom.co.ke';
    } else {
        $baseUrl = 'https://sandbox.safaricom.co.ke';
    }

    // Authentication URL and query URL
    $authUrl = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $queryUrl = $baseUrl . '/mpesa/stkpushquery/v1/query';

    try {
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
            return [
                'status' => 'error',
                'message' => 'Connection error: ' . curl_error($ch)
            ];
        }
        curl_close($ch);
        
        $authResponse = json_decode($response, true);
        
        if (!isset($authResponse['access_token'])) {
            return [
                'status' => 'error',
                'message' => 'Authentication failed'
            ];
        }
        
        $accessToken = $authResponse['access_token'];
        
        // Prepare timestamp
        $timestamp = date('YmdHis');
        
        // Prepare password
        $password = base64_encode($shortcode . $passkey . $timestamp);
        
        // Prepare query request data
        $queryData = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId
        ];
        
        // Query transaction status
        $ch = curl_init($queryUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($queryData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        
        if (curl_error($ch)) {
            curl_close($ch);
            return [
                'status' => 'error',
                'message' => 'Query error: ' . curl_error($ch)
            ];
        }
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        
        // Check response
        if (!isset($responseData['ResponseCode'])) {
            return [
                'status' => 'error',
                'message' => 'Could not query transaction status'
            ];
        }
        
        // Check if successful
        if ($responseData['ResponseCode'] === '0') {
            // Payment successful
            // Update database
            Capsule::table('mod_mpesa_transactions')
                ->where('checkout_request_id', $checkoutRequestId)
                ->update([
                    'transaction_status' => 'completed',
                    'response_description' => $responseData['ResponseDescription'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            return [
                'status' => 'completed',
                'message' => 'Payment completed successfully'
            ];
        } else {
            // Payment failed or still pending
            $status = ($responseData['ResponseCode'] === '1032') ? 'pending' : 'failed';
            $message = isset($responseData['ResponseDescription']) ? $responseData['ResponseDescription'] : 'Transaction status unknown';
            
            if ($status === 'failed') {
                // Update database
                Capsule::table('mod_mpesa_transactions')
                    ->where('checkout_request_id', $checkoutRequestId)
                    ->update([
                        'transaction_status' => 'failed',
                        'response_description' => $message,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'error_code' => extractErrorCodeFromDescription($message)
            ];
        }
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Exception: ' . $e->getMessage()
        ];
    }
} 