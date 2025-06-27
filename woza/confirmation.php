<?php
/**
 * WHMCS M-pesa Confirmation Handler
 *
 * This file handles C2B payment confirmations from Safaricom M-pesa API.
 * It logs raw JSON data and saves to database for offline payment verification.
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Include WHMCS core for database operations
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/gatewayfunctions.php';
require_once __DIR__ . '/../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

// Get confirmation data
$confirmationData = file_get_contents('php://input');
$confirmationArray = json_decode($confirmationData, true);

// Use absolute path for logs directory
$logDir = '/var/www/html/modules/gateways/woza/logs';

// Create logs directory if it doesn't exist
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

// If primary log directory fails, use alternative
if (!is_dir($logDir) || !is_writable($logDir)) {
    $logDir = '/var/www/html/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
}

// Log file for confirmations
$confirmLogFile = $logDir . '/confirm.txt';

// Log the raw JSON data with timestamp
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'raw_json' => $confirmationData
];

$logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 80) . "\n";

// Try to write to log file with error suppression
@file_put_contents($confirmLogFile, $logLine, FILE_APPEND | LOCK_EX);

// Send JSON response to Safaricom first (important for callback success)
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Confirmation received successfully'
]);

// Process the confirmation data if valid
if ($confirmationArray && is_array($confirmationArray)) {
    try {
        // Create table for storing C2B confirmations if it doesn't exist
        createC2BConfirmationsTable();
        
        // Extract confirmation data
        $transactionType = $confirmationArray['TransactionType'] ?? '';
        $transID = $confirmationArray['TransID'] ?? '';
        $transTime = $confirmationArray['TransTime'] ?? '';
        $transAmount = (float)($confirmationArray['TransAmount'] ?? 0);
        $businessShortCode = $confirmationArray['BusinessShortCode'] ?? '';
        $billRefNumber = $confirmationArray['BillRefNumber'] ?? '';
        $invoiceNumber = $confirmationArray['InvoiceNumber'] ?? '';
        $orgAccountBalance = (float)($confirmationArray['OrgAccountBalance'] ?? 0);
        $thirdPartyTransID = $confirmationArray['ThirdPartyTransID'] ?? '';
        $msisdn = $confirmationArray['MSISDN'] ?? '';
        $firstName = $confirmationArray['FirstName'] ?? '';
        
        // Parse transaction time to MySQL format
        $transactionDate = null;
        if ($transTime && strlen($transTime) === 14) {
            // Format: 20250627062325 -> 2025-06-27 06:23:25
            $transactionDate = substr($transTime, 0, 4) . '-' . 
                             substr($transTime, 4, 2) . '-' . 
                             substr($transTime, 6, 2) . ' ' . 
                             substr($transTime, 8, 2) . ':' . 
                             substr($transTime, 10, 2) . ':' . 
                             substr($transTime, 12, 2);
        }
        
        // Check if this confirmation already exists
        $existingConfirmation = Capsule::table('mod_mpesa_c2b_confirmations')
            ->where('trans_id', $transID)
            ->first();
        
        if (!$existingConfirmation) {
            // Insert new confirmation record
            $confirmationId = Capsule::table('mod_mpesa_c2b_confirmations')->insertGetId([
                'transaction_type' => $transactionType,
                'trans_id' => $transID,
                'trans_time' => $transTime,
                'transaction_date' => $transactionDate,
                'trans_amount' => $transAmount,
                'business_short_code' => $businessShortCode,
                'bill_ref_number' => $billRefNumber,
                'invoice_number' => $invoiceNumber,
                'org_account_balance' => $orgAccountBalance,
                'third_party_trans_id' => $thirdPartyTransID,
                'msisdn' => $msisdn,
                'first_name' => $firstName,
                'raw_data' => $confirmationData,
                'processed' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // Try to auto-process if we can identify the invoice
            if ($billRefNumber) {
                processOfflinePayment($confirmationId, $billRefNumber, $transID, $transAmount);
            }
            
            // Log successful save
            logTransaction('woza', [
                'confirmation_id' => $confirmationId,
                'trans_id' => $transID,
                'amount' => $transAmount,
                'bill_ref' => $billRefNumber,
                'status' => 'saved_to_database'
            ], 'C2B Confirmation Saved to Database');
            
        } else {
            // Log duplicate
            logTransaction('woza', [
                'trans_id' => $transID,
                'status' => 'duplicate_confirmation'
            ], 'C2B Confirmation Duplicate Detected');
        }
        
    } catch (Exception $e) {
        // Log any errors
        logTransaction('woza', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'raw_data' => $confirmationData
        ], 'C2B Confirmation Processing Error');
    }
}

exit;

/**
 * Create the C2B confirmations table if it doesn't exist
 */
function createC2BConfirmationsTable() {
    try {
        $pdo = Capsule::connection()->getPdo();
        $createTable = "CREATE TABLE IF NOT EXISTS `mod_mpesa_c2b_confirmations` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `transaction_type` varchar(50) DEFAULT NULL,
            `trans_id` varchar(50) NOT NULL,
            `trans_time` varchar(20) DEFAULT NULL,
            `transaction_date` datetime DEFAULT NULL,
            `trans_amount` decimal(10,2) NOT NULL,
            `business_short_code` varchar(20) DEFAULT NULL,
            `bill_ref_number` varchar(100) DEFAULT NULL,
            `invoice_number` varchar(100) DEFAULT NULL,
            `org_account_balance` decimal(15,2) DEFAULT NULL,
            `third_party_trans_id` varchar(100) DEFAULT NULL,
            `msisdn` varchar(255) DEFAULT NULL,
            `first_name` varchar(100) DEFAULT NULL,
            `raw_data` text DEFAULT NULL,
            `processed` tinyint(1) DEFAULT 0,
            `invoice_id` int(11) DEFAULT NULL,
            `payment_added` tinyint(1) DEFAULT 0,
            `notes` text DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `trans_id` (`trans_id`),
            KEY `bill_ref_number` (`bill_ref_number`),
            KEY `processed` (`processed`),
            KEY `transaction_date` (`transaction_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $pdo->exec($createTable);
    } catch (Exception $e) {
        // Continue if table creation fails
    }
}

/**
 * Process offline payment automatically if possible
 */
function processOfflinePayment($confirmationId, $billRefNumber, $transID, $amount) {
    try {
        // Try to extract invoice ID from bill reference
        $invoiceId = null;
        
        // Check if bill ref is just a number (invoice ID)
        if (is_numeric($billRefNumber)) {
            $invoiceId = (int)$billRefNumber;
        }
        // Check for patterns like INV123, INVOICE123, etc.
        elseif (preg_match('/(?:inv|invoice)(\d+)/i', $billRefNumber, $matches)) {
            $invoiceId = (int)$matches[1];
        }
        
        if ($invoiceId) {
            // Verify invoice exists
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            
            if ($invoice) {
                // Check if payment already exists
                $existingPayment = Capsule::table('tblaccounts')
                    ->where('invoiceid', $invoiceId)
                    ->where('transid', $transID)
                    ->first();
                
                if (!$existingPayment) {
                    // Add payment to WHMCS
                    $success = addInvoicePayment(
                        $invoiceId,
                        $transID,
                        $amount,
                        0, // No fees
                        'woza'
                    );
                    
                    if ($success) {
                        // Update confirmation record
                        Capsule::table('mod_mpesa_c2b_confirmations')
                            ->where('id', $confirmationId)
                            ->update([
                                'processed' => 1,
                                'invoice_id' => $invoiceId,
                                'payment_added' => 1,
                                'notes' => 'Auto-processed successfully',
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                        
                        logTransaction('woza', [
                            'confirmation_id' => $confirmationId,
                            'invoice_id' => $invoiceId,
                            'trans_id' => $transID,
                            'amount' => $amount,
                            'status' => 'auto_processed'
                        ], 'C2B Payment Auto-Processed Successfully');
                    }
                } else {
                    // Mark as duplicate
                    Capsule::table('mod_mpesa_c2b_confirmations')
                        ->where('id', $confirmationId)
                        ->update([
                            'processed' => 1,
                            'invoice_id' => $invoiceId,
                            'payment_added' => 0,
                            'notes' => 'Duplicate payment - already exists',
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                }
            } else {
                // Invoice not found
                Capsule::table('mod_mpesa_c2b_confirmations')
                    ->where('id', $confirmationId)
                    ->update([
                        'notes' => "Invoice #$invoiceId not found",
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
        } else {
            // Could not extract invoice ID
            Capsule::table('mod_mpesa_c2b_confirmations')
                ->where('id', $confirmationId)
                ->update([
                    'notes' => "Could not extract invoice ID from reference: $billRefNumber",
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
        }
        
    } catch (Exception $e) {
        // Log processing error
        Capsule::table('mod_mpesa_c2b_confirmations')
            ->where('id', $confirmationId)
            ->update([
                'notes' => 'Processing error: ' . $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
    }
}

?>
 