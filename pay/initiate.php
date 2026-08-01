<?php
declare(strict_types=1);

// Security: HTTPS only in production
if (isset($_SERVER['HTTPS']) === false && ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost') {
    http_response_code(403);
    exit(json_encode(['error' => 'HTTPS required']));
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// --- Load config (lives ABOVE public_html) ---
// Try multiple levels up to handle different cPanel directory structures
$config_path = null;
foreach ([2, 1, 3] as $levels) {
    $candidate = dirname(__DIR__, $levels) . '/pesapal-config.php';
    if (file_exists($candidate)) {
        $config_path = $candidate;
        break;
    }
}
if ($config_path === null) {
    http_response_code(500);
    $searched = dirname(__DIR__, 2) . '/pesapal-config.php';
    error_log('[Pesapal] Config not found. Searched near: ' . $searched);
    exit(json_encode(['error' => 'Server configuration missing', 'hint' => 'Upload pesapal-config.php to: ' . dirname(__DIR__, 2)]));
}
require_once $config_path;

// --- Whitelist of valid services and their prices (KES) ---
// Update these prices to match your actual coaching rates.
$service_prices = [
    'Life Coaching'              => 500,
    'Grief & End of Life Coaching' => 500,
    'Betrayal Trauma Coaching'   => 500,
];

// --- Validate & sanitise input ---
$first_name = trim(strip_tags($_POST['firstName'] ?? ''));
$last_name  = trim(strip_tags($_POST['lastName']  ?? ''));
$email      = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$phone      = preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? '');
$service    = trim(strip_tags($_POST['service']   ?? ''));

if (!$first_name || !$last_name || !$email) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing required fields']));
}

// Enforce amount from server-side price list — never trust client-submitted amount
if (!array_key_exists($service, $service_prices)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid or non-payable service selected']));
}
$amount = $service_prices[$service];

// --- Pesapal API base URL ---
$base = (PESAPAL_ENV === 'sandbox')
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3';

// --- Step 1: Get bearer token ---
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

// --- Step 2: Submit order request ---
$order_id = 'RC-' . strtoupper(bin2hex(random_bytes(6)));

$payload = [
    'id'           => $order_id,
    'currency'     => 'KES',
    'amount'       => $amount,
    'description'  => 'Radiance Coaching - ' . $service,
    'callback_url' => 'https://radiancecoaching.co.ke/pay/callback.php',
    'billing_address' => [
        'email_address' => $email,
        'phone_number'  => $phone,
        'first_name'    => $first_name,
        'last_name'     => $last_name,
    ],
];

// Only include notification_id if one is configured
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
// cURL helper — all Pesapal v3 calls use POST with JSON
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
