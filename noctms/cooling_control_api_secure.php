<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '1122';
const DB_NAME = 'tempLogger';
const CONTROL_API_KEY = '945dxP9mQ4zL2026_cooling';
const FORCE_HTTPS = false;
const ALLOWED_HOSTS = []; // set to ['dashboard.example.com'] on production

function getRequestHost(): string {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $host = strtolower(trim($host));
    if (($pos = strpos($host, ':')) !== false) {
        $host = substr($host, 0, $pos);
    }
    return $host;
}

function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

function reject(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function enforceAllowedHosts(): void {
    if (empty(ALLOWED_HOSTS)) {
        return;
    }
    if (!in_array(getRequestHost(), ALLOWED_HOSTS, true)) {
        reject('Forbidden host', 403);
    }
}

function forceHttpsIfNeeded(): void {
    if (FORCE_HTTPS && !isHttps()) {
        reject('HTTPS required', 403);
    }
}

function sendHeaders(): void {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function getDb(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        reject('Database connection failed', 500);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function ensureCoolingTable(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS cooling_control (
            id TINYINT PRIMARY KEY,
            mode ENUM('AUTO','MANUAL') NOT NULL DEFAULT 'AUTO',
            manual_state TINYINT(1) NOT NULL DEFAULT 0,
            relay_state TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $conn->query("
        INSERT INTO cooling_control (id, mode, manual_state, relay_state)
        VALUES (1, 'AUTO', 0, 0)
        ON DUPLICATE KEY UPDATE id = id
    ");
}

function readApiKey(): string {
    $headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (is_string($headerKey) && $headerKey !== '') {
        return $headerKey;
    }
    $postKey = $_POST['api_key'] ?? '';
    if (is_string($postKey) && $postKey !== '') {
        return $postKey;
    }
    $getKey = $_GET['api_key'] ?? '';
    return is_string($getKey) ? $getKey : '';
}

function requireApiKey(): void {
    $provided = readApiKey();
    if ($provided === '' || !hash_equals(CONTROL_API_KEY, $provided)) {
        reject('Invalid API key', 401);
    }
}

enforceAllowedHosts();
forceHttpsIfNeeded();
sendHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    reject('Method not allowed', 405);
}

requireApiKey();
$conn = getDb();
ensureCoolingTable($conn);

if ($method === 'POST') {
    if (!isset($_POST['relay_state'])) {
        reject('Missing relay_state', 400);
    }
    $relayState = (int)$_POST['relay_state'];
    $relayState = $relayState ? 1 : 0;

    $stmt = $conn->prepare("UPDATE cooling_control SET relay_state = ?, updated_at = NOW() WHERE id = 1");
    if (!$stmt) {
        reject('Failed to prepare update', 500);
    }
    $stmt->bind_param('i', $relayState);
    $stmt->execute();
    $stmt->close();
}

$result = $conn->query("SELECT mode, manual_state, relay_state, updated_at FROM cooling_control WHERE id = 1 LIMIT 1");
$control = $result ? $result->fetch_assoc() : null;

echo json_encode([
    'ok' => true,
    'mode' => $control['mode'] ?? 'AUTO',
    'manual_state' => (int)($control['manual_state'] ?? 0),
    'relay_state' => (int)($control['relay_state'] ?? 0),
    'updated_at' => $control['updated_at'] ?? null
]);
