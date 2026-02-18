<?php
/**
 * Grammar Mentor - Lemon Squeezy API Handler
 * 
 * This file handles all Lemon Squeezy API interactions:
 * - License validation
 * - Subscription checking
 * - User authentication
 */

require_once __DIR__ . '/config.php';

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

define('LEMONSQUEEZY_API_URL', 'https://api.lemonsqueezy.com/v1');

// Product variant IDs
define('VARIANT_MONTHLY', '1303449');
define('VARIANT_YEARLY', '1303454');
define('VARIANT_LIFETIME', '1303448');

// Checkout URLs
define('CHECKOUT_MONTHLY', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/f1ea24e6-4964-46a0-b442-3a659f76ed5a');
define('CHECKOUT_YEARLY', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/4f3f8322-6efa-41d6-b884-8176cbcac195');
define('CHECKOUT_LIFETIME', 'https://grammar-mentor.lemonsqueezy.com/checkout/buy/d9b6bd65-57d6-47eb-851e-47278b468439');

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
        case '1303448':
            return 'lifetime';
        case '1303449':   // monthly
        case '1303454':   // yearly
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

    // ── NEU ────────────────────────────────────────
    case 'login_with_email':
        handleLoginWithEmail();
        break;
    // ───────────────────────────────────────────────

    case 'google_login':
        handleGoogleLogin();
        break;

    default:
        sendResponse([
            'success' => false,
            'error' => 'Invalid action. Available actions: validate_license, check_subscription, get_checkout_url, get_config, login_with_email'
        ], 400);
}

// ============================================
// ACTION HANDLERS
// ============================================

function handleGoogleLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    $idToken = $input['id_token'] ?? '';

    if (empty($idToken)) {
        sendResponse(['success' => false, 'error' => 'Kein ID Token übermittelt'], 400);
    }

    // Google Tokeninfo Endpoint (validiert und gibt User-Info zurück)
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($idToken);
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (!$data || isset($data['error'])) {
        sendResponse(['success' => false, 'error' => $data['error_description'] ?? 'Ungültiges Token'], 401);
    }

    // Optional: Prüfe audience (deine Client ID)
    if ($data['aud'] !== '321621097003-j12qbjotes9glvohuqbepb2pouol7b7j.apps.googleusercontent.com') {
        sendResponse(['success' => false, 'error' => 'Ungültige Client ID'], 401);
    }

    $email = $data['email'] ?? '';
    $name  = $data['name']  ?? explode('@', $email)[0] ?? 'User';

    // Jetzt wie bei deinem email-login: In DB nachschauen
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $stmt = $pdo->prepare("SELECT plan, status, valid_until FROM subscriptions WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['status'] === 'active') {
            sendResponse([
                'success'    => true,
                'valid'      => true,
                'email'      => $email,
                'name'       => $name,
                'status'     => 'active',
                'plan'       => $row['plan'],
                'validUntil' => $row['valid_until']
            ]);
        } else {
            // Kein Abo → gib trotzdem Email zurück, Frontend kann upgraden vorschlagen
            sendResponse([
                'success' => true,
                'valid'   => false,
                'email'   => $email,
                'name'    => $name,
                'error'   => 'Kein aktives Pro-Abo gefunden. Upgrade möglich.'
            ]);
        }
    } catch (PDOException $e) {
        error_log("DB Fehler: " . $e->getMessage());
        sendResponse(['success' => false, 'error' => 'Technischer Fehler'], 500);
    }
}

/**
 * Validate a license key
 * POST: ?action=validate_license
 * Body: { "email": "user@example.com", "licenseKey": "XXXX-XXXX-XXXX-XXXX" }
 */
function handleValidateLicense() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email      = trim($input['email'] ?? '');
    $licenseKey = trim($input['licenseKey'] ?? '');
    
    if (empty($email) || empty($licenseKey)) {
        sendResponse([
            'success' => false,
            'error'   => 'Email and license key are required'
        ], 400);
    }
    
    logActivity('License validation attempt', ['email' => $email, 'key_prefix' => substr($licenseKey, 0, 8) . '...']);
    
    $response = lemonsqueezyRequest('/licenses/validate', 'POST', [
        'license_key' => $licenseKey,
        // instance_id nur anhängen, wenn du später aktivierst und die Instanz-ID speicherst
        // 'instance_id'  => $someStoredInstanceId   // ← optional / später
    ]);
    
    if (isset($response['error'])) {
        logActivity('API request failed', ['error' => $response['error']]);
        sendResponse([
            'success' => false,
            'error'   => 'License validation failed: ' . ($response['error'] ?? 'Unknown API error')
        ], 502);
    }
    
    $data         = $response['data'] ?? [];
    $license      = $data['attributes'] ?? [];
    $isValid      = $response['valid'] ?? false;
    $status       = $license['status'] ?? 'inactive';
    $expiresAt    = $license['expires_at'] ?? null;
    $variantId    = $response['meta']['variant_id'] ?? null;
    $customerName = $response['meta']['customer_name'] ?? explode('@', $email)[0] ?? 'User';
    
    if ($isValid && $status === 'active') {
        $plan = determinePlan($variantId);
        
        logActivity('Valid license', [
            'email' => $email,
            'plan'  => $plan,
            'expires_at' => $expiresAt
        ]);
        
        sendResponse([
            'success'    => true,
            'valid'      => true,
            'email'      => $email,
            'name'       => $customerName,
            'status'     => 'active',
            'plan'       => $plan,
            'validUntil' => $expiresAt,
            'variantId'  => $variantId,
            // Falls du später Instances trackst:
            // 'instance_id' => $license['instance_id'] ?? null
        ]);
    } else {
        logActivity('Invalid license', ['status' => $status, 'valid' => $isValid]);
        
        $errorMsg = $status !== 'active' ? "License is {$status}" : 'License not valid';
        
        sendResponse([
            'success' => false,
            'valid'   => false,
            'error'   => $errorMsg
        ], 200);   // 200 weil Frontend das als normale Antwort behandeln soll
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
            'lifetime' => CHECKOUT_LIFETIME
        ],
        'variantIds' => [
            'monthly' => VARIANT_MONTHLY,
            'yearly' => VARIANT_YEARLY,
            'lifetime' => VARIANT_LIFETIME
        ]
    ]);
}

/**
 * Einfacher E-Mail-Login ohne License-Key
 * POST: ?action=login_with_email
 * Body: { "email": "user@example.com" }
 */
function handleLoginWithEmail() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse([
            'success' => false,
            'error'   => 'Bitte eine gültige E-Mail-Adresse angeben'
        ], 400);
    }

    logActivity('Email-Login Versuch', ['email' => $email]);

    try {
        $pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

        $stmt = $pdo->prepare("
            SELECT plan, status, valid_until 
            FROM subscriptions 
            WHERE email = ? 
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['status'] === 'active') {
            $plan = $row['plan'];
            $validUntil = $row['valid_until'] 
                ?? date('Y-m-d', strtotime('+1 year'));  // realistischerer Fallback

            sendResponse([
                'success'    => true,
                'valid'      => true,
                'email'      => $email,
                'name'       => explode('@', $email)[0] ?: 'User',
                'status'     => 'active',
                'plan'       => $plan,
                'validUntil' => $validUntil
            ]);
        } else {
            sendResponse([
                'success' => true,
                'valid'   => false,
                'error'   => 'There is currently no active Pro subscription associated with this email address. Upgrade now?'
            ]);
        }
    } catch (PDOException $e) {
        error_log("DB Fehler in login_with_email: " . $e->getMessage());
        sendResponse([
            'success' => false,
            'error'   => 'Technical error – please try again later'
        ], 500);
    }
}