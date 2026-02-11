<?php
/**
 * Grammar Mentor - Lemon Squeezy API Handler
 * 
 * This file handles all Lemon Squeezy API interactions:
 * - License validation
 * - Subscription checking
 * - User authentication
 */

// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// CORS headers for frontend access
header('Access-Control-Allow-Origin: *'); // In production, replace * with your domain
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// CONFIGURATION
// ============================================

define('LEMONSQUEEZY_API_KEY', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiI5NGQ1OWNlZi1kYmI4LTRlYTUtYjE3OC1kMjU0MGZjZDY5MTkiLCJqdGkiOiI5NTAwNjgzY2Y4OTlkYjAwMzUwNGIyMDRjNjBmMDJkODE4YWUwNzA0ZDVhNGNjNjQ3OTI1MjhlMjQ1MDUwMjJjZDgyZDFhMTk2MDhkYWVhNiIsImlhdCI6MTc3MDgzOTg1MC42NzczNTEsIm5iZiI6MTc3MDgzOTg1MC42NzczNTMsImV4cCI6MTc4NjQwNjQwMC4wMzI2NDUsInN1YiI6IjY0ODk2OTIiLCJzY29wZXMiOltdfQ.CAZg6uFFvBHV7SrrRgF-m8URb0QTG_FGV3kgeMzctjXsUFEhJNK6rBo4knPdJWGumXP3UadKyZTUuNNXuR2pqfUMI4PO0DQjsGmwIgTBbQbtXaIwnTi5r2wMEzS93Umb8fGONLb7SDV_H_AAdKEO4LNLPYszUAmEjld8RGR2eJUY-Eumk1E4FULR3nOT9INs3DLJOFZ-gkjSaK5K2DRrCiQ20HO0IEd0eFjSRXsH4nPsamAHrnOGLWSVd1MzfBG3u2Tk-mexS5fBwNGYozpofhPd-OVNJ_u6TWV9t0U3RdH8t2Jb36kgJJt7WTJPWBQ-IdhQOCSlASBOtKtqXmw2_HxTRjq1GnRB3sM5t2ivIm1FqFwBf8efnWd4N4kZ6C4zZS4hvd-YSoUt-ej2VjgW2ZUjR7PnZDvJ1h-z27DTJrsfYy7YDod6mlnrW04YZBQyqH66i56vdQM6jDthk6xGO1oZo2mahz94VZhCHx_xbBwx-C5znvIH5-43T8Wx366WJxYJwKe1x5j-_qAnro5SzoCkg9DaXUVX84FhVpmpWn0xUj0_q0tmqv7hHqBb8iqPPRuGQwTCNzjGXOXGBMVnuj-EHEyXBKYMeKrRMPFv0hCndm8CIXvjqi_YHabUygC3wqvxv8f3bPAwSxWRPXxFoenBFuh6WAJ7uftZm9SACJk');
define('LEMONSQUEEZY_API_URL', 'https://api.lemonsqueezy.com/v1');

// Product variant IDs
define('VARIANT_MONTHLY', '824475');
define('VARIANT_YEARLY', '824478');
define('VARIANT_2YEAR', '824483');
define('VARIANT_LIFETIME', '824489');

// Checkout URLs
define('CHECKOUT_MONTHLY', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/cbb95cf4-1ecc-4032-a73c-79b6f771ed33');
define('CHECKOUT_YEARLY', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/fc3e92b7-331e-4af5-a2cd-4a12a5f5f33a');
define('CHECKOUT_2YEAR', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/f2f898f3-5680-4656-8221-3f0e6936cf0b');
define('CHECKOUT_LIFETIME', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/2ab2920a-9841-417b-908e-f924e76252af');

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Make a request to Lemon Squeezy API
 */
function lemonsqueezyRequest($endpoint, $method = 'GET', $data = null) {
    $url = LEMONSQUEEZY_API_URL . $endpoint;
    
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . LEMONSQUEEZY_API_KEY
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Lemon Squeezy API Error: " . $error);
        return ['error' => $error, 'httpCode' => $httpCode];
    }
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 400) {
        error_log("Lemon Squeezy API Error: HTTP $httpCode - " . $response);
        return ['error' => $decoded['errors'][0]['detail'] ?? 'API request failed', 'httpCode' => $httpCode];
    }
    
    return $decoded;
}

/**
 * Determine subscription plan from variant ID
 */
function determinePlan($variantId) {
    $variantId = strval($variantId);
    
    switch ($variantId) {
        case VARIANT_LIFETIME:
            return 'lifetime';
        case VARIANT_2YEAR:
        case VARIANT_YEARLY:
        case VARIANT_MONTHLY:
            return 'pro';
        default:
            return 'free';
    }
}

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

/**
 * Log activity (you can customize this to write to database)
 */
function logActivity($message, $data = []) {
    $logEntry = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($data)) {
        $logEntry .= ' - ' . json_encode($data);
    }
    error_log($logEntry);
}

// ============================================
// MAIN ROUTER
// ============================================

// Get the action from query parameter or POST data
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'validate_license':
        handleValidateLicense();
        break;
    
    case 'check_subscription':
        handleCheckSubscription();
        break;
    
    case 'get_checkout_url':
        handleGetCheckoutUrl();
        break;
    
    case 'get_config':
        handleGetConfig();
        break;
    
    default:
        sendResponse([
            'success' => false,
            'error' => 'Invalid action. Available actions: validate_license, check_subscription, get_checkout_url, get_config'
        ], 400);
}

// ============================================
// ACTION HANDLERS
// ============================================

/**
 * Validate a license key
 * POST: ?action=validate_license
 * Body: { "email": "user@example.com", "licenseKey": "XXXX-XXXX-XXXX-XXXX" }
 */
function handleValidateLicense() {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = $input['email'] ?? '';
    $licenseKey = $input['licenseKey'] ?? '';
    
    if (empty($email) || empty($licenseKey)) {
        sendResponse([
            'success' => false,
            'error' => 'Email and license key are required'
        ], 400);
    }
    
    logActivity('License validation attempt', ['email' => $email]);
    
    // Call Lemon Squeezy API to validate license
    $response = lemonsqueezyRequest('/licenses/validate', 'POST', [
        'license_key' => $licenseKey,
        'instance_name' => $email
    ]);
    
    if (isset($response['error'])) {
        sendResponse([
            'success' => false,
            'error' => 'Failed to validate license: ' . $response['error']
        ], 500);
    }
    
    // Check if license is valid and active
    $isValid = $response['valid'] ?? false;
    $licenseStatus = $response['license_key']['status'] ?? 'inactive';
    
    if ($isValid && $licenseStatus === 'active') {
        // Get subscription details
        $variantId = $response['meta']['variant_id'] ?? null;
        $customerName = $response['meta']['customer_name'] ?? explode('@', $email)[0];
        $expiresAt = $response['license_key']['expires_at'] ?? null;
        
        $plan = determinePlan($variantId);
        
        logActivity('License validation successful', [
            'email' => $email,
            'plan' => $plan
        ]);
        
        sendResponse([
            'success' => true,
            'valid' => true,
            'email' => $email,
            'name' => $customerName,
            'status' => 'active',
            'plan' => $plan,
            'validUntil' => $expiresAt,
            'variantId' => $variantId
        ]);
    } else {
        logActivity('License validation failed', ['email' => $email, 'status' => $licenseStatus]);
        
        sendResponse([
            'success' => true,
            'valid' => false,
            'error' => 'Invalid or inactive license key'
        ]);
    }
}

/**
 * Check subscription status by email
 * POST: ?action=check_subscription
 * Body: { "email": "user@example.com" }
 */
function handleCheckSubscription() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';
    
    if (empty($email)) {
        sendResponse([
            'success' => false,
            'error' => 'Email is required'
        ], 400);
    }
    
    // TODO: If you're storing subscription data in a database, query it here
    // For now, we'll return a simple response
    
    sendResponse([
        'success' => true,
        'hasSubscription' => false,
        'message' => 'Please validate your license key to check subscription status'
    ]);
}

/**
 * Get checkout URL for a specific plan
 * GET: ?action=get_checkout_url&plan=yearly
 */
function handleGetCheckoutUrl() {
    $plan = $_GET['plan'] ?? 'yearly';
    
    $checkoutUrls = [
        'monthly' => CHECKOUT_MONTHLY,
        'yearly' => CHECKOUT_YEARLY,
        '2year' => CHECKOUT_2YEAR,
        'lifetime' => CHECKOUT_LIFETIME
    ];
    
    if (!isset($checkoutUrls[$plan])) {
        sendResponse([
            'success' => false,
            'error' => 'Invalid plan. Available: monthly, yearly, 2year, lifetime'
        ], 400);
    }
    
    sendResponse([
        'success' => true,
        'plan' => $plan,
        'checkoutUrl' => $checkoutUrls[$plan]
    ]);
}

/**
 * Get configuration for frontend
 * GET: ?action=get_config
 */
function handleGetConfig() {
    sendResponse([
        'success' => true,
        'checkoutUrls' => [
            'monthly' => CHECKOUT_MONTHLY,
            'yearly' => CHECKOUT_YEARLY,
            '2year' => CHECKOUT_2YEAR,
            'lifetime' => CHECKOUT_LIFETIME
        ],
        'variantIds' => [
            'monthly' => VARIANT_MONTHLY,
            'yearly' => VARIANT_YEARLY,
            '2year' => VARIANT_2YEAR,
            'lifetime' => VARIANT_LIFETIME
        ]
    ]);
}