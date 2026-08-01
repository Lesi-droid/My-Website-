<?php
declare(strict_types=1);

// Pesapal sends GET params to IPN URL:
//   ?OrderNotificationType=xxx&OrderTrackingId=yyy&OrderMerchantReference=zzz
//
// We MUST respond with a specific JSON body to acknowledge receipt,
// otherwise Pesapal will keep retrying.

$tracking_id  = htmlspecialchars($_GET['OrderTrackingId']          ?? '', ENT_QUOTES, 'UTF-8');
$merchant_ref = htmlspecialchars($_GET['OrderMerchantReference']   ?? '', ENT_QUOTES, 'UTF-8');
$notif_type   = htmlspecialchars($_GET['OrderNotificationType']    ?? '', ENT_QUOTES, 'UTF-8');

// Reject malformed requests
if (!$tracking_id || !$notif_type) {
    http_response_code(400);
    exit;
}

// Load config
$config_path = null;
foreach ([2, 1, 3] as $levels) {
    $candidate = dirname(__DIR__, $levels) . '/pesapal-config.php';
    if (file_exists($candidate)) { $config_path = $candidate; break; }
}
if ($config_path === null || !file_exists($config_path)) {
    $config_path = '';
}
if ($config_path === '') {
    http_response_code(500);
    exit;
}
require_once $config_path;

$base = (PESAPAL_ENV === 'sandbox')
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3';

// --- Get bearer token ---
$auth_ch = curl_init($base . '/api/Auth/RequestToken');
curl_setopt_array($auth_ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'consumer_key'    => PESAPAL_CONSUMER_KEY,
        'consumer_secret' => PESAPAL_CONSUMER_SECRET,
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$auth_resp = json_decode((string) curl_exec($auth_ch), true) ?? [];
curl_close($auth_ch);

$payment_status = 'unknown';
$amount         = 0;

// --- Verify transaction status ---
if (!empty($auth_resp['token'])) {
    $status_ch = curl_init(
        $base . '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($tracking_id)
    );
    curl_setopt_array($status_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $auth_resp['token'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $status_resp = json_decode((string) curl_exec($status_ch), true) ?? [];
    curl_close($status_ch);

    $payment_status = strtolower($status_resp['payment_status_description'] ?? 'unknown');
    $amount         = $status_resp['amount'] ?? 0;
}

// --- Write to audit log ---
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0750, true);
}
$log_entry = implode(' | ', [
    date('Y-m-d H:i:s'),
    $tracking_id,
    $merchant_ref,
    $notif_type,
    $payment_status,
    'KES ' . $amount,
]) . PHP_EOL;
file_put_contents($log_dir . '/ipn.log', $log_entry, FILE_APPEND | LOCK_EX);

// --- Acknowledge IPN to Pesapal (required — exact format) ---
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'orderNotificationType'  => $notif_type,
    'orderTrackingId'        => $tracking_id,
    'orderMerchantReference' => $merchant_ref,
    'status'                 => 200,
]);
