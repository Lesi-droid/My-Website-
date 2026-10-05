<?php
declare(strict_types=1);

// ---- Settings ----------------------------------------------------------
// Public URL of this pay/ folder on the PHP host (no trailing slash)
const PAY_HOST_URL = 'https://radiancecoaching.co.ke/pay';

// Sites allowed to call this endpoint from the browser
const ALLOWED_ORIGINS = [
    'https://lesi-droid.github.io',
    'https://radiancecoaching.co.ke',
    'https://www.radiancecoaching.co.ke',
];

// Whitelist of payable services: name sent by the site => Cal.com namespace + price (KES)
// Keep prices in sync with data-price in index.html
const SERVICES = [
    'Life Coaching'                => ['ns' => 'life-coaching-session',          'amount' => 5000],
    'Grief & End of Life Coaching' => ['ns' => 'grief-and-end-of-life-coaching', 'amount' => 5000],
    'Betrayal Trauma Coaching'     => ['ns' => 'betrayal-trauma-coaching',       'amount' => 5000],
];

// ---- Headers / CORS ----------------------------------------------------
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Security: HTTPS only in production
$is_https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$is_https && ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost') {
    http_response_code(403);
    exit(json_encode(['error' => 'HTTPS required']));
}

// Only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// ---- Load config (lives ABOVE public_html) -----------------------------
$config_path = null;
foreach ([2, 1, 3] as $levels) {
    $candidate = dirname(__DIR__, $levels) . '/pesapal-config.php';
    if (file_exists($candidate)) {
        $config_path = $candidate;
        break;
    }
}
if ($config_path === null) {
    error_log('[Pesapal] Config not found. Searched near: ' . dirname(__DIR__, 2) . '/pesapal-config.php');
    http_response_code(500);
    exit(json_encode(['error' => 'Server configuration missing']));
}
require_once $config_path;

// ---- Validate & sanitise input -----------------------------------------
$first_name = trim(strip_tags($_POST['firstName'] ?? ''));
$last_name  = trim(strip_tags($_POST['lastName']  ?? ''));
$email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$phone      = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
$service    = trim(strip_tags($_POST['service']   ?? ''));

if (!$first_name || !$last_name || !$email) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing required fields']));
}

// Amount and namespace come from the server-side list, never from the client
if (!array_key_exists($service, SERVICES)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid or non-payable service selected']));
}
$amount = SERVICES[$service]['amount'];
$ns     = SERVICES[$service]['ns'];

// ---- Pesapal API base URL ----------------------------------------------
$base = (PESAPAL_ENV === 'sandbox')
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3';

// ---- Step 1: Get bearer token ------------------------------------------
$auth = pesapal_post($base . '/api/Auth/RequestToken', [
    'consumer_key'    => PESAPAL_CONSUMER_KEY,
    'consumer_secret' => PESAPAL_CONSUMER_SECRET,
]);

if (empty($auth['token'])) {
    error_log('[Pesapal] Auth failed: ' . json_encode($auth));
    http_response_code(502);
    exit(json_encode(['error' => 'Could not authenticate with payment provider']));
}
$token = $auth['token'];

// ---- Step 2: Submit order request --------------------------------------
// Order ID format: RC-<cal-namespace>-<12 hex>  (max 46 chars; Pesapal limit is 50)
// callback.php reads the namespace from this to send the customer to the right calendar.
$order_id = 'RC-' . $ns . '-' . strtoupper(bin2hex(random_bytes(6)));

$payload = [
    'id'           => $order_id,
    'currency'     => 'KES',
    'amount'       => $amount,
    'description'  => 'Radiance Coaching - ' . $service,
    'callback_url' => PAY_HOST_URL . '/callback.php',
    'billing_address' => [
        'email_address' => $email,
        'phone_number'  => $phone,
        'first_name'    => $first_name,
        'last_name'     => $last_name,
    ],
];

// Pesapal v3 expects a registered IPN id. Run register-ipn.php once and put the id in pesapal-config.php.
if (defined('PESAPAL_IPN_ID') && PESAPAL_IPN_ID !== '') {
    $payload['notification_id'] = PESAPAL_IPN_ID;
}

$order = pesapal_post($base . '/api/Transactions/SubmitOrderRequest', $payload, $token);

if (empty($order['redirect_url'])) {
    $pesapal_error = $order['error']['message'] ?? ($order['message'] ?? json_encode($order));
    error_log('[Pesapal] Order failed: ' . json_encode($order));
    http_response_code(502);
    exit(json_encode(['error' => 'Could not create payment order', 'detail' => $pesapal_error]));
}

exit(json_encode(['redirect_url' => $order['redirect_url']]));

// ---------------------------------------------------------------------------
// cURL helper: all Pesapal v3 calls here use POST with JSON
// ---------------------------------------------------------------------------
function pesapal_post(string $url, array $payload, string $token = ''): array
{
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('[Pesapal] cURL error: ' . curl_error($ch));
    }
    curl_close($ch);

    return json_decode((string) $response, true) ?? [];
}
