<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

/*
|--------------------------------------------------------------------------
| ESP8266 DHT22 + HL-01 Flame + MQ-135 Air Quality Logger Endpoint
|--------------------------------------------------------------------------
| Save as: /var/www/html/noctms/log_sensor.php
| ESP8266 POST URL example:
| http://SERVER_IP/noctms/log_sensor.php
*/

const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '1122';
const DB_NAME = 'tempLogger';

const SENSOR_POST_API_KEY = '8fK2xP9mQ4zL2026_TempSecure';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respondJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function getDb(): mysqli {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        respondJson(500, ['ok' => false, 'error' => 'Database connection failed']);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : ['cnt' => 0];
    $stmt->close();

    return (int)($row['cnt'] ?? 0) > 0;
}

function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    if (!columnExists($conn, $table, $column)) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

function ensureSensorLogsTable(mysqli $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS sensor_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device VARCHAR(100) NOT NULL DEFAULT 'ESP8266',
            temp_c DECIMAL(6,2) DEFAULT NULL,
            humidity DECIMAL(6,2) DEFAULT NULL,
            heat_index_c DECIMAL(6,2) DEFAULT NULL,
            alarm TINYINT(1) NOT NULL DEFAULT 0,
            MQ135_raw INT DEFAULT NULL,
            smoke_level VARCHAR(20) NOT NULL DEFAULT 'GOOD',
            smoke_level_value TINYINT NOT NULL DEFAULT 0,
            flame_detected TINYINT(1) NOT NULL DEFAULT 0,
            safety_alarm TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sensor_logs_created_at (created_at),
            INDEX idx_sensor_logs_safety (safety_alarm, flame_detected, smoke_level_value)
        )
    ");

    addColumnIfMissing($conn, 'sensor_logs', 'MQ135_raw', "MQ135_raw INT DEFAULT NULL AFTER alarm");
    addColumnIfMissing($conn, 'sensor_logs', 'smoke_level', "smoke_level VARCHAR(20) NOT NULL DEFAULT 'GOOD' AFTER MQ135_raw");
    addColumnIfMissing($conn, 'sensor_logs', 'smoke_level_value', "smoke_level_value TINYINT NOT NULL DEFAULT 0 AFTER smoke_level");
    addColumnIfMissing($conn, 'sensor_logs', 'flame_detected', "flame_detected TINYINT(1) NOT NULL DEFAULT 0 AFTER smoke_level_value");
    addColumnIfMissing($conn, 'sensor_logs', 'safety_alarm', "safety_alarm TINYINT(1) NOT NULL DEFAULT 0 AFTER flame_detected");
}

function postString(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return trim((string)$value);
}

function postFloat(string $key): ?float {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }
    $value = (string)$_POST[$key];
    return is_numeric($value) ? (float)$value : null;
}

function postInt(string $key, int $default = 0): int {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return $default;
    }
    $value = (string)$_POST[$key];
    return is_numeric($value) ? (int)$value : $default;
}

function clampInt(int $value, int $min, int $max): int {
    return max($min, min($max, $value));
}

// Updated for 5-tier AQI mapping
function smokeTextFromValue(int $value): string {
    if ($value >= 4) return 'HAZARDOUS';
    if ($value === 3) return 'UNHEALTHY';
    if ($value === 2) return 'POOR';
    if ($value === 1) return 'MODERATE';
    return 'GOOD';
}

// Updated for 5-tier AQI mapping
function smokeValueFromText(string $text): int {
    $text = strtoupper(trim($text));
    if ($text === 'HAZARDOUS') return 4;
    if ($text === 'UNHEALTHY') return 3;
    if ($text === 'POOR')      return 2;
    if ($text === 'MODERATE')  return 1;
    return 0; // GOOD
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, ['ok' => false, 'error' => 'POST method required']);
}

$apiKey = postString('api_key');
if (!hash_equals(SENSOR_POST_API_KEY, $apiKey)) {
    respondJson(403, ['ok' => false, 'error' => 'Invalid API key']);
}

$conn = getDb();
ensureSensorLogsTable($conn);

$device = substr(postString('device', 'ESP8266_DHT22_HL01_MQ135'), 0, 100);

$tempC = postFloat('temp_c');
$humidity = postFloat('humidity');
$heatIndexC = postFloat('heat_index_c');
$alarm = postInt('alarm', 0) ? 1 : 0;

$MQ135Raw = clampInt(postInt('MQ135_raw', 0), 0, 1023);

$smokeLevelText = strtoupper(postString('smoke_level', ''));
$smokeLevelValue = isset($_POST['smoke_level_value'])
    ? clampInt(postInt('smoke_level_value', 0), 0, 4) // Clamped to 4 for HAZARDOUS
    : smokeValueFromText($smokeLevelText);

if ($smokeLevelText === '') {
    $smokeLevelText = smokeTextFromValue($smokeLevelValue);
}

// Updated validation array to match the new 5 levels
if (!in_array($smokeLevelText, ['GOOD', 'MODERATE', 'POOR', 'UNHEALTHY', 'HAZARDOUS'], true)) {
    $smokeLevelText = smokeTextFromValue($smokeLevelValue);
}

$flameDetected = postInt('flame_detected', 0) ? 1 : 0;
$safetyAlarm = isset($_POST['safety_alarm'])
    ? (postInt('safety_alarm', 0) ? 1 : 0)
    : (($flameDetected === 1 || $smokeLevelValue > 0) ? 1 : 0);

$stmt = $conn->prepare("
    INSERT INTO sensor_logs (
        device,
        temp_c,
        humidity,
        heat_index_c,
        alarm,
        MQ135_raw,
        smoke_level,
        smoke_level_value,
        flame_detected,
        safety_alarm
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    respondJson(500, ['ok' => false, 'error' => 'Prepare failed']);
}

$stmt->bind_param(
    'sdddiisiii',
    $device,
    $tempC,
    $humidity,
    $heatIndexC,
    $alarm,
    $MQ135Raw,
    $smokeLevelText,
    $smokeLevelValue,
    $flameDetected,
    $safetyAlarm
);

$ok = $stmt->execute();
$insertId = $stmt->insert_id;
$error = $stmt->error;
$stmt->close();

if (!$ok) {
    respondJson(500, ['ok' => false, 'error' => 'Insert failed', 'detail' => $error]);
}

respondJson(200, [
    'ok' => true,
    'id' => $insertId,
    'device' => $device,
    'temp_c' => $tempC,
    'humidity' => $humidity,
    'heat_index_c' => $heatIndexC,
    'alarm' => $alarm,
    'MQ135_raw' => $MQ135Raw,
    'smoke_level' => $smokeLevelText,
    'smoke_level_value' => $smokeLevelValue,
    'flame_detected' => $flameDetected,
    'safety_alarm' => $safetyAlarm
]);
