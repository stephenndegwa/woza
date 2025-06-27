<?php
/**
 * WHMCS M-pesa Payment Callback Handler
 *
 * This file handles payment confirmations from Safaricom M-pesa API.
 * Enhanced with comprehensive debugging and robust payment processing.
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Include the WHMCS core
require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Get callback data
$callbackData = file_get_contents('php://input');
$callbackArray = json_decode($callbackData, true);

// Log every callback to callback.log file
$logFile = __DIR__ . '/../logs/callback.log';
$logDir = dirname($logFile);

// Create logs directory if it doesn't exist
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Prepare log entry
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'Unknown',
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0,
    'raw_data' => $callbackData,
    'parsed_data' => $callbackArray,
    'headers' => getallheaders() ?: []
];

// Write to log file
$logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 80) . "\n";
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// Enhanced logging with timestamp
$logPrefix = '[' . date('Y-m-d H:i:s') . '] M-PESA Callback: ';
logTransaction('woza', $callbackArray, $logPrefix . 'Callback Received');

// Validate callback data
if (!$callbackData || !$callbackArray) {
    logTransaction('woza', ['error' => 'No callback data'], $logPrefix . 'Invalid callback data received');
    
    // Log invalid callback
    $finalLogEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'INVALID_CALLBACK_DATA',
        'error' => 'No callback data received or invalid JSON',
        'raw_data' => $callbackData
    ];
    file_put_contents($logFile, "FINAL RESULT: " . json_encode($finalLogEntry) . "\n" . str_repeat('=', 80) . "\n", FILE_APPEND | LOCK_EX);
    
    http_response_code(400);
    exit('Invalid callback data');
}

// Extract callback information
$stkCallback = isset($callbackArray['Body']['stkCallback']) ? $callbackArray['Body']['stkCallback'] : null;

if (!$stkCallback) {
    logTransaction('woza', $callbackArray, $logPrefix . 'Invalid STK callback format - missing stkCallback');
    http_response_code(400);
    exit('Invalid STK callback format');
}

$merchantRequestID = isset($stkCallback['MerchantRequestID']) ? $stkCallback['MerchantRequestID'] : '';
$checkoutRequestID = isset($stkCallback['CheckoutRequestID']) ? $stkCallback['CheckoutRequestID'] : '';
$resultCode = isset($stkCallback['ResultCode']) ? (int)$stkCallback['ResultCode'] : -1;
$resultDesc = isset($stkCallback['ResultDesc']) ? $stkCallback['ResultDesc'] : '';

// Log extracted data
logTransaction('woza', [
    'MerchantRequestID' => $merchantRequestID,
    'CheckoutRequestID' => $checkoutRequestID,
    'ResultCode' => $resultCode,
    'ResultDesc' => $resultDesc
], $logPrefix . 'Extracted callback data');

// Validate required fields
if (empty($checkoutRequestID)) {
    logTransaction('woza', $callbackArray, $logPrefix . 'Missing CheckoutRequestID');
    http_response_code(400);
    exit('Missing CheckoutRequestID');
}

// Initialize variables
$mpesaReceiptNumber = '';
$amount = 0;
$phoneNumber = '';
$transactionDate = '';
$balance = 0;

// Process successful payment
if ($resultCode === 0) {
    logTransaction('woza', [], $logPrefix . 'Processing successful payment (ResultCode = 0)');
    // Payment was successful, extract callback metadata
    if (isset($stkCallback['CallbackMetadata']['Item']) && is_array($stkCallback['CallbackMetadata']['Item'])) {
        logTransaction('woza', $stkCallback['CallbackMetadata']['Item'], $logPrefix . 'Extracting callback metadata');
        
        foreach ($stkCallback['CallbackMetadata']['Item'] as $item) {
            if (isset($item['Name']) && isset($item['Value'])) {
                switch ($item['Name']) {
                    case 'Amount':
                        $amount = (float)$item['Value'];
                        break;
                    case 'MpesaReceiptNumber':
                        $mpesaReceiptNumber = (string)$item['Value'];
                        break;
                    case 'PhoneNumber':
                        $phoneNumber = (string)$item['Value'];
                        break;
                    case 'TransactionDate':
                        $transactionDate = (string)$item['Value'];
                        break;
                    case 'Balance':
                        $balance = (float)$item['Value'];
                        break;
                }
            }
        }
        
        // Log extracted metadata
        logTransaction('woza', [
            'Amount' => $amount,
            'MpesaReceiptNumber' => $mpesaReceiptNumber,
            'PhoneNumber' => $phoneNumber,
            'TransactionDate' => $transactionDate,
            'Balance' => $balance
        ], $logPrefix . 'Extracted metadata values');
        
    } else {
        logTransaction('woza', $stkCallback, $logPrefix . 'No CallbackMetadata found or invalid format');
        http_response_code(400);
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Invalid callback metadata'
        ]);
        exit;
    }
    
    // Validate required metadata
    if (empty($mpesaReceiptNumber) || $amount <= 0) {
        logTransaction('woza', [
            'mpesaReceiptNumber' => $mpesaReceiptNumber,
            'amount' => $amount
        ], $logPrefix . 'Missing required metadata - receipt number or amount');
        http_response_code(400);
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Missing required payment data'
        ]);
        exit;
    }
    
    // Find the corresponding transaction in our database
    logTransaction('woza', ['CheckoutRequestID' => $checkoutRequestID], $logPrefix . 'Looking up transaction in database');
    
    $transaction = Capsule::table('mod_mpesa_transactions')
        ->where('checkout_request_id', $checkoutRequestID)
        ->first();
    
    if (!$transaction) {
        logTransaction('woza', ['CheckoutRequestID' => $checkoutRequestID], $logPrefix . 'Transaction not found in mod_mpesa_transactions table');
        http_response_code(404);
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Transaction not found'
        ]);
        exit;
    }
    
    $invoiceId = $transaction->invoice_id;
    logTransaction('woza', [
        'transaction_id' => $transaction->id,
        'invoice_id' => $invoiceId,
        'original_amount' => $transaction->amount,
        'callback_amount' => $amount
    ], $logPrefix . 'Found transaction record');
    
    // Verify the invoice exists
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        logTransaction('woza', ['invoice_id' => $invoiceId], $logPrefix . 'Invoice not found in tblinvoices table');
        http_response_code(404);
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Invoice not found'
        ]);
        exit;
    }
    
    logTransaction('woza', [
        'invoice_id' => $invoiceId,
        'invoice_status' => $invoice->status,
        'invoice_total' => $invoice->total,
        'payment_amount' => $amount
    ], $logPrefix . 'Invoice details');
    
    // Check if this transaction has already been processed
    $existingPayment = Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->where('transid', $mpesaReceiptNumber)
        ->first();
    
    if ($existingPayment) {
        logTransaction('woza', [
            'invoice_id' => $invoiceId,
            'mpesa_receipt' => $mpesaReceiptNumber,
            'existing_payment_id' => $existingPayment->id
        ], $logPrefix . 'Payment already processed - duplicate callback');
        
        http_response_code(200);
        echo json_encode([
            'ResultCode' => 0,
            'ResultDesc' => 'Payment already processed'
        ]);
        exit;
    }
    
    // Check gateway activation status
    try {
        $gatewayActive = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'woza')
            ->where('setting', 'type')
            ->where('value', '!=', '')
            ->exists();
        
        if (!$gatewayActive) {
            logTransaction('woza', [], $logPrefix . 'M-PESA gateway not activated');
            http_response_code(500);
            echo json_encode([
                'ResultCode' => 1,
                'ResultDesc' => 'Gateway not activated'
            ]);
            exit;
        }
        
        logTransaction('woza', [
            'gateway_active' => true
        ], $logPrefix . 'Gateway activation validated');
        
    } catch (Exception $e) {
        logTransaction('woza', [
            'error' => $e->getMessage()
        ], $logPrefix . 'Error checking gateway activation');
        // Continue processing - don't fail callback due to gateway check
    }
    
    // Add the payment to WHMCS
    logTransaction('woza', [
        'invoice_id' => $invoiceId,
        'transaction_id' => $mpesaReceiptNumber,
        'amount' => $amount,
        'gateway' => 'woza'
    ], $logPrefix . 'Attempting to add payment to WHMCS');
    
    try {
        $success = addInvoicePayment(
            $invoiceId,
            $mpesaReceiptNumber,
            $amount,
            0, // No fees
            'woza'
        );
        
        if ($success) {
            logTransaction('woza', [
                'invoice_id' => $invoiceId,
                'payment_added' => true,
                'amount' => $amount,
                'receipt' => $mpesaReceiptNumber
            ], $logPrefix . 'Payment successfully added to WHMCS');
            
            // Update our transaction record
            $updateResult = Capsule::table('mod_mpesa_transactions')
                ->where('checkout_request_id', $checkoutRequestID)
                ->update([
                    'mpesa_receipt' => $mpesaReceiptNumber,
                    'transaction_status' => 'completed',
                    'response_description' => $resultDesc,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            logTransaction('woza', [
                'update_result' => $updateResult,
                'checkout_request_id' => $checkoutRequestID
            ], $logPrefix . 'Transaction record updated');
            
            // Verify the invoice status after payment
            $updatedInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            logTransaction('woza', [
                'invoice_id' => $invoiceId,
                'old_status' => $invoice->status,
                'new_status' => $updatedInvoice->status,
                'total' => $updatedInvoice->total
            ], $logPrefix . 'Invoice status after payment');
            
            // Log successful payment processing
            $finalLogEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'SUCCESS',
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'mpesa_receipt' => $mpesaReceiptNumber,
                'checkout_request_id' => $checkoutRequestID,
                'message' => 'Payment processed successfully'
            ];
            file_put_contents($logFile, "FINAL RESULT: " . json_encode($finalLogEntry) . "\n" . str_repeat('=', 80) . "\n", FILE_APPEND | LOCK_EX);
            
            // Send success response to Safaricom
            http_response_code(200);
            echo json_encode([
                'ResultCode' => 0,
                'ResultDesc' => 'Payment processed successfully'
            ]);
            
        } else {
            // Failed to add payment to WHMCS
            logTransaction('woza', [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'receipt' => $mpesaReceiptNumber,
                'error' => 'addInvoicePayment returned false'
            ], $logPrefix . 'Failed to add payment to WHMCS');
            
            // Log payment failure
            $finalLogEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'PAYMENT_FAILED',
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'mpesa_receipt' => $mpesaReceiptNumber,
                'checkout_request_id' => $checkoutRequestID,
                'error' => 'addInvoicePayment returned false'
            ];
            file_put_contents($logFile, "FINAL RESULT: " . json_encode($finalLogEntry) . "\n" . str_repeat('=', 80) . "\n", FILE_APPEND | LOCK_EX);
            
            http_response_code(500);
            echo json_encode([
                'ResultCode' => 1,
                'ResultDesc' => 'Failed to process payment'
            ]);
        }
        
    } catch (Exception $e) {
        logTransaction('woza', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], $logPrefix . 'Exception while adding payment to WHMCS');
        
        // Log exception
        $finalLogEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'EXCEPTION',
            'invoice_id' => $invoiceId ?? 'Unknown',
            'amount' => $amount ?? 0,
            'mpesa_receipt' => $mpesaReceiptNumber ?? 'Unknown',
            'checkout_request_id' => $checkoutRequestID ?? 'Unknown',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
        file_put_contents($logFile, "FINAL RESULT: " . json_encode($finalLogEntry) . "\n" . str_repeat('=', 80) . "\n", FILE_APPEND | LOCK_EX);
        
        http_response_code(500);
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Payment processing error: ' . $e->getMessage()
        ]);
    }
} else {
    // Payment failed or was cancelled
    logTransaction('woza', [
        'ResultCode' => $resultCode,
        'ResultDesc' => $resultDesc
    ], $logPrefix . 'Processing failed payment');
    
    $transaction = Capsule::table('mod_mpesa_transactions')
        ->where('checkout_request_id', $checkoutRequestID)
        ->first();
    
    if ($transaction) {
        // Update transaction status to failed
        $updateResult = Capsule::table('mod_mpesa_transactions')
            ->where('checkout_request_id', $checkoutRequestID)
            ->update([
                'transaction_status' => 'failed',
                'response_description' => $resultDesc,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        
        logTransaction('woza', [
            'checkout_request_id' => $checkoutRequestID,
            'update_result' => $updateResult
        ], $logPrefix . 'Failed transaction record updated');
    }
    
    // Enhanced logging with error code categorization
    $errorCategory = getMpesaErrorCategory($resultCode);
    logTransaction('woza', $callbackArray, $logPrefix . "Payment Failed ($errorCategory) - Code: $resultCode, Description: $resultDesc");
    
    // Log failed/cancelled payment
    $finalLogEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'PAYMENT_CANCELLED_OR_FAILED',
        'result_code' => $resultCode,
        'result_desc' => $resultDesc,
        'error_category' => $errorCategory,
        'checkout_request_id' => $checkoutRequestID,
        'merchant_request_id' => $merchantRequestID
    ];
    file_put_contents($logFile, "FINAL RESULT: " . json_encode($finalLogEntry) . "\n" . str_repeat('=', 80) . "\n", FILE_APPEND | LOCK_EX);
    
    // Send acknowledgment to Safaricom
    http_response_code(200);
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Callback processed'
    ]);
}

/**
 * Get error category for better logging and analytics.
 * 
 * @param int $resultCode The result code from M-pesa callback
 * @return string Error category
 */
function getMpesaErrorCategory($resultCode)
{
    switch ($resultCode) {
        case 1037:
            return 'CONNECTIVITY_ISSUE';
        case 1025:
        case 9999:
            return 'SYSTEM_ERROR';
        case 1032:
            return 'USER_CANCELLED';
        case 1:
            return 'INSUFFICIENT_FUNDS';
        case 2001:
            return 'INVALID_PIN';
        case 1019:
            return 'TRANSACTION_EXPIRED';
        case 1001:
            return 'DUPLICATE_TRANSACTION';
        default:
            return 'OTHER_ERROR';
    }
}

exit; 