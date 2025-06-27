<?php
/**
 * WHMCS M-pesa Payment Gateway Module
 *
 * Payment Gateway modules allow you to integrate payment solutions with the
 * WHMCS platform.
 *
 * This M-pesa payment gateway module facilitates mobile money payments via
 * M-pesa in Kenya and other supported regions.
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @return array
 */
function woza_MetaData()
{
    return array(
        'DisplayName' => 'M-pesa',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define gateway configuration options.
 *
 * @return array
 */
function woza_config()
{
    return array(
        // Friendly display name for the gateway
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'M-pesa',
        ),
        // Consumer Key from Safaricom Daraja API
        'consumerKey' => array(
            'FriendlyName' => 'Consumer Key',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your Consumer Key from Safaricom Daraja API',
        ),
        // Consumer Secret from Safaricom Daraja API
        'consumerSecret' => array(
            'FriendlyName' => 'Consumer Secret',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your Consumer Secret from Safaricom Daraja API',
        ),
        // Shortcode/Paybill/Till Number
        'shortcode' => array(
            'FriendlyName' => 'Paybill/Till Number',
            'Type' => 'text',
            'Size' => '15',
            'Default' => '',
            'Description' => 'Enter your M-pesa Paybill or Till Number',
        ),
        // Account Reference or Business Short Name
        'passkey' => array(
            'FriendlyName' => 'Pass Key',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your M-pesa Pass Key',
        ),
        // Environment - Sandbox or Production
        'environment' => array(
            'FriendlyName' => 'Environment',
            'Type' => 'dropdown',
            'Options' => array(
                'sandbox' => 'Sandbox (Test)',
                'production' => 'Production (Live)',
            ),
            'Description' => 'Select environment',
        ),
        // Callback URL
        'callbackUrl' => array(
            'FriendlyName' => 'Callback URL',
            'Type' => 'text',
            'Size' => '100',
            'Default' => '',
            'Description' => 'Leave empty for auto-generated URL',
        ),
        // STK Push Timeout in seconds
        'transactionTimeout' => array(
            'FriendlyName' => 'Transaction Timeout',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '60',
            'Description' => 'Enter the timeout in seconds for the STK Push (default: 60)',
        ),
        // Auto-redirect to payment page
        'autoRedirect' => array(
            'FriendlyName' => 'Auto-redirect to Payment Page',
            'Type' => 'yesno',
            'Description' => 'Automatically redirect users to the dedicated payment page (recommended for better UX)',
        ),
    );
}

/**
 * Payment link.
 *
 * Required by the WHMCS module system.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return string
 */
function woza_link($params)
{
    // Validate required gateway configuration
    if (empty($params['consumerKey']) || empty($params['consumerSecret']) || 
        empty($params['shortcode']) || empty($params['passkey'])) {
        return '<div class="alert alert-danger"><strong>Error:</strong> M-pesa gateway is not properly configured. Please contact the administrator.</div>';
    }

    // Gateway Configuration Parameters
    $consumerKey = $params['consumerKey'];
    $consumerSecret = $params['consumerSecret'];
    $shortcode = $params['shortcode'];
    $passkey = $params['passkey'];
    $environment = $params['environment'];
    
    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    // Client Parameters
    $clientId = $params['clientdetails']['id'];
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];
    
    // Validate required parameters
    if (empty($invoiceId) || empty($amount) || empty($clientId)) {
        return '<div class="alert alert-danger"><strong>Error:</strong> Missing required payment parameters.</div>';
    }
    
    // Format phone number to remove country code prefix if present
    $phone = woza_formatPhone($phone);
    
    $returnData = "";
    $paymentStatus = "";
    
    // No internal STK Push processing - will use external form submission
    
    // Check for payment verification
    if (isset($_POST['verifypayment']) && $_POST['verifypayment']) {
        $verificationResult = woza_verifyPayment($params, $invoiceId);
        if ($verificationResult['success']) {
            $returnData = "<div class='alert alert-success'><strong>Success!</strong> Payment verified and processed.</div>";
            // Refresh the page to show updated invoice status
            echo "<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>";
        } else {
            $returnData = "<div class='alert alert-warning'><strong>Notice:</strong> " . $verificationResult['message'] . "</div>";
        }
    }
    
    // Build the payment redirect to dedicated page with security token
    $securityToken = md5($invoiceId . $clientId . date('Y-m-d') . 'woza_payment_security');
    $paymentUrl = $systemUrl . 'payment.php?invoice_id=' . $invoiceId . '&token=' . $securityToken . '&return_url=' . urlencode($returnUrl);
    
    // Check if auto-redirect is enabled and this is a direct payment request (not a verification)
    $autoRedirectEnabled = isset($params['autoRedirect']) && $params['autoRedirect'] == 'on';
    $shouldAutoRedirect = $autoRedirectEnabled && !isset($_POST['verifypayment']) && empty($returnData);
    
    $htmlOutput = '
    <div class="mpesa-payment-redirect" style="text-align: center; padding: 30px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; font-family: Arial, sans-serif;">
        <div style="margin-bottom: 20px;">
            <img src="' . $systemUrl . '/img/mpesa.png" alt="M-pesa" style="max-height: 60px; margin-bottom: 15px;" />
            <h3 style="color: #333; margin: 10px 0;">M-pesa Payment</h3>
            <p style="color: #666; margin: 5px 0;">Invoice #' . $invoiceId . ' - ' . $currencyCode . ' ' . number_format($amount, 2) . '</p>
        </div>';
    
    if ($shouldAutoRedirect) {
        $htmlOutput .= '
        <div id="auto-redirect-container" style="margin: 20px 0;">
            <div style="background: linear-gradient(135deg, #4CAF50, #45a049); color: white; padding: 20px; border-radius: 8px; margin-bottom: 15px;">
                <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <div class="spinner" style="width: 20px; height: 20px; border: 2px solid rgba(255,255,255,0.3); border-top: 2px solid white; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <span>Redirecting to payment page...</span>
                </div>
                <div id="countdown" style="font-size: 14px; margin-top: 10px; opacity: 0.9;">Redirecting in <span id="countdown-number">3</span> seconds</div>
            </div>
            <button type="button" onclick="redirectNow()" style="background: #2196F3; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-right: 10px;">Pay Now</button>
            <button type="button" onclick="cancelAutoRedirect()" style="background: #f44336; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">Cancel Auto-redirect</button>
        </div>
        
        <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        
        <script>
        let countdown = 3;
        let autoRedirectEnabled = true;
        
        function updateCountdown() {
            if (!autoRedirectEnabled) return;
            
            document.getElementById("countdown-number").textContent = countdown;
            
            if (countdown <= 0) {
                redirectNow();
                return;
            }
            
            countdown--;
            setTimeout(updateCountdown, 1000);
        }
        
        function redirectNow() {
            window.location.href = "' . $paymentUrl . '";
        }
        
        function cancelAutoRedirect() {
            autoRedirectEnabled = false;
            document.getElementById("auto-redirect-container").style.display = "none";
            document.getElementById("manual-options").style.display = "block";
        }
        
        // Start countdown
        updateCountdown();
        </script>';
    }
    
    $htmlOutput .= '
        <div id="manual-options" style="' . ($shouldAutoRedirect ? 'display: none;' : '') . '">
            <div style="margin: 20px 0;">
                <a href="' . $paymentUrl . '" class="btn btn-success" style="background: #4CAF50; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 5px;">
                    <i class="fa fa-mobile" style="margin-right: 8px;"></i>Pay with M-pesa
                </a>
            </div>
            
            <div style="margin: 20px 0; padding: 15px; background: #e8f4fd; border-left: 4px solid #2196F3; border-radius: 4px;">
                <h4 style="margin: 0 0 10px 0; color: #1976D2;">How to Pay:</h4>
                <ol style="text-align: left; margin: 0; padding-left: 20px; color: #333;">
                    <li>Click "Pay with M-pesa" button above</li>
                    <li>Enter your M-pesa phone number</li>
                    <li>Check your phone for STK Push prompt</li>
                    <li>Enter your M-pesa PIN to complete payment</li>
                </ol>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
            <p style="margin: 0; color: #856404; font-size: 14px;">
                <strong>Already paid?</strong> If you\'ve completed the M-pesa payment but the invoice is still showing as unpaid, 
                please click the button below to verify your payment.
            </p>
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="verifypayment" value="1" />
                <button type="submit" style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                    <i class="fa fa-check" style="margin-right: 5px;"></i>Verify Payment
                </button>
            </form>
        </div>
    </div>';
    
    return $returnData . $htmlOutput;
}

/**
 * Verify payment status
 */
function woza_verifyPayment($params, $invoiceId)
{
    try {
        // Check if payment already exists in WHMCS
        $existingPayment = \WHMCS\Database\Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('gateway', 'woza')
            ->first();
        
        if ($existingPayment) {
            return [
                'success' => true,
                'message' => 'Payment already recorded for this invoice.'
            ];
        }
        
        // Check our transaction table for completed payments
        $transaction = \WHMCS\Database\Capsule::table('mod_mpesa_transactions')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->first();
        
        if ($transaction && !empty($transaction->mpesa_receipt_number)) {
            // Add payment to WHMCS
            $success = addInvoicePayment(
                $invoiceId,
                $transaction->mpesa_receipt_number,
                $transaction->amount,
                0,
                'woza'
            );
            
            if ($success) {
                return [
                    'success' => true,
                    'message' => 'Payment verified and added to your account.'
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'No completed payment found for this invoice. Please ensure you have completed the M-pesa payment.'
        ];
        
    } catch (Exception $e) {
        logTransaction('woza', ['error' => $e->getMessage()], 'Payment Verification Error');
        return [
            'success' => false,
            'message' => 'Error verifying payment. Please try again or contact support.'
        ];
    }
}

/**
 * Format phone number for M-pesa
 */
function woza_formatPhone($phone)
{
    // Remove any non-numeric characters except +
    $phone = preg_replace('/[^\d+]/', '', $phone);
    
    // Handle different formats
    if (strpos($phone, '+254') === 0) {
        // +254712345678 -> 254712345678
        $phone = substr($phone, 1);
    } elseif (strpos($phone, '254') === 0) {
        // 254712345678 -> keep as is
        $phone = $phone;
    } elseif (strpos($phone, '07') === 0 || strpos($phone, '01') === 0) {
        // 0712345678 -> 254712345678
        $phone = '254' . substr($phone, 1);
    } elseif (strlen($phone) === 9 && (strpos($phone, '7') === 0 || strpos($phone, '1') === 0)) {
        // 712345678 -> 254712345678
        $phone = '254' . $phone;
    }
    
    // Validate final format (should be 254XXXXXXXXX with exactly 12 digits)
    if (!preg_match('/^254\d{9}$/', $phone)) {
        // Return original if format is invalid
        return $phone;
    }
    
    return $phone;
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Transaction response status
 */
function woza_refund($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Transaction Parameters
    $transactionIdToRefund = $params['transid'];
    $refundAmount = $params['amount'];
    $currencyCode = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // M-pesa doesn't support automatic refunds through API
    // This would need to be handled manually through M-pesa portal
    return array(
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status' => 'error',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => 'M-pesa refunds must be processed manually through the M-pesa portal',
        // Unique Transaction ID for the refund transaction
        'transid' => '',
        // Optional fee amount for the fee value refunded
        'fees' => 0,
    );
}