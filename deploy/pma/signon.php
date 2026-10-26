<?php
/**
 * phpMyAdmin Single Sign-On (SSO) Handler for HPanel.
 *
 * Redeems single-use tokens against the HPanel internal API,
 * establishes a phpMyAdmin signon session, and redirects cleanly.
 */

// Load optional configuration file or fallback to environment variables
$configFile = __DIR__ . '/signon_config.php';
$config = file_exists($configFile) ? require $configFile : [];

$sharedSecret = $config['shared_secret'] ?? getenv('PANEL_PMA_SHARED_SECRET') ?? '';
$panelUrl = rtrim($config['panel_url'] ?? getenv('HPANEL_URL') ?? 'https://hpanel.nexorafox.in', '/');
$internalRedeemUrl = $config['internal_redeem_url'] ?? 'http://127.0.0.1/internal/pma/redeem';
$panelHostHeader = $config['panel_host_header'] ?? parse_url($panelUrl, PHP_URL_HOST) ?? 'hpanel.nexorafox.in';

$rawToken = $_GET['token'] ?? '';

// Validate token format (must be 64-character lowercase hex string)
if (! is_string($rawToken) || ! preg_match('/^[a-f0-9]{64}$/i', $rawToken) || empty($sharedSecret)) {
    header('Location: ' . $panelUrl . '/accounts?error=' . urlencode('Invalid or expired SSO token.'));
    exit;
}

$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . $rawToken, $sharedSecret);

// Call internal redeem endpoint over local loopback
$ch = curl_init($internalRedeemUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['token' => $rawToken]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Timestamp: ' . $timestamp,
        'X-Signature: ' . $signature,
        'Host: ' . $panelHostHeader,
    ],
]);

$responseBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || ! $responseBody) {
    header('Location: ' . $panelUrl . '/accounts?error=' . urlencode('Single sign-on verification failed.'));
    exit;
}

$data = json_decode($responseBody, true);
if (! is_array($data) || empty($data['user']) || empty($data['password'])) {
    header('Location: ' . $panelUrl . '/accounts?error=' . urlencode('Invalid response from authentication service.'));
    exit;
}

// Configure secure phpMyAdmin signon session
session_name('hpanel_pma');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();
$_SESSION['PMA_single_signon_user'] = (string) $data['user'];
$_SESSION['PMA_single_signon_password'] = (string) $data['password'];
$_SESSION['PMA_single_signon_host'] = '127.0.0.1';

// Redirect to phpMyAdmin with optional target database
$targetUrl = 'index.php';
if (! empty($data['database'])) {
    $targetUrl .= '?db=' . urlencode((string) $data['database']);
}

header('Location: ' . $targetUrl);
exit;
