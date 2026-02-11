<?php
/**
 * Grammar Mentor - Lemon Squeezy Webhook Handler
 * 
 * This file receives and processes webhooks from Lemon Squeezy
 * Place this file at: grammar-mentor.com/webhook.php
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Never display errors for webhooks
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook-errors.log');

// Webhook signing secret
define('WEBHOOK_SECRET', 'queryzillepg');

// Log file for webhook events
define('WEBHOOK_LOG', __DIR__ . '/webhook-events.log');

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Log webhook event
 */
function logWebhook($message, $data = []) {
    $logEntry = date('Y-m-d H:i:s') . ' - ' . $message;
    if (!empty($data)) {
        $logEntry .= "\n" . json_encode($data, JSON_PRETTY_PRINT);
    }
    $logEntry .= "\n" . str_repeat('-', 80) . "\n";
    
    file_put_contents(WEBHOOK_LOG, $logEntry, FILE_APPEND);
}

/**
 * Verify webhook signature
 */
function verifySignature($payload, $signature) {
    $hash = hash_hmac('sha256', $payload, WEBHOOK_SECRET);
    return hash_equals($hash, $signature);
}

/**
 * Send response and exit
 */
function sendResponse($message, $statusCode = 200) {
    http_response_code($statusCode);
    echo $message;
    exit();
}

/**
 * Determine plan from variant ID
 */
function determinePlan($variantId) {
    $variantId = strval($variantId);
    
    // Product variant IDs
    $variants = [
        '824489' => 'lifetime', // Lifetime
        '824483' => 'pro',      // 2 years
        '824478' => 'pro',      // Yearly
        '824475' => 'pro'       // Monthly
    ];
    
    return $variants[$variantId] ?? 'free';
}

// ============================================
// MAIN WEBHOOK HANDLER
// ============================================

// Get the raw POST body
$payload = file_get_contents('php://input');

// Get the signature from headers
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

// Log the incoming webhook
logWebhook('Webhook received', [
    'signature_present' => !empty($signature),
    'payload_length' => strlen($payload)
]);

// Verify signature
if (empty($signature)) {
    logWebhook('ERROR: No signature provided');
    sendResponse('No signature provided', 401);
}

if (!verifySignature($payload, $signature)) {
    logWebhook('ERROR: Invalid signature');
    sendResponse('Invalid signature', 401);
}

// Parse the webhook payload
$event = json_decode($payload, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logWebhook('ERROR: Invalid JSON payload');
    sendResponse('Invalid JSON', 400);
}

// Get event name and data
$eventName = $event['meta']['event_name'] ?? 'unknown';
$eventData = $event['data'] ?? [];

logWebhook("Event: $eventName", $eventData);

// ============================================
// WEBHOOK EVENT HANDLERS
// ============================================

switch ($eventName) {
    case 'subscription_created':
        handleSubscriptionCreated($eventData);
        break;
    
    case 'subscription_updated':
        handleSubscriptionUpdated($eventData);
        break;
    
    case 'subscription_cancelled':
    case 'subscription_expired':
        handleSubscriptionEnded($eventData);
        break;
    
    case 'subscription_payment_success':
        handlePaymentSuccess($eventData);
        break;
    
    case 'subscription_payment_failed':
        handlePaymentFailed($eventData);
        break;
    
    case 'subscription_payment_recovered':
        handlePaymentRecovered($eventData);
        break;
    
    case 'order_created':
        handleOrderCreated($eventData);
        break;
    
    case 'license_key_created':
        handleLicenseKeyCreated($eventData);
        break;
    
    default:
        logWebhook("Unhandled event type: $eventName");
}

sendResponse('OK', 200);

// ============================================
// EVENT HANDLERS
// ============================================

/**
 * Handle new subscription creation
 */
function handleSubscriptionCreated($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['user_email'] ?? '';
    $subscriptionId = $attributes['id'] ?? '';
    $variantId = $attributes['variant_id'] ?? '';
    $status = $attributes['status'] ?? '';
    $endsAt = $attributes['ends_at'] ?? null;
    $renewsAt = $attributes['renews_at'] ?? null;
    
    $plan = determinePlan($variantId);
    
    logWebhook('✅ SUBSCRIPTION CREATED', [
        'email' => $email,
        'subscription_id' => $subscriptionId,
        'plan' => $plan,
        'variant_id' => $variantId,
        'status' => $status,
        'renews_at' => $renewsAt
    ]);
    
    // TODO: Update your database
    // Example:
    /*
    $db = new PDO('mysql:host=localhost;dbname=grammar_mentor', 'username', 'password');
    $stmt = $db->prepare("
        INSERT INTO users (email, subscription_id, subscription_status, subscription_plan, variant_id, subscription_ends_at, created_at)
        VALUES (:email, :sub_id, :status, :plan, :variant_id, :ends_at, NOW())
        ON DUPLICATE KEY UPDATE
            subscription_id = :sub_id,
            subscription_status = :status,
            subscription_plan = :plan,
            variant_id = :variant_id,
            subscription_ends_at = :ends_at,
            updated_at = NOW()
    ");
    $stmt->execute([
        'email' => $email,
        'sub_id' => $subscriptionId,
        'status' => $status,
        'plan' => $plan,
        'variant_id' => $variantId,
        'ends_at' => $endsAt
    ]);
    */
    
    // TODO: Send welcome email to user
    sendWelcomeEmail($email, $plan);
}

/**
 * Handle subscription updates
 */
function handleSubscriptionUpdated($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['user_email'] ?? '';
    $subscriptionId = $attributes['id'] ?? '';
    $status = $attributes['status'] ?? '';
    $variantId = $attributes['variant_id'] ?? '';
    
    $plan = determinePlan($variantId);
    
    logWebhook('🔄 SUBSCRIPTION UPDATED', [
        'email' => $email,
        'subscription_id' => $subscriptionId,
        'status' => $status,
        'plan' => $plan
    ]);
    
    // TODO: Update database
    /*
    $db = new PDO('mysql:host=localhost;dbname=grammar_mentor', 'username', 'password');
    $stmt = $db->prepare("
        UPDATE users 
        SET subscription_status = :status,
            subscription_plan = :plan,
            variant_id = :variant_id,
            updated_at = NOW()
        WHERE subscription_id = :sub_id
    ");
    $stmt->execute([
        'status' => $status,
        'plan' => $plan,
        'variant_id' => $variantId,
        'sub_id' => $subscriptionId
    ]);
    */
}

/**
 * Handle subscription cancellation or expiration
 */
function handleSubscriptionEnded($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['user_email'] ?? '';
    $subscriptionId = $attributes['id'] ?? '';
    $status = $attributes['status'] ?? '';
    
    logWebhook('❌ SUBSCRIPTION ENDED', [
        'email' => $email,
        'subscription_id' => $subscriptionId,
        'status' => $status
    ]);
    
    // TODO: Update database to revoke access
    /*
    $db = new PDO('mysql:host=localhost;dbname=grammar_mentor', 'username', 'password');
    $stmt = $db->prepare("
        UPDATE users 
        SET subscription_status = 'cancelled',
            updated_at = NOW()
        WHERE email = :email
    ");
    $stmt->execute(['email' => $email]);
    */
    
    // TODO: Send cancellation feedback email
    sendCancellationEmail($email);
}

/**
 * Handle successful payment
 */
function handlePaymentSuccess($data) {
    $attributes = $data['attributes'] ?? [];
    
    $subscriptionId = $attributes['id'] ?? '';
    $email = $attributes['user_email'] ?? '';
    
    logWebhook('💳 PAYMENT SUCCESS', [
        'subscription_id' => $subscriptionId,
        'email' => $email
    ]);
    
    // TODO: Update payment records, send receipt
}

/**
 * Handle failed payment
 */
function handlePaymentFailed($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['user_email'] ?? '';
    $subscriptionId = $attributes['id'] ?? '';
    
    logWebhook('⚠️ PAYMENT FAILED', [
        'email' => $email,
        'subscription_id' => $subscriptionId
    ]);
    
    // TODO: Send payment failure notification
    sendPaymentFailedEmail($email);
}

/**
 * Handle recovered payment (after failure)
 */
function handlePaymentRecovered($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['user_email'] ?? '';
    $subscriptionId = $attributes['id'] ?? '';
    
    logWebhook('✅ PAYMENT RECOVERED', [
        'email' => $email,
        'subscription_id' => $subscriptionId
    ]);
    
    // TODO: Re-activate subscription if it was cancelled
}

/**
 * Handle one-time order (typically for lifetime purchases)
 */
function handleOrderCreated($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['user_email'] ?? '';
    $orderId = $attributes['id'] ?? '';
    $variantId = $attributes['first_order_item']['variant_id'] ?? '';
    
    $plan = determinePlan($variantId);
    
    logWebhook('🛒 ORDER CREATED', [
        'email' => $email,
        'order_id' => $orderId,
        'plan' => $plan,
        'variant_id' => $variantId
    ]);
    
    // For lifetime purchases, grant permanent access
    if ($plan === 'lifetime') {
        // TODO: Update database with lifetime access
        /*
        $db = new PDO('mysql:host=localhost;dbname=grammar_mentor', 'username', 'password');
        $stmt = $db->prepare("
            INSERT INTO users (email, subscription_status, subscription_plan, variant_id, created_at)
            VALUES (:email, 'active', 'lifetime', :variant_id, NOW())
            ON DUPLICATE KEY UPDATE
                subscription_status = 'active',
                subscription_plan = 'lifetime',
                variant_id = :variant_id,
                updated_at = NOW()
        ");
        $stmt->execute([
            'email' => $email,
            'variant_id' => $variantId
        ]);
        */
        
        sendLifetimeWelcomeEmail($email);
    }
}

/**
 * Handle license key creation
 */
function handleLicenseKeyCreated($data) {
    $attributes = $data['attributes'] ?? [];
    
    $email = $attributes['customer_email'] ?? '';
    $licenseKey = $attributes['key'] ?? '';
    $variantId = $attributes['variant_id'] ?? '';
    
    $plan = determinePlan($variantId);
    
    logWebhook('🔑 LICENSE KEY CREATED', [
        'email' => $email,
        'plan' => $plan,
        'variant_id' => $variantId
    ]);
    
    // TODO: Store license key and send to customer
    sendLicenseKeyEmail($email, $licenseKey);
}

// ============================================
// EMAIL HELPER FUNCTIONS (Placeholders)
// ============================================

/**
 * Send welcome email to new subscriber
 */
function sendWelcomeEmail($email, $plan) {
    // TODO: Implement email sending
    // You can use PHP mail(), PHPMailer, or an email service like SendGrid
    
    logWebhook('📧 Would send welcome email', [
        'to' => $email,
        'plan' => $plan
    ]);
}

/**
 * Send lifetime plan welcome email
 */
function sendLifetimeWelcomeEmail($email) {
    logWebhook('📧 Would send lifetime welcome email', ['to' => $email]);
}

/**
 * Send cancellation email
 */
function sendCancellationEmail($email) {
    logWebhook('📧 Would send cancellation email', ['to' => $email]);
}

/**
 * Send payment failed notification
 */
function sendPaymentFailedEmail($email) {
    logWebhook('📧 Would send payment failed email', ['to' => $email]);
}

/**
 * Send license key email
 */
function sendLicenseKeyEmail($email, $licenseKey) {
    logWebhook('📧 Would send license key email', [
        'to' => $email,
        'license_key' => $licenseKey
    ]);
}