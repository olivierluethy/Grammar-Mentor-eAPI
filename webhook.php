<?php
/**
 * Grammar Mentor - Lemon Squeezy Webhook Handler
 * 
 * This file receives and processes webhooks from Lemon Squeezy
 * Place this file at: grammar-mentor.com/webhook.php
 */

require_once __DIR__ . '/config.php';
file_put_contents(__DIR__.'/PING.txt', "Webhook hit\n", FILE_APPEND);


function getDB() {
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Never display errors for webhooks
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook-errors.log');

// Webhook signing secret
define('WEBHOOK_SECRET', '__REDACTED_WEBHOOK_SECRET__');

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
    $variantId = (int) $variantId;   // wichtig: Integer erzwingen!

    switch ($variantId) {
        case 1303448:
            return 'lifetime';
        case 1303449:   // monthly
        case 1303454:   // yearly
            return 'pro';
        default:
            error_log("Webhook: unbekannte variant_id → $variantId");
            return 'free';
    }
}

// ============================================
// MAIN WEBHOOK HANDLER
// ============================================

// Get the raw POST body
$payload = file_get_contents('php://input');

// Get the signature from headers
$signature = $_SERVER['HTTP_X_SIGNATURE']
          ?? $_SERVER['HTTP_X_SIGNATURE']
          ?? '';


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
 * Handle new subscription creation (recurring plans: monthly / yearly)
 */
function handleSubscriptionCreated($data)
{
    $attributes = $data['attributes'] ?? [];

    $email           = trim($attributes['user_email'] ?? '');
    $subscriptionId  = $attributes['id'] ?? null;
    $variantId       = $attributes['variant_id'] ?? null;
    $status          = $attributes['status'] ?? 'active';
    $renewsAt        = $attributes['renews_at'] ?? null;       // ISO 8601 z.B. "2026-03-14T23:59:59.000000Z"
    $endsAt          = $attributes['ends_at'] ?? null;         // bei Kündigung / Ablauf
    $trialEndsAt     = $attributes['trial_ends_at'] ?? null;

    if (empty($email) || empty($subscriptionId)) {
        logWebhook('⚠️ subscription_created – fehlende Pflichtfelder', ['email' => $email, 'subscriptionId' => $subscriptionId]);
        return;
    }

    $plan = determinePlan($variantId);

    logWebhook('✅ SUBSCRIPTION CREATED', [
        'email'          => $email,
        'subscriptionId' => $subscriptionId,
        'variantId'      => $variantId,
        'plan'           => $plan,
        'status'         => $status,
        'renews_at'      => $renewsAt
    ]);

    $db = getDB();

    // Berechne valid_until – bei recurring Plänen meist renews_at als Obergrenze
    $validUntil = null;
    if ($renewsAt) {
        $dt = new DateTime($renewsAt);
        $validUntil = $dt->format('Y-m-d');
    }

    $stmt = $db->prepare("
        INSERT INTO subscriptions 
        (
            email, 
            plan, 
            status, 
            lemon_subscription_id, 
            valid_until,
            created_at
        )
        VALUES 
        (
            :email, 
            :plan, 
            :status, 
            :sub_id, 
            :valid_until,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            plan                   = :plan,
            status                 = :status,
            lemon_subscription_id  = :sub_id,
            valid_until            = :valid_until,
            updated_at             = NOW()
    ");

    $stmt->execute([
        'email'       => $email,
        'plan'        => $plan,
        'status'      => $status,
        'sub_id'      => $subscriptionId,
        'valid_until' => $validUntil
    ]);

    // Optional: Trial-Status loggen / behandeln
    if ($trialEndsAt && $status === 'active') {
        logWebhook('ℹ️  Trial subscription created', ['email' => $email, 'trial_ends_at' => $trialEndsAt]);
    }

    sendWelcomeEmail($email, $plan);
}


/**
 * Handle subscription updates
 */
function handleSubscriptionUpdated($data) {
    $attributes = $data['attributes'] ?? [];

    $email = $attributes['user_email'] ?? '';
    $subscriptionId = $attributes['id'] ?? '';
    $variantId = $attributes['variant_id'] ?? '';
    $status = $attributes['status'] ?? 'active';

    $plan = determinePlan($variantId);

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE subscriptions
        SET plan = :plan,
            status = :status,
            lemon_subscription_id = :sub_id,
            updated_at = NOW()
        WHERE email = :email
    ");

    $stmt->execute([
        'email' => $email,
        'plan' => $plan,
        'status' => $status,
        'sub_id' => $subscriptionId
    ]);
}

/**
 * Handle subscription cancellation or expiration
 */
function handleSubscriptionEnded($data) {
    $attributes = $data['attributes'] ?? [];
    $email = $attributes['user_email'] ?? '';

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE subscriptions
        SET status = 'cancelled'
        WHERE email = :email
    ");
    $stmt->execute(['email' => $email]);

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
/**
 * Handle one-time purchase (hauptsächlich Lifetime)
 */
function handleOrderCreated($data)
{
    $attributes = $data['attributes'] ?? [];

    $email     = strtolower(trim($attributes['user_email'] ?? ''));
    $orderId   = $data['id'] ?? null; // ← FIXED
    $variantId = $attributes['first_order_item']['variant_id'] ?? null;

    if (!$email || !$orderId || !$variantId) {
        logWebhook('❌ order_created missing fields', compact('email','orderId','variantId'));
        return;
    }

    $plan = determinePlan($variantId);

    logWebhook('🛒 ORDER CREATED FIXED', [
        'email' => $email,
        'orderId' => $orderId,
        'variantId' => $variantId,
        'plan' => $plan
    ]);

    $db = getDB();

    $validUntil = ($plan === 'lifetime') ? '9999-12-31' : null;

    $stmt = $db->prepare("
        INSERT INTO subscriptions 
        (email, plan, status, lemon_order_id, valid_until, created_at)
        VALUES (:email, :plan, 'active', :order_id, :valid_until, NOW())
        ON DUPLICATE KEY UPDATE
            plan = :plan,
            status = 'active',
            lemon_order_id = :order_id,
            valid_until = :valid_until,
            updated_at = NOW()
    ");

    $stmt->execute([
        'email' => $email,
        'plan' => $plan,
        'order_id' => $orderId,
        'valid_until' => $validUntil
    ]);
}



/**
 * Handle license key creation
 */
function handleLicenseKeyCreated($data) {
    $attributes = $data['attributes'] ?? [];

    $email = strtolower(trim($attributes['user_email'] ?? ''));
    $licenseKey = $attributes['key'] ?? '';
    $productId = $attributes['product_id'] ?? null;

    if (!$email) {
        logWebhook('❌ license_key_created missing email', $attributes);
        return;
    }

    logWebhook('🔑 LICENSE KEY FIXED', [
        'email' => $email,
        'product_id' => $productId
    ]);
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