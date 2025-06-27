<?php
/**
 * WHMCS M-pesa Validation Handler
 *
 * This file handles validation requests from Safaricom M-pesa API.
 * It's called before a payment is processed to validate if it should be accepted.
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Include the WHMCS core
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/gatewayfunctions.php';

use WHMCS\Database\Capsule;

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Get validation data
$validationData = file_get_contents('php://input');
$validationArray = json_decode($validationData, true);

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/../modules/gateways/woza/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Log file for validations
$validationLogFile = $logDir . '/validation.txt';

// Prepare comprehensive log entry
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'Unknown',
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0,
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => getallheaders() ?: [],
    'raw_data' => $validationData,
    'parsed_data' => $validationArray,
    'get_params' => $_GET,
    'post_params' => $_POST
];

// Write to validation log file
$logLine = "=== SAFARICOM VALIDATION REQUEST ===\n";
$logLine .= json_encode($logEntry, JSON_PRETTY_PRINT);
$logLine .= "\n" . str_repeat('=', 80) . "\n\n";
file_put_contents($validationLogFile, $logLine, FILE_APPEND | LOCK_EX);

// Also log to WHMCS gateway log for admin visibility
logTransaction('woza', $logEntry, 'C2B Validation Request');

// Default response - accept all payments
$response = [
    'ResultCode' => 0,
    'ResultDesc' => 'Accepted'
];

// Validation logic
try {
    if ($validationArray && is_array($validationArray)) {
        // Log validation attempt
        $processLogEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'VALIDATION_ATTEMPT',
            'data_received' => $validationArray,
            'message' => 'Processing validation request'
        ];
        
        file_put_contents($validationLogFile, "PROCESSING: " . json_encode($processLogEntry, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 40) . "\n", FILE_APPEND | LOCK_EX);
        
        // Extract possible validation data
        $possibleAmount = $validationArray['TransAmount'] ?? 
                         $validationArray['Amount'] ?? 
                         $validationArray['amount'] ?? 
                         0;
        
        $possibleReference = $validationArray['BillRefNumber'] ?? 
                            $validationArray['AccountReference'] ?? 
                            $validationArray['account_reference'] ?? 
                            $validationArray['reference'] ?? 
                            '';
        
        $possiblePhone = $validationArray['MSISDN'] ?? 
                        $validationArray['PhoneNumber'] ?? 
                        $validationArray['phone'] ?? 
                        '';
        
        $possibleShortcode = $validationArray['BusinessShortCode'] ?? 
                            $validationArray['ShortCode'] ?? 
                            '';
        
        // Log extracted data
        $extractedData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'DATA_EXTRACTION',
            'extracted' => [
                'amount' => $possibleAmount,
                'reference' => $possibleReference,
                'phone' => $possiblePhone,
                'shortcode' => $possibleShortcode
            ],
            'message' => 'Extracted validation data'
        ];
        
        file_put_contents($validationLogFile, "EXTRACTED: " . json_encode($extractedData, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 40) . "\n", FILE_APPEND | LOCK_EX);
        
        // Validation rules - Only amount validation
        $validationResult = 'ACCEPTED';
        $validationMessage = 'Payment accepted';
        
        // Rule 1: Check minimum amount
        if ($possibleAmount < 1) {
            $validationResult = 'REJECTED';
            $validationMessage = 'Amount too low (minimum 1 KES)';
            $response = [
                'ResultCode' => 1,
                'ResultDesc' => $validationMessage
            ];
        }
        
        // Rule 2: Check maximum amount (optional)
        elseif ($possibleAmount > 500000) {
            $validationResult = 'REJECTED';
            $validationMessage = 'Amount too high (maximum 500,000 KES)';
            $response = [
                'ResultCode' => 1,
                'ResultDesc' => $validationMessage
            ];
        }
        
        // Accept all other payments (no invoice validation)
        
        // Log validation result
        $validationLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $validationResult,
            'amount' => $possibleAmount,
            'reference' => $possibleReference,
            'phone' => $possiblePhone,
            'message' => $validationMessage,
            'response_code' => $response['ResultCode'],
            'response_desc' => $response['ResultDesc']
        ];
        
        file_put_contents($validationLogFile, "VALIDATION_RESULT: " . json_encode($validationLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 40) . "\n", FILE_APPEND | LOCK_EX);
        
        // Also log to WHMCS
        logTransaction('woza', $validationLog, 'C2B Validation ' . $validationResult);
        
    } else {
        // No data received or invalid format
        $errorLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'NO_DATA',
            'message' => 'No validation data received or invalid format'
        ];
        
        file_put_contents($validationLogFile, "ERROR: " . json_encode($errorLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 40) . "\n", FILE_APPEND | LOCK_EX);
    }
    
} catch (Exception $e) {
    $exceptionLog = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'EXCEPTION',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    file_put_contents($validationLogFile, "EXCEPTION: " . json_encode($exceptionLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 40) . "\n", FILE_APPEND | LOCK_EX);
    
    // Log to WHMCS as well
    logTransaction('woza', $exceptionLog, 'C2B Validation Exception');
    
    // In case of exception, reject the payment for safety
    $response = [
        'ResultCode' => 1,
        'ResultDesc' => 'Validation error occurred'
    ];
}

// Send JSON response
header('Content-Type: application/json');
http_response_code(200);
echo json_encode($response);

// Final log entry
$finalLog = [
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'COMPLETED',
    'message' => 'Validation processing completed',
    'response_sent' => $response
];

file_put_contents($validationLogFile, "FINAL: " . json_encode($finalLog, JSON_PRETTY_PRINT) . "\n" . str_repeat('=', 80) . "\n\n", FILE_APPEND | LOCK_EX);

exit;
?> 