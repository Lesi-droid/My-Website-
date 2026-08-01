<?php
declare(strict_types=1);

// ============================================================
//  Pesapal IPN Registration – ONE-TIME USE SCRIPT
//
//  INSTRUCTIONS:
//  1. Upload this file to public_html/pay/register-ipn.php
//  2. Open https://radiancecoaching.co.ke/pay/register-ipn.php
//     in your browser (while logged in via IP restriction below)
//  3. Copy the ipn_id printed on screen
//  4. Paste it into pesapal-config.php as PESAPAL_IPN_ID
//  5. DELETE this file from the server immediately after
// ============================================================

// --- Simple IP protection: only allow your own IP to run this ---
// Replace with your actual public IP (visit https://api.ipify.org to find it)
$allowed_ip = '154.117.136.118';

if ($_SERVER['REMOTE_ADDR'] !== $allowed_ip) {
    http_response_code(403);
    exit('Access denied.');
}

// Load config
$config_path = null;
foreach ([2, 1, 3] as $levels) {
    $candidate = dirname(__DIR__, $levels) . '/pesapal-config.php';
    if (file_exists($candidate)) { $config_path = $candidate; break; }
}
if (!$config_path) {
    exit('ERROR: pesapal-config.php not found. Upload it to: ' . dirname(__DIR__, 2));
}
require_once $config_path;

$base = (PESAPAL_ENV === 'sandbox')
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3';

// --- Step 1: Get bearer token ---
$auth = pesapal_post($base . '/api/Auth/RequestToken', [
    'consumer_key'    => PESAPAL_CONSUMER_KEY,
    'consumer_secret' => PESAPAL_CONSUMER_SECRET,
]);

if (empty($auth['token'])) {
    exit('ERROR: Authentication failed. Raw response: ' . print_r($auth, true));
}

// --- Step 2: Register IPN URL ---
$ipn = pesapal_post($base . '/api/URLSetup/RegisterIPN', [
    'url'          => 'https://radiancecoaching.co.ke/pay/ipn.php',
    'ipn_notification_type' => 'GET',   // Pesapal sends GET to your IPN URL
], $auth['token']);

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pesapal IPN Registration</title>
  <style>
    body { font-family: sans-serif; max-width: 600px; margin: 60px auto; padding: 0 20px; }
    .box { border: 2px solid; border-radius: 12px; padding: 24px; margin-top: 20px; }
    .success { border-color: #16a34a; background: #f0fdf4; }
    .error   { border-color: #dc2626; background: #fef2f2; }
    code { background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 1.1em; display: inline-block; margin: 10px 0; }
    .warn { color: #b45309; font-weight: bold; margin-top: 16px; }
  </style>
</head>
<body>
  <h2>Pesapal IPN Registration</h2>
  <p>Environment: <strong><?= htmlspecialchars(PESAPAL_ENV) ?></strong></p>

<?php if (!empty($ipn['ipn_id'])): ?>
  <div class="box success">
    <h3>✅ IPN Registered Successfully</h3>
    <p>Copy this <code>ipn_id</code> and paste it into <strong>pesapal-config.php</strong>:</p>
    <code><?= htmlspecialchars($ipn['ipn_id']) ?></code>
    <p>IPN URL: <strong><?= htmlspecialchars($ipn['url'] ?? 'https://radiancecoaching.co.ke/pay/ipn.php') ?></strong></p>
    <p class="warn">⚠️ DELETE this file from the server now — it must not remain accessible.</p>
  </div>
<?php else: ?>
  <div class="box error">
    <h3>❌ Registration Failed</h3>
    <pre><?= htmlspecialchars(print_r($ipn, true)) ?></pre>
    <p>Check that your Consumer Key/Secret are correct and that the IPN URL is publicly reachable.</p>
  </div>
<?php endif; ?>

</body>
</html>
<?php

// ---------------------------------------------------------------------------
function pesapal_post(string $url, array $payload, string $token = ''): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('[Pesapal RegisterIPN] cURL error: ' . curl_error($ch));
    }
    curl_close($ch);
    return json_decode((string) $response, true) ?? [];
}
