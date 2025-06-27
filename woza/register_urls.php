<?php
/**
 * WHMCS M-pesa URL Registration
 *
 * This file helps register your confirmation and validation URLs with Safaricom M-pesa API.
 * This is required for C2B (Customer to Business) payments to work properly.
 *
 * @copyright Hostraha
 * @license http://www.whmcs.com/license/ WHMCS Eula
 */

// Include the WHMCS core
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/gatewayfunctions.php';

use WHMCS\Database\Capsule;

// Get M-pesa gateway configuration
$gatewayParams = getGatewayVariables('woza');

// Check if gateway is configured
if (empty($gatewayParams['consumerKey']) || empty($gatewayParams['consumerSecret'])) {
    die('M-pesa gateway not properly configured. Please configure the gateway first.');
}

// Allow environment and response type override from URL parameters
$selectedEnvironment = $_GET['env'] ?? $gatewayParams['environment'] ?? 'sandbox';
$selectedResponseType = $_GET['response_type'] ?? 'Completed';

// Configuration
$consumerKey = $gatewayParams['consumerKey'];
$consumerSecret = $gatewayParams['consumerSecret'];
$environment = $selectedEnvironment;
$shortcode = $gatewayParams['shortcode'];

// API URLs based on selected environment
$baseUrl = ($environment === 'live' || $environment === 'production') 
    ? 'https://api.safaricom.co.ke' 
    : 'https://sandbox.safaricom.co.ke';

$authUrl = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
$registerUrl = $baseUrl . '/mpesa/c2b/v2/registerurl';

// Your URLs - Updated to use woza directory (no MPESA keyword)
$confirmationUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/woza/confirmation.php';
$validationUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/woza/validation.php';

/**
 * Get access token from Safaricom
 */
function getAccessToken($authUrl, $consumerKey, $consumerSecret) {
    $credentials = base64_encode($consumerKey . ':' . $consumerSecret);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $authUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $credentials,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_VERBOSE, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    // Enhanced error reporting
    if ($error) {
        throw new Exception("cURL Error: " . $error . "\nURL: " . $authUrl);
    }
    
    if ($httpCode !== 200) {
        $errorDetails = [
            'HTTP Code' => $httpCode,
            'URL' => $authUrl,
            'Response' => $response,
            'Consumer Key Length' => strlen($consumerKey),
            'Consumer Secret Length' => strlen($consumerSecret),
            'Credentials Length' => strlen($credentials),
            'Request Time' => $curlInfo['total_time'] ?? 'unknown'
        ];
        
        $errorMessage = "HTTP Error Details:\n";
        foreach ($errorDetails as $key => $value) {
            $errorMessage .= "- {$key}: {$value}\n";
        }
        
        // Try to decode response for more details
        $responseData = json_decode($response, true);
        if ($responseData && isset($responseData['error'])) {
            $errorMessage .= "- API Error: " . $responseData['error'] . "\n";
            if (isset($responseData['error_description'])) {
                $errorMessage .= "- Error Description: " . $responseData['error_description'] . "\n";
            }
        }
        
        throw new Exception($errorMessage);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['access_token'])) {
        throw new Exception("No access token received. Response: " . $response);
    }
    
    return $data['access_token'];
}

/**
 * Register URLs with Safaricom
 */
function registerUrls($registerUrl, $accessToken, $shortcode, $confirmationUrl, $validationUrl, $responseType = 'Completed') {
    $postData = [
        'ShortCode' => $shortcode,
        'ResponseType' => $responseType, // 'Completed' or 'Cancelled'
        'ConfirmationURL' => $confirmationUrl,
        'ValidationURL' => $validationUrl
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $registerUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    return [
        'http_code' => $httpCode,
        'response' => $response,
        'data' => json_decode($response, true)
    ];
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>M-pesa URL Registration</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background: #f5f5f5; 
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .header { 
            background: #007cba; 
            color: white; 
            padding: 15px; 
            margin: -20px -20px 20px -20px; 
            border-radius: 8px 8px 0 0; 
        }
        .info-box { 
            background: #e7f3ff; 
            border: 1px solid #b3d9ff; 
            padding: 15px; 
            border-radius: 5px; 
            margin: 15px 0; 
        }
        .success { 
            background: #d4edda; 
            border: 1px solid #c3e6cb; 
            color: #155724; 
        }
        .error { 
            background: #f8d7da; 
            border: 1px solid #f5c6cb; 
            color: #721c24; 
        }
        .warning { 
            background: #fff3cd; 
            border: 1px solid #ffeaa7; 
            color: #856404; 
        }
        pre { 
            background: #f8f9fa; 
            padding: 10px; 
            border-radius: 5px; 
            overflow-x: auto; 
            border: 1px solid #dee2e6; 
        }
        .btn { 
            background: #007cba; 
            color: white; 
            padding: 10px 20px; 
            text-decoration: none; 
            border-radius: 5px; 
            display: inline-block; 
            margin: 10px 5px; 
            border: none; 
            cursor: pointer; 
        }
        .btn:hover { 
            background: #005a87; 
        }
        .btn-danger { 
            background: #dc3545; 
        }
        .btn-danger:hover { 
            background: #c82333; 
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0; 
        }
        th, td { 
            padding: 10px; 
            text-align: left; 
            border-bottom: 1px solid #ddd; 
        }
        th { 
            background: #f8f9fa; 
            font-weight: bold; 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔗 M-pesa URL Registration</h1>
            <p>Register your confirmation and validation URLs with Safaricom</p>
            <p><small><strong>Note:</strong> URLs moved to /woza/ directory to avoid keyword restrictions</small></p>
        </div>

        <?php if (isset($_GET['register'])): ?>
            <div class="info-box">
                <h3>🚀 Registering URLs...</h3>
            </div>

            <?php
            try {
                // Debug: Show configuration being used
                echo "<h4>🔍 Debug Information:</h4>";
                echo "<table>";
                echo "<tr><th>Environment</th><td>" . htmlspecialchars($environment) . "</td></tr>";
                echo "<tr><th>Base URL</th><td>" . htmlspecialchars($baseUrl) . "</td></tr>";
                echo "<tr><th>Auth URL</th><td>" . htmlspecialchars($authUrl) . "</td></tr>";
                echo "<tr><th>Register URL</th><td>" . htmlspecialchars($registerUrl) . "</td></tr>";
                echo "<tr><th>Short Code</th><td>" . htmlspecialchars($shortcode) . "</td></tr>";
                echo "<tr><th>Consumer Key</th><td>" . htmlspecialchars(substr($consumerKey, 0, 10)) . "... (length: " . strlen($consumerKey) . ")</td></tr>";
                echo "<tr><th>Consumer Secret</th><td>" . htmlspecialchars(substr($consumerSecret, 0, 10)) . "... (length: " . strlen($consumerSecret) . ")</td></tr>";
                echo "<tr><th>Response Type</th><td>" . htmlspecialchars($selectedResponseType) . "</td></tr>";
                echo "<tr><th>Confirmation URL</th><td>" . htmlspecialchars($confirmationUrl) . "</td></tr>";
                echo "<tr><th>Validation URL</th><td>" . htmlspecialchars($validationUrl) . "</td></tr>";
                echo "</table>";
                
                // Step 1: Get access token
                echo "<h4>Step 1: Getting Access Token...</h4>";
                $accessToken = getAccessToken($authUrl, $consumerKey, $consumerSecret);
                echo "<div class='info-box success'><strong>✅ Access token obtained successfully!</strong></div>";
                
                // Step 2: Register URLs
                echo "<h4>Step 2: Registering URLs...</h4>";
                $result = registerUrls($registerUrl, $accessToken, $shortcode, $confirmationUrl, $validationUrl, $selectedResponseType);
                
                echo "<h4>Registration Result:</h4>";
                echo "<table>";
                echo "<tr><th>HTTP Code</th><td>" . $result['http_code'] . "</td></tr>";
                echo "<tr><th>Response</th><td><pre>" . htmlspecialchars($result['response']) . "</pre></td></tr>";
                echo "</table>";
                
                if ($result['http_code'] === 200 && isset($result['data']['ResponseDescription'])) {
                    if (stripos($result['data']['ResponseDescription'], 'success') !== false) {
                        echo "<div class='info-box success'>";
                        echo "<h4>✅ Registration Successful!</h4>";
                        echo "<p><strong>Response:</strong> " . htmlspecialchars($result['data']['ResponseDescription']) . "</p>";
                        echo "</div>";
                    } else {
                        echo "<div class='info-box error'>";
                        echo "<h4>❌ Registration Failed</h4>";
                        echo "<p><strong>Response:</strong> " . htmlspecialchars($result['data']['ResponseDescription']) . "</p>";
                        echo "</div>";
                    }
                } else {
                    echo "<div class='info-box error'>";
                    echo "<h4>❌ Registration Failed</h4>";
                    echo "<p>Unexpected response format or HTTP error</p>";
                    echo "</div>";
                }
                
            } catch (Exception $e) {
                echo "<div class='info-box error'>";
                echo "<h4>❌ Error occurred:</h4>";
                echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
            ?>

        <?php else: ?>
            
            <div class="info-box">
                <h3>🌍 Environment Selection</h3>
                <p>Choose the environment for URL registration:</p>
                <div style="margin: 15px 0;">
                    <a href="?env=sandbox" class="btn <?php echo ($environment === 'sandbox') ? '' : 'btn-secondary'; ?>" 
                       style="<?php echo ($environment === 'sandbox') ? 'background: #28a745;' : 'background: #6c757d;'; ?>">
                        🧪 Sandbox (Testing)
                    </a>
                    <a href="?env=live" class="btn <?php echo ($environment === 'live' || $environment === 'production') ? '' : 'btn-secondary'; ?>" 
                       style="<?php echo ($environment === 'live' || $environment === 'production') ? 'background: #dc3545;' : 'background: #6c757d;'; ?>">
                        🚀 Production (Live)
                    </a>
                </div>
                <?php if ($environment === 'sandbox'): ?>
                    <div class="info-box" style="background: #d1ecf1; border-color: #bee5eb; color: #0c5460;">
                        <strong>🧪 Sandbox Mode Selected</strong><br>
                        Use this for testing. No real money transactions will occur.
                    </div>
                <?php else: ?>
                    <div class="info-box warning">
                        <strong>⚠️ Production Mode Selected</strong><br>
                        This will register URLs for live transactions. Make sure your system is ready!
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <h3>⚙️ Response Type Selection</h3>
                <p>Choose what happens when validation URL is unreachable:</p>
                <div style="margin: 15px 0;">
                    <a href="?env=<?php echo urlencode($environment); ?>&response_type=Completed" class="btn <?php echo ($selectedResponseType === 'Completed') ? '' : 'btn-secondary'; ?>" 
                       style="<?php echo ($selectedResponseType === 'Completed') ? 'background: #28a745;' : 'background: #6c757d;'; ?>">
                        ✅ Completed (Auto-complete payments)
                    </a>
                    <a href="?env=<?php echo urlencode($environment); ?>&response_type=Cancelled" class="btn <?php echo ($selectedResponseType === 'Cancelled') ? '' : 'btn-secondary'; ?>" 
                       style="<?php echo ($selectedResponseType === 'Cancelled') ? 'background: #dc3545;' : 'background: #6c757d;'; ?>">
                        ❌ Cancelled (Auto-cancel payments)
                    </a>
                </div>
                <div class="info-box" style="background: #f8f9fa; border-color: #dee2e6; color: #495057;">
                    <strong>📖 What this means:</strong><br>
                    <?php if ($selectedResponseType === 'Completed'): ?>
                        <strong>✅ Completed:</strong> If your validation URL is unreachable, M-pesa will automatically <strong>complete</strong> the payment and send confirmation to your system.
                    <?php else: ?>
                        <strong>❌ Cancelled:</strong> If your validation URL is unreachable, M-pesa will automatically <strong>cancel</strong> the payment. No confirmation will be sent.
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="info-box">
                <h3>📋 Current Configuration</h3>
                <table>
                    <tr><th>Environment</th><td><strong><?php echo ucfirst($environment); ?></strong></td></tr>
                    <tr><th>Response Type</th><td><strong><?php echo htmlspecialchars($selectedResponseType); ?></strong></td></tr>
                    <tr><th>Short Code</th><td><?php echo htmlspecialchars($shortcode); ?></td></tr>
                    <tr><th>Consumer Key</th><td><?php echo htmlspecialchars(substr($consumerKey, 0, 10)) . '...'; ?></td></tr>
                    <tr><th>Base URL</th><td><strong><?php echo htmlspecialchars($baseUrl); ?></strong></td></tr>
                </table>
            </div>

            <div class="info-box">
                <h3>🔗 URLs to be Registered</h3>
                <table>
                    <tr><th>Confirmation URL</th><td><?php echo htmlspecialchars($confirmationUrl); ?></td></tr>
                    <tr><th>Validation URL</th><td><?php echo htmlspecialchars($validationUrl); ?></td></tr>
                </table>
                <div class="info-box success">
                    <strong>✅ URL Compliance:</strong> These URLs use the /woza/ directory and avoid forbidden keywords like "MPESA", "Safaricom", etc.
                </div>
            </div>

            <div class="info-box warning">
                <h3>⚠️ Important Notes</h3>
                <?php if ($environment === 'sandbox'): ?>
                    <ul>
                        <li><strong>Testing Environment:</strong> This is sandbox mode - no real money involved</li>
                        <li><strong>Test Credentials:</strong> Use Safaricom sandbox credentials</li>
                        <li><strong>Test Shortcode:</strong> Use sandbox shortcode (usually 600982)</li>
                        <li><strong>HTTPS Optional:</strong> HTTP URLs may work in sandbox for testing</li>
                        <li><strong>URL Keywords:</strong> Avoided forbidden keywords by using /woza/ directory</li>
                    </ul>
                <?php else: ?>
                    <ul>
                        <li><strong>PRODUCTION Environment:</strong> This will handle real money transactions!</li>
                        <li><strong>HTTPS REQUIRED:</strong> Safaricom requires HTTPS URLs for production</li>
                        <li><strong>Valid SSL:</strong> Your domain must have a valid SSL certificate</li>
                        <li><strong>Live Credentials:</strong> Use your live Safaricom credentials</li>
                        <li><strong>Live Shortcode:</strong> Use your actual paybill/till number</li>
                        <li><strong>Public Access:</strong> URLs must be publicly accessible from internet</li>
                        <li><strong>URL Keywords:</strong> Avoided forbidden keywords by using /woza/ directory</li>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="info-box">
                <h3>🔧 What this does:</h3>
                <ul>
                    <li>Authenticates with Safaricom API using your credentials</li>
                    <li>Registers your confirmation URL to receive payment notifications</li>
                    <li>Registers your validation URL to validate payments (optional)</li>
                    <li>Enables C2B (Customer to Business) payments</li>
                    <li>Uses clean URLs without forbidden keywords</li>
                </ul>
            </div>

            <p>
                <?php if ($environment === 'live' || $environment === 'production'): ?>
                    <a href="?register=1&env=<?php echo urlencode($environment); ?>&response_type=<?php echo urlencode($selectedResponseType); ?>" class="btn" 
                       onclick="return confirm('⚠️ WARNING: You are about to register URLs for PRODUCTION environment!\n\nThis will enable live M-pesa transactions. Make sure:\n✅ Your SSL certificate is valid\n✅ Your URLs are publicly accessible\n✅ Your system is ready for live payments\n\nResponse Type: <?php echo $selectedResponseType; ?>\n\nDo you want to continue?');">
                        🚀 Register URLs for Production
                    </a>
                <?php else: ?>
                    <a href="?register=1&env=<?php echo urlencode($environment); ?>&response_type=<?php echo urlencode($selectedResponseType); ?>" class="btn">🧪 Register URLs for Sandbox</a>
                <?php endif; ?>
                <a href="../modules/gateways/whmcs-mpesa/test/test_confirmation.php" class="btn">🧪 Test Confirmation Handler</a>
            </p>

        <?php endif; ?>

        <hr>
        
        <div class="info-box">
            <h3>📚 Next Steps After Registration</h3>
            <ol>
                <li><strong>Test the confirmation handler:</strong> Use the test script to verify it works</li>
                <li><strong>Create validation.php:</strong> If needed for additional validation</li>
                <li><strong>Configure paybill:</strong> Set up your paybill number in Safaricom portal</li>
                <li><strong>Test C2B payments:</strong> Make test payments to your paybill</li>
                <li><strong>Monitor logs:</strong> Check confirmation logs for incoming payments</li>
            </ol>
        </div>

        <div class="info-box">
            <h3>🔍 Troubleshooting & Safaricom Requirements</h3>
            <ul>
                <li><strong>SSL Certificate:</strong> Ensure your domain has a valid SSL certificate (required for production)</li>
                <li><strong>Public URLs:</strong> Use publicly accessible IP addresses or domain names</li>
                <li><strong>URL Keywords:</strong> Avoid keywords like M-PESA, Safaricom, SQL, exe, cmd in your URLs</li>
                <li><strong>No Public Testers:</strong> Don't use ngrok, mockbin, requestbin (especially production)</li>
                <li><strong>Response Time:</strong> Validation responses must be received within 8 seconds</li>
                <li><strong>External Validation:</strong> Optional feature - email apisupport@safaricom.co.ke to activate</li>
                <li><strong>One-Time Production:</strong> Production URLs can only be registered once</li>
                <li><strong>Sandbox vs Production:</strong> Sandbox allows multiple registrations, production is one-time</li>
                <li><strong>Logs:</strong> Check <code>modules/gateways/whmcs-mpesa/logs/</code> for detailed logs</li>
            </ul>
        </div>

        <hr>
        <p><small>
            <strong>Confirmation URL:</strong> <?php echo $confirmationUrl; ?><br>
            <strong>Validation URL:</strong> <?php echo $validationUrl; ?>
        </small></p>
    </div>
</body>
</html>