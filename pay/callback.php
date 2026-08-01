<?php
declare(strict_types=1);

// Pesapal redirects here with these GET params after payment:
//   ?OrderTrackingId=xxx&OrderMerchantReference=RC-yyy&OrderNotificationType=zzz

$tracking_id  = htmlspecialchars($_GET['OrderTrackingId']          ?? '', ENT_QUOTES, 'UTF-8');
$merchant_ref = htmlspecialchars($_GET['OrderMerchantReference']   ?? '', ENT_QUOTES, 'UTF-8');

$paid         = false;
$failed       = false;
$status_desc  = 'Processing';

// Verify actual payment status directly from Pesapal API (do not trust URL params alone)
if ($tracking_id !== '') {
    $config_path = null;
    foreach ([2, 1, 3] as $levels) {
        $candidate = dirname(__DIR__, $levels) . '/pesapal-config.php';
        if (file_exists($candidate)) { $config_path = $candidate; break; }
    }
    if ($config_path && file_exists($config_path)) {
        require_once $config_path;

        $base = (PESAPAL_ENV === 'sandbox')
            ? 'https://cybqa.pesapal.com/pesapalv3'
            : 'https://pay.pesapal.com/v3';

        // Get bearer token
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

            $payment_status = strtolower($status_resp['payment_status_description'] ?? '');
            $status_desc    = $status_resp['payment_status_description'] ?? 'Processing';

            if ($payment_status === 'completed') {
                $paid = true;
            } elseif (in_array($payment_status, ['failed', 'invalid'], true)) {
                $failed = true;
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $paid ? 'Payment Confirmed' : ($failed ? 'Payment Failed' : 'Payment Processing') ?> – Radiance Coaching</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
  <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-6">
  <div class="bg-white rounded-3xl shadow-xl max-w-md w-full p-10 text-center space-y-6">

    <?php if ($paid): ?>
      <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mx-auto">
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h1 class="text-2xl font-medium text-slate-900">Payment Confirmed! 🌿</h1>
      <p class="text-slate-600 leading-relaxed">
        Thank you for booking with Radiance Coaching. Anne will be in touch within 24 hours to schedule your session.
      </p>
      <?php if ($merchant_ref): ?>
        <p class="text-sm text-slate-400">Reference: <?= $merchant_ref ?></p>
      <?php endif; ?>

    <?php elseif ($failed): ?>
      <div class="inline-flex items-center justify-center w-20 h-20 bg-red-100 rounded-full mx-auto">
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </div>
      <h1 class="text-2xl font-medium text-slate-900">Payment Unsuccessful</h1>
      <p class="text-slate-600 leading-relaxed">
        Your payment could not be processed. Please try again or reach out to us directly.
      </p>
      <a href="https://radiancecoaching.co.ke/#contact"
         class="inline-block px-8 py-3 rounded-full border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
        Try Again
      </a>

    <?php else: ?>
      <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mx-auto">
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#090cab" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <h1 class="text-2xl font-medium text-slate-900">Processing Payment…</h1>
      <p class="text-slate-600 leading-relaxed">
        Your payment is being confirmed. You'll receive an email once it's complete. This can take a few minutes.
      </p>
    <?php endif; ?>

    <a href="https://radiancecoaching.co.ke"
       class="inline-block px-8 py-3 rounded-full bg-gradient-to-r from-[#090cab] to-[#1e40af] text-white font-medium hover:opacity-90 transition-opacity">
      Return to Radiance Coaching
    </a>
  </div>
</body>
</html>
