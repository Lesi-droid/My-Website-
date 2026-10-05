<?php
declare(strict_types=1);

// Pesapal calls this URL with:
//   ?OrderNotificationType=xxx&OrderTrackingId=yyy&OrderMerchantReference=zzz
// We verify the status with Pesapal, log it, and reply with the exact JSON Pesapal expects.

function clean(string $v): string
{
    return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $v) ?? '', 0, 100);
}

$tracking_id  = clean((string) ($_GET['OrderTrackingId']        ?? ''));
$merchant_ref = clean((string) ($_GET['OrderMerchantReference'] ?? ''));
$notif_type   = clean((string) ($_GET['OrderNotificationType']  ?? ''));

// Reject malformed requests
if ($tracking_id === '' || $notif_type === '') {
    http_response_code(400);
    exit;
}

// Load config (lives ABOVE public_html)
$config_path = null;
foreach ([2, 1, 3] as $levels) {
    $candidate = dirname(__DIR__, $levels) . '/pesapal-config.php';
    if (file_exists($candidate)) { $config_path = $candidate; break; }
}
if ($config_path === null) {
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

// --- Verify transaction status with Pesapal ---
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

    $payment_status = clean(strtolower((string) ($status_resp['payment_status_description'] ?? 'unknown')));
    $amount         = is_numeric($status_resp['amount'] ?? null) ? $status_resp['amount'] : 0;
}

// --- Audit log, stored ABOVE public_html next to pesapal-config.php (not web-accessible) ---
$log_dir = dirname($config_path) . '/pesapal-logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0750, true);
}
$log_entry = implode(' | ', [
    date('Y-m-d H:i:s'),
    $tracking_id,
    $merchant_ref,
    $notif_type,
    $payment_status,
    'KES ' . $amount,
]) . PHP_EOL;
@file_put_contents($log_dir . '/ipn.log', $log_entry, FILE_APPEND | LOCK_EX);

// --- Acknowledge IPN to Pesapal (required, exact format) ---
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'orderNotificationType'  => $notif_type,
    'orderTrackingId'        => $tracking_id,
    'orderMerchantReference' => $merchant_ref,
    'status'                 => 200,
]);
