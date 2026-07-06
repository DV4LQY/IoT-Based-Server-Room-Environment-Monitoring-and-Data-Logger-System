<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

/*
|--------------------------------------------------------------------------
| Deployment configuration
|--------------------------------------------------------------------------
| Edit these values before public deployment.
*/
const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '1122';
const DB_NAME = 'tempLogger';

const FORCE_HTTPS = false; // set to true on live HTTPS domain
const SESSION_IDLE_TIMEOUT = 900;       // 15 minutes
const SESSION_ABSOLUTE_TIMEOUT = 28800; // 8 hours
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_BLOCK_SECONDS = 900;        // 15 minutes
const PASSWORD_MIN_LENGTH = 10;

/*
| Leave empty to allow any host.
| For production, set your real domain(s), e.g. ['dashboard.example.com']
*/
const ALLOWED_HOSTS = [];



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

function enforceAllowedHosts(): void {
    if (empty(ALLOWED_HOSTS)) {
        return;
    }
    $host = getRequestHost();
    if (!in_array($host, ALLOWED_HOSTS, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden host.';
        exit;
    }
}

function forceHttpsIfNeeded(): void {
    if (!FORCE_HTTPS || isHttps()) {
        return;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

function sendSecurityHeaders(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function startSecureSession(): void {
    $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', isHttps() ? '1' : '0');

    session_name('TEMPDASHSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

function getDb(): mysqli {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Database connection failed.';
        exit;
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
    /*
     * Main ESP8266 logging table.
     * The extra columns support HL-01 flame detection and MQ-135 analog smoke values.
     */
    $conn->query("
        CREATE TABLE IF NOT EXISTS sensor_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device VARCHAR(100) NOT NULL DEFAULT 'ESP8266',
            temp_c DECIMAL(6,2) DEFAULT NULL,
            humidity DECIMAL(6,2) DEFAULT NULL,
            heat_index_c DECIMAL(6,2) DEFAULT NULL,
            alarm TINYINT(1) NOT NULL DEFAULT 0,
            MQ135_raw INT DEFAULT NULL,
            smoke_level VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
            smoke_level_value TINYINT NOT NULL DEFAULT 0,
            flame_detected TINYINT(1) NOT NULL DEFAULT 0,
            safety_alarm TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sensor_logs_created_at (created_at),
            INDEX idx_sensor_logs_safety (safety_alarm, flame_detected, smoke_level_value)
        )
    ");

    /*
     * Migration support for existing tempLogger.sensor_logs tables.
     * These ALTER statements run only when the column is missing.
     */
    addColumnIfMissing($conn, 'sensor_logs', 'MQ135_raw', "MQ135_raw INT DEFAULT NULL AFTER alarm");
    addColumnIfMissing($conn, 'sensor_logs', 'smoke_level', "smoke_level VARCHAR(20) NOT NULL DEFAULT 'NORMAL' AFTER MQ135_raw");
    addColumnIfMissing($conn, 'sensor_logs', 'smoke_level_value', "smoke_level_value TINYINT NOT NULL DEFAULT 0 AFTER smoke_level");
    addColumnIfMissing($conn, 'sensor_logs', 'flame_detected', "flame_detected TINYINT(1) NOT NULL DEFAULT 0 AFTER smoke_level_value");
    addColumnIfMissing($conn, 'sensor_logs', 'safety_alarm', "safety_alarm TINYINT(1) NOT NULL DEFAULT 0 AFTER flame_detected");
}

function ensureTables(mysqli $conn): void {
    ensureSensorLogsTable($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS dashboard_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) DEFAULT NULL,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            last_login_at DATETIME DEFAULT NULL,
            last_password_change_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS dashboard_login_attempts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(50) DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_ip_time (ip_address, attempted_at)
        )
    ");

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

function getClientIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function cleanupOldLoginAttempts(mysqli $conn): void {
    $stmt = $conn->prepare("DELETE FROM dashboard_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }
}

function failedLoginCount(mysqli $conn, string $ip): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS attempts FROM dashboard_login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL ? SECOND)");
    if (!$stmt) {
        return 0;
    }
    $window = LOGIN_BLOCK_SECONDS;
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : ['attempts' => 0];
    $stmt->close();
    return (int)($row['attempts'] ?? 0);
}

function recordLoginAttempt(mysqli $conn, string $ip, ?string $username, bool $success): void {
    $stmt = $conn->prepare("INSERT INTO dashboard_login_attempts (ip_address, username, success) VALUES (?, ?, ?)");
    if ($stmt) {
        $flag = $success ? 1 : 0;
        $stmt->bind_param('ssi', $ip, $username, $flag);
        $stmt->execute();
        $stmt->close();
    }
}

function clearLoginAttempts(mysqli $conn, string $ip): void {
    $stmt = $conn->prepare("DELETE FROM dashboard_login_attempts WHERE ip_address = ?");
    if ($stmt) {
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function requireCsrf(): void {
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    if (!is_string($posted) || !is_string($session) || $posted === '' || !hash_equals($session, $posted)) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'CSRF validation failed.';
        exit;
    }
}

function isLoggedIn(): bool {
    return isset($_SESSION['dashboard_user']) && is_array($_SESSION['dashboard_user']);
}

function logoutAndRedirect(string $reason = ''): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $target = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    if ($target === '' || $target === false) {
        $target = $_SERVER['PHP_SELF'] ?? '/';
    }
    if ($reason !== '') {
        $target .= '?reason=' . urlencode($reason);
    }
    header('Location: ' . $target);
    exit;
}

function enforceSessionPolicy(): void {
    if (!isLoggedIn()) {
        return;
    }

    $now = time();
    $createdAt = (int)($_SESSION['created_at'] ?? $now);
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
    $lastRegen = (int)($_SESSION['last_regenerated_at'] ?? 0);

    if (($now - $lastActivity) > SESSION_IDLE_TIMEOUT) {
        logoutAndRedirect('timeout');
    }

    if (($now - $createdAt) > SESSION_ABSOLUTE_TIMEOUT) {
        logoutAndRedirect('session_expired');
    }

    $_SESSION['last_activity'] = $now;

    if ($lastRegen === 0 || ($now - $lastRegen) > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated_at'] = $now;
    }
}

function fetchCoolingControl(mysqli $conn): ?array {
    $result = $conn->query("SELECT id, mode, manual_state, relay_state, updated_at FROM cooling_control WHERE id = 1 LIMIT 1");
    return $result ? $result->fetch_assoc() : null;
}

function enforceManualOffOnAutoAlarm(mysqli $conn, ?array $latest): void {
    /*
     * Safety rule:
     * If the dashboard is in AUTO mode and the alarm is active,
     * the manual trigger request must be cleared. This does NOT stop
     * the automatic relay action; it only prevents a stale manual ON request.
     */
    if (!$latest || (int)($latest['alarm'] ?? 0) !== 1) {
        return;
    }

    $stmt = $conn->prepare("UPDATE cooling_control SET manual_state = 0, updated_at = NOW() WHERE id = 1 AND mode = 'AUTO' AND manual_state <> 0");
    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }
}

function updateCoolingControl(mysqli $conn, string $mode, int $manualState): ?array {
    $mode = strtoupper($mode) === 'MANUAL' ? 'MANUAL' : 'AUTO';
    $manualState = $manualState ? 1 : 0;

    if ($mode === 'AUTO') {
        $manualState = 0;
    }

    $stmt = $conn->prepare("UPDATE cooling_control SET mode = ?, manual_state = ?, updated_at = NOW() WHERE id = 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('si', $mode, $manualState);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? fetchCoolingControl($conn) : null;
}

function normalizeDateTimeFilter(string $value, bool $isEnd = false): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = str_replace('T', ' ', $value);

    // If only a date is supplied, keep full-day behavior.
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        return $value . ($isEnd ? ' 23:59:59' : ' 00:00:00');
    }

    // datetime-local normally sends YYYY-MM-DDTHH:MM.
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}$/', $value)) {
        return $value . ($isEnd ? ':59' : ':00');
    }

    return $value;
}

function buildWhereClause(array &$params, string &$types, string $startDate = '', string $endDate = ''): string {
    $where = [];

    $startDateTime = normalizeDateTimeFilter($startDate, false);
    $endDateTime = normalizeDateTimeFilter($endDate, true);

    if ($startDateTime !== '') {
        $where[] = "created_at >= ?";
        $params[] = $startDateTime;
        $types .= 's';
    }

    if ($endDateTime !== '') {
        $where[] = "created_at <= ?";
        $params[] = $endDateTime;
        $types .= 's';
    }

    return $where ? ('WHERE ' . implode(' AND ', $where)) : '';
}


function fetchDashboardData(mysqli $conn, string $startDate = '', string $endDate = ''): array {
    $params = [];
    $types = '';
    $whereSql = buildWhereClause($params, $types, $startDate, $endDate);

    /*
     * Chart data now follows only the selected Start Date and End Date.
     * No Chart Points dropdown / manual limit is used.
     *
     * Safety fallback:
     * If no date filter is selected, show latest 200 records to avoid loading
     * the entire database on first dashboard load.
     */
    $limitSql = '';
    if ($startDate === '' && $endDate === '') {
        $limitSql = 'LIMIT 200';
    }

    $sql = "SELECT id, device, temp_c, humidity, heat_index_c, alarm,
                   MQ135_raw, smoke_level, smoke_level_value, flame_detected, safety_alarm,
                   created_at
            FROM sensor_logs
            $whereSql
            ORDER BY id DESC
            $limitSql";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'latest' => null,
            'labels' => [],
            'tempData' => [],
            'humidityData' => [],
            'heatIndexData' => [],
            'alarmData' => [],
            'MQ135Data' => [],
            'smokeLevelData' => [],
            'flameData' => [],
            'safetyAlarmData' => [],
            'rows' => [],
            'rangeLabel' => 'No records available',
            'pointCount' => 0
        ];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    $latest = $rows[0] ?? null;
    enforceManualOffOnAutoAlarm($conn, $latest);
    $rowsChronological = array_reverse($rows);

    $rangeLabel = 'No records available';
    if ($rowsChronological) {
        $firstRow = $rowsChronological[0];
        $lastRow = $rowsChronological[count($rowsChronological) - 1];
        $rangeLabel = date('Y-m-d H:i:s', strtotime((string)$firstRow['created_at'])) .
            ' to ' .
            date('Y-m-d H:i:s', strtotime((string)$lastRow['created_at']));
    }

    $labels = [];
    $tempData = [];
    $humidityData = [];
    $heatIndexData = [];
    $alarmData = [];
    $MQ135Data = [];
    $smokeLevelData = [];
    $flameData = [];
    $safetyAlarmData = [];

    foreach ($rowsChronological as $row) {
        $labels[] = date('Y-m-d H:i:s', strtotime((string)$row['created_at']));
        $tempData[] = is_numeric($row['temp_c'] ?? null) ? (float)$row['temp_c'] : null;
        $humidityData[] = is_numeric($row['humidity'] ?? null) ? (float)$row['humidity'] : null;
        $heatIndexData[] = is_numeric($row['heat_index_c'] ?? null) ? (float)$row['heat_index_c'] : null;
        $alarmData[] = (int)($row['alarm'] ?? 0);
        $MQ135Data[] = is_numeric($row['MQ135_raw'] ?? null) ? (int)$row['MQ135_raw'] : null;
        $smokeLevelData[] = (int)($row['smoke_level_value'] ?? 0);
        $flameData[] = (int)($row['flame_detected'] ?? 0);
        $safetyAlarmData[] = (int)($row['safety_alarm'] ?? 0);
    }

    return [
        'latest' => $latest,
        'labels' => $labels,
        'tempData' => $tempData,
        'humidityData' => $humidityData,
        'heatIndexData' => $heatIndexData,
        'alarmData' => $alarmData,
        'MQ135Data' => $MQ135Data,
        'smokeLevelData' => $smokeLevelData,
        'flameData' => $flameData,
        'safetyAlarmData' => $safetyAlarmData,
        'rows' => $rows,
        'rangeLabel' => $rangeLabel,
        'pointCount' => count($rowsChronological)
    ];
}

function fetchHistoryRows(mysqli $conn, string $startDate = '', string $endDate = '', int $limit = 100): array {
    $params = [];
    $types = '';
    $whereSql = buildWhereClause($params, $types, $startDate, $endDate);

    $sql = "SELECT id, device, temp_c, humidity, heat_index_c, alarm,
                   MQ135_raw, smoke_level, smoke_level_value, flame_detected, safety_alarm,
                   created_at
            FROM sensor_logs
            $whereSql
            ORDER BY id DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $params[] = $limit;
    $types .= 'i';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function passwordStrengthError(string $password): string {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'New password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'New password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'New password must contain at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'New password must contain at least one special character.';
    }
    return '';
}

enforceAllowedHosts();
forceHttpsIfNeeded();
sendSecurityHeaders();
startSecureSession();
enforceSessionPolicy();

$conn = getDb();
ensureTables($conn);
cleanupOldLoginAttempts($conn);

$loginError = '';
$notice = '';
$changePasswordError = '';
$changePasswordSuccess = '';
$reason = $_GET['reason'] ?? '';
if ($reason === 'timeout') {
    $notice = 'Your session timed out due to inactivity. Please sign in again.';
} elseif ($reason === 'session_expired') {
    $notice = 'Your session expired. Please sign in again.';
}

if (isset($_SESSION['change_password_status'])) {
    $flash = $_SESSION['change_password_status'];
    unset($_SESSION['change_password_status']);
    if (($flash['type'] ?? '') === 'success') {
        $changePasswordSuccess = (string)($flash['message'] ?? '');
    } else {
        $changePasswordError = (string)($flash['message'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    requireCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = getClientIp();

    if (failedLoginCount($conn, $ip) >= MAX_LOGIN_ATTEMPTS) {
        $loginError = 'Too many failed login attempts. Try again later.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash, full_name, must_change_password FROM dashboard_users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($user && password_verify($password, (string)$user['password_hash'])) {
            recordLoginAttempt($conn, $ip, $username, true);
            clearLoginAttempts($conn, $ip);

            session_regenerate_id(true);
            $_SESSION['dashboard_user'] = [
                'id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'full_name' => (string)($user['full_name'] ?: $user['username']),
                'must_change_password' => (int)$user['must_change_password']
            ];
            $_SESSION['created_at'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['last_regenerated_at'] = time();
            csrfToken();

            $stmt = $conn->prepare("UPDATE dashboard_users SET last_login_at = NOW() WHERE id = ?");
            if ($stmt) {
                $uid = (int)$user['id'];
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->close();
            }

            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            recordLoginAttempt($conn, $ip, $username, false);
            $loginError = 'Invalid username or password.';
        }
    }
}

if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    requireCsrf();
    logoutAndRedirect();
}

if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    requireCsrf();

    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $currentUserId = (int)($_SESSION['dashboard_user']['id'] ?? 0);

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'All password fields are required.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#change-password');
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'New password and confirm password do not match.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#change-password');
        exit;
    }

    $strengthError = passwordStrengthError($newPassword);
    if ($strengthError !== '') {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => $strengthError];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#change-password');
        exit;
    }

    $stmt = $conn->prepare("SELECT password_hash FROM dashboard_users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$account || !password_verify($currentPassword, (string)$account['password_hash'])) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'Current password is incorrect.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#change-password');
        exit;
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $mustChange = 0;
    $stmt = $conn->prepare("UPDATE dashboard_users SET password_hash = ?, must_change_password = ?, last_password_change_at = NOW() WHERE id = ?");
    $stmt->bind_param('sii', $newPasswordHash, $mustChange, $currentUserId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $_SESSION['dashboard_user']['must_change_password'] = 0;
        $_SESSION['change_password_status'] = ['type' => 'success', 'message' => 'Password changed successfully.'];
    } else {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'Failed to change password. Please try again.'];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '#change-password');
    exit;
}

if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_cooling') {
    requireCsrf();
    header('Content-Type: application/json; charset=UTF-8');

    if ((int)($_SESSION['dashboard_user']['must_change_password'] ?? 0) === 1) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Change the default password before using controls.']);
        exit;
    }

    $mode = strtoupper(trim((string)($_POST['mode'] ?? 'AUTO')));
    $manualState = (int)($_POST['manual_state'] ?? 0);
    $control = updateCoolingControl($conn, $mode, $manualState);

    if ($control) {
        echo json_encode(['ok' => true, 'control' => $control]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to update cooling control.']);
    }
    exit;
}

$startDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';
$allowedChartTypes = [
    'line' => 'Line Chart',
    'bar' => 'Bar Graph',
    'pie' => 'Pie Chart',
    'doughnut' => 'Doughnut Chart',
    'polarArea' => 'Polar Area'
];
$chartType = isset($_GET['chart_type']) ? (string)$_GET['chart_type'] : 'line';
if (!array_key_exists($chartType, $allowedChartTypes)) {
    $chartType = 'line';
}

if (!isLoggedIn()) {
    $csrf = csrfToken();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC-Temp Logger Login</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a, #111827, #1e293b);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 440px;
            background: rgba(30, 41, 59, 0.82);
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.3);
        }
        h1 { margin-top: 0; margin-bottom: 8px; }
        p { color: #cbd5e1; }
        label { display: block; margin: 16px 0 6px; color: #cbd5e1; }
        input {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            background: #0f172a;
            color: #fff;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            margin-top: 18px;
            padding: 12px;
            border: 0;
            border-radius: 10px;
            background: #38bdf8;
            color: #062033;
            font-weight: bold;
            cursor: pointer;
        }
        .error, .notice {
            margin-top: 12px;
            font-size: 14px;
            border-radius: 10px;
            padding: 12px;
        }
        .error {
            color: #fecaca;
            background: rgba(239, 68, 68, 0.14);
            border: 1px solid rgba(239, 68, 68, 0.22);
        }
        .notice {
            color: #bfdbfe;
            background: rgba(59, 130, 246, 0.14);
            border: 1px solid rgba(59, 130, 246, 0.22);
        }
        .note {
            margin-top: 16px;
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.5;
        }
        code {
            background: rgba(255,255,255,0.08);
            padding: 2px 6px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <form class="login-card" method="post" autocomplete="off">
        <h1>NOC-Temperature Logger</h1>
        <p>Sign in to view charts and control the exhaust fan relay.</p>

        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">

        <label for="username">Username</label>
        <input id="username" type="text" name="username" required maxlength="50">

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>

        <button type="submit">Login</button>

        <?php if ($notice !== ''): ?>
            <div class="notice"><?php echo e($notice); ?></div>
        <?php endif; ?>

        <?php if ($loginError !== ''): ?>
            <div class="error"><?php echo e($loginError); ?></div>
        <?php endif; ?>

        <div class="note">
                    <strong>Authorized Access Only</strong><br>
    This dashboard is intended for authorized personnel responsible for monitoring
    temperature, humidity, alarm status, and cooling system activity. Unauthorized access,
    sharing of credentials, or misuse of this system is strictly prohibited.
        </div>
    </form>
</body>
</html>
<?php
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'dashboard' => fetchDashboardData($conn, $startDate, $endDate),
        'history_rows' => fetchHistoryRows($conn, $startDate, $endDate, 100),
        'control' => fetchCoolingControl($conn),
        'must_change_password' => (int)($_SESSION['dashboard_user']['must_change_password'] ?? 0)
    ]);
    exit;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportRows = fetchHistoryRows($conn, $startDate, $endDate, 5000);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sensor_logs_export.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID',
        'Device',
        'Temperature (C)',
        'Humidity (%)',
        'Heat Index (C)',
        'Temperature Alarm',
        'MQ-135 Raw Value',
        'Smoke Level',
        'Smoke Level Value',
        'HL-01 Flame Detected',
        'Safety Alarm',
        'Created At'
    ]);
    foreach ($exportRows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['device'],
            $row['temp_c'],
            $row['humidity'],
            $row['heat_index_c'],
            ((int)($row['alarm'] ?? 0) === 1) ? 'ACTIVE' : 'NORMAL',
            $row['MQ135_raw'] ?? '',
            $row['smoke_level'] ?? 'NORMAL',
            $row['smoke_level_value'] ?? 0,
            ((int)($row['flame_detected'] ?? 0) === 1) ? 'DETECTED' : 'NORMAL',
            ((int)($row['safety_alarm'] ?? 0) === 1) ? 'ACTIVE' : 'NORMAL',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

$dashboard = fetchDashboardData($conn, $startDate, $endDate);
$latest = $dashboard['latest'];
$historyRows = fetchHistoryRows($conn, $startDate, $endDate, 100);
$coolingControl = fetchCoolingControl($conn);
$currentUser = $_SESSION['dashboard_user'];
$csrf = csrfToken();
$mustChangePassword = (int)($currentUser['must_change_password'] ?? 0) === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC-Temperature Logger</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a, #111827, #1e293b);
            color: #fff;
            padding: 20px;
        }
        .container { max-width: 1440px; margin: auto; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .meta { color: #cbd5e1; }
        .meta strong { color: #fff; }
        .top-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .pill, button.pill, a.pill {
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.1);
            color: #fff;
            padding: 10px 14px;
            border-radius: 999px;
            text-decoration: none;
            cursor: pointer;
        }
        .danger-banner, .success-banner, .info-banner {
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
        }
        .danger-banner {
            background: rgba(239, 68, 68, 0.14);
            border: 1px solid rgba(239, 68, 68, 0.22);
            color: #fecaca;
        }
        .success-banner {
            background: rgba(34, 197, 94, 0.14);
            border: 1px solid rgba(34, 197, 94, 0.22);
            color: #bbf7d0;
        }
        .info-banner {
            background: rgba(59, 130, 246, 0.14);
            border: 1px solid rgba(59, 130, 246, 0.22);
            color: #bfdbfe;
        }
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 32px; }
        .header p { margin-top: 8px; color: #cbd5e1; }

        .toolbar, .control-card, .card, .chart-card, .table-card {
            background: rgba(30, 41, 59, 0.75);
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 24px rgba(0,0,0,0.25);
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .form-group { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
        label { color: #cbd5e1; font-size: 14px; }
        input, select, button, a.button-link {
            border: 1px solid rgba(255,255,255,0.08);
            background: #0f172a;
            color: #fff;
            padding: 10px 12px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
        }

        button, a.button-link { cursor: pointer; }
        .button-row { display: flex; flex-wrap: wrap; gap: 10px; }
        .inline-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            align-items: end;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .card h3, .chart-card h3, .table-card h3, .control-card h3 {
            margin: 0 0 10px;
            color: #cbd5e1;
            font-weight: normal;
        }
        .value {
            font-size: 28px;
            font-weight: bold;
            word-break: break-word;
        }
        .small {
            font-size: 14px;
            color: #94a3b8;
            margin-top: 6px;
        }
        .status-normal { color: #22c55e; }
        .status-warning { color: #f59e0b; }
        .status-alarm { color: #ef4444; }

        .charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        canvas {
            width: 100% !important;
            height: 320px !important;
        }

        .cooling-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: rgba(15,23,42,0.55);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 14px;
            padding: 14px 16px;
        }

        .toggle-row h4 { margin: 0 0 6px; }
        .toggle-row p { margin: 0; color: #94a3b8; font-size: 13px; }

        .switch {
            position: relative;
            display: inline-block;
            width: 58px;
            height: 32px;
            flex: 0 0 auto;
        }
        .switch input { display: none; }
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: #334155;
            transition: .2s;
            border-radius: 999px;
        }
        .slider:before {
            content: "";
            position: absolute;
            height: 24px;
            width: 24px;
            left: 4px;
            top: 4px;
            background: white;
            transition: .2s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background: #22c55e;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
        }
        th { color: #cbd5e1; }

        .footer {
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
            margin-top: 18px;
        }

        body[data-theme="light"] {
            background: linear-gradient(135deg, #eef4ff, #f8fafc, #e2e8f0);
            color: #0f172a;
        }
        body[data-theme="light"] .topbar,
        body[data-theme="light"] .toolbar,
        body[data-theme="light"] .control-card,
        body[data-theme="light"] .card,
        body[data-theme="light"] .chart-card,
        body[data-theme="light"] .table-card {
            background: rgba(255,255,255,0.92);
            border-color: rgba(15,23,42,0.08);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            color: #0f172a;
        }
        body[data-theme="light"] .header p,
        body[data-theme="light"] .small,
        body[data-theme="light"] .footer,
        body[data-theme="light"] th,
        body[data-theme="light"] .meta {
            color: #475569;
        }
        body[data-theme="light"] input[type="date"],
        body[data-theme="light"] input[type="text"],
        body[data-theme="light"] input[type="password"],
        body[data-theme="light"] select {
            background: #ffffff;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        body[data-theme="light"] button,
        body[data-theme="light"] .button-link,
        body[data-theme="light"] .pill {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: rgba(29,78,216,0.15);
        }
        body[data-theme="light"] button:hover,
        body[data-theme="light"] .button-link:hover,
        body[data-theme="light"] .pill:hover {
            filter: brightness(0.97);
        }
        body[data-theme="light"] .danger-banner {
            background: rgba(254, 242, 242, 0.95);
            color: #991b1b;
            border-color: rgba(239, 68, 68, 0.18);
        }
        body[data-theme="light"] table td,
        body[data-theme="light"] table th {
            border-bottom-color: rgba(15,23,42,0.08);
        }
        @media (max-width: 980px) {
            .charts, .cooling-layout { grid-template-columns: 1fr; }
            .header h1 { font-size: 26px; }
            .value { font-size: 22px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="meta">
            Signed in as <strong><?php echo e((string)$currentUser['full_name']); ?></strong>
            (<?php echo e((string)$currentUser['username']); ?>)
        </div>
        <div class="top-actions">
            <button type="button" class="pill theme-toggle" id="themeToggleBtn" aria-label="Toggle dashboard theme">Switch to Light Theme</button>
            <a class="pill" href="change_password_dashboard.php">Change Password</a>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <button type="submit" class="pill">Logout</button>
            </form>
        </div>
    </div>

    <?php if ($mustChangePassword): ?>
        <div class="danger-banner">
            You are using the default password. Open the separate Change Password page now before using dashboard controls or deploying this on a public domain.
        </div>
    <?php endif; ?>

    <div class="header">
        <h1>NOC Temperature and Humidity Monitoring and Control System</h1>
        <p>This dashboard feeds from esp8266 through server/ database not directly from the sensor</p>
    </div>

    <form class="toolbar" method="get" autocomplete="off">
        <div class="button-row">
            <div class="form-group">
                <label for="start_date">Start Date</label>
                <input id="start_date" type="datetime-local" step="1" name="start_date" value="<?php echo e($startDate); ?>">
            </div>
            <div class="form-group">
                <label for="end_date">End Date</label>
                <input id="end_date" type="datetime-local" step="1" name="end_date" value="<?php echo e($endDate); ?>">
            </div>
            <div class="form-group">
                <label for="chart_type">Chart Type</label>
                <select id="chart_type" name="chart_type">
                    <?php foreach ($allowedChartTypes as $typeValue => $typeLabel): ?>
                        <option value="<?php echo e($typeValue); ?>" <?php echo ($chartType === $typeValue) ? 'selected' : ''; ?>>
                            <?php echo e($typeLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="button-row">
            <button type="submit">Apply Filter</button>
            <a class="button-link" href="?export=csv&amp;start_date=<?php echo urlencode($startDate); ?>&amp;end_date=<?php echo urlencode($endDate); ?>&amp;chart_type=<?php echo urlencode($chartType); ?>">Export CSV</a>
        </div>
    </form>

    <div class="control-card" style="margin-bottom:20px;">
        <h3>Cooling Control</h3>
        <div class="cooling-layout">
            <div class="toggle-row">
                <div>
                    <h4>Auto Follow Alarm</h4>
                    <p>When enabled, the relay follows the alarm logic from the temperature threshold.</p>
                </div>
                <label class="switch">
                    <input type="checkbox" id="autoModeSwitch" <?php echo (($coolingControl['mode'] ?? 'AUTO') === 'AUTO') ? 'checked' : ''; ?> <?php echo $mustChangePassword ? 'disabled' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div>
                    <h4>Manual Cooling Relay</h4>
                    <p>Used only when Auto Follow Alarm is turned off.</p>
                </div>
                <label class="switch">
                    <input type="checkbox" id="manualRelaySwitch" <?php echo ((int)($coolingControl['manual_state'] ?? 0) === 1) ? 'checked' : ''; ?> <?php echo (($coolingControl['mode'] ?? 'AUTO') === 'AUTO' || $mustChangePassword) ? 'disabled' : ''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
        </div>
        <div class="small" style="margin-top:12px;">
            Current mode: <strong id="coolingModeText"><?php echo e((string)($coolingControl['mode'] ?? 'AUTO')); ?></strong> |
            Requested manual state: <strong id="manualStateText"><?php echo ((int)($coolingControl['manual_state'] ?? 0) === 1) ? 'ON' : 'OFF'; ?></strong> |
            Reported relay state: <strong id="relayStateText"><?php echo ((int)($coolingControl['relay_state'] ?? 0) === 1) ? 'ON' : 'OFF'; ?></strong>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h3>Device</h3>
            <div class="value" id="deviceValue"><?php echo $latest ? e((string)$latest['device']) : 'N/A'; ?></div>
            <div class="small">Source device</div>
        </div>

        <div class="card">
            <h3>Temperature</h3>
            <div class="value" id="tempValue"><?php echo $latest ? e((string)$latest['temp_c']) . ' °C' : 'N/A'; ?></div>
            <div class="small">Current temperature</div>
        </div>

        <div class="card">
            <h3>Humidity</h3>
            <div class="value" id="humidityValue"><?php echo $latest ? e((string)$latest['humidity']) . ' %' : 'N/A'; ?></div>
            <div class="small">Current humidity</div>
        </div>

        <div class="card">
            <h3>Heat Index</h3>
            <div class="value" id="heatIndexValue"><?php echo $latest ? e((string)$latest['heat_index_c']) . ' °C' : 'N/A'; ?></div>
            <div class="small">Feels-like temperature</div>
        </div>

        <div class="card">
            <h3>Alarm Status</h3>
            <div class="value <?php echo ($latest && (int)$latest['alarm'] === 1) ? 'status-alarm' : 'status-normal'; ?>" id="alarmValue">
                <?php echo (!$latest) ? 'N/A' : (((int)$latest['alarm'] === 1) ? 'ACTIVE' : 'NORMAL'); ?>
            </div>
            <div class="small">Threshold evaluation</div>
        </div>


        <div class="card">
            <h3>MQ-135 Smoke Raw Value</h3>
            <div class="value" id="MQ135Value"><?php echo $latest ? e((string)($latest['MQ135_raw'] ?? '0')) . ' / 1023' : 'N/A'; ?></div>
            <div class="small">Analog smoke sensor reading</div>
        </div>

        <div class="card">
            <h3>MQ-135 Air Quality Index</h3>
            <div class="value <?php echo ($latest && (int)($latest['smoke_level_value'] ?? 0) >= 2) ? 'status-alarm' : (($latest && (int)($latest['smoke_level_value'] ?? 0) === 1) ? 'status-warning' : 'status-normal'); ?>" id="smokeLevelValue">
                <?php echo $latest ? e((string)($latest['smoke_level'] ?? 'NORMAL')) : 'N/A'; ?>
            </div>
            <div class="small">GOOD / MODERATE / POOR / UNHEALTHY / HAZARDOUS</div>
        </div>

        <div class="card">
            <h3>HL-01 Flame</h3>
            <div class="value <?php echo ($latest && (int)($latest['flame_detected'] ?? 0) === 1) ? 'status-alarm' : 'status-normal'; ?>" id="flameValue">
                <?php echo (!$latest) ? 'N/A' : (((int)($latest['flame_detected'] ?? 0) === 1) ? 'DETECTED' : 'NORMAL'); ?>
            </div>
            <div class="small">Digital flame sensor status</div>
        </div>

        <div class="card">
            <h3>Safety Warning</h3>
            <div class="value <?php echo ($latest && (int)($latest['safety_alarm'] ?? 0) === 1) ? 'status-alarm' : 'status-normal'; ?>" id="safetyAlarmValue">
                <?php echo (!$latest) ? 'N/A' : (((int)($latest['safety_alarm'] ?? 0) === 1) ? 'ACTIVE' : 'NORMAL'); ?>
            </div>
            <div class="small">Flame/smoke warning evaluation</div>
        </div>

        <div class="card">
            <h3>Last Update</h3>
            <div class="value" style="font-size:18px;" id="lastUpdateValue">
                <?php echo $latest ? e((string)$latest['created_at']) : 'N/A'; ?>
            </div>
            <div class="small">Latest database record</div>
        </div>
    </div>

    <div class="charts">
        <div class="chart-card">
            <h3>Temperature Trend</h3>
            <canvas id="tempChart"></canvas>
        </div>

        <div class="chart-card">
            <h3>Humidity Trend</h3>
            <canvas id="humidityChart"></canvas>
        </div>

        <div class="chart-card">
            <h3>Heat Index Trend</h3>
            <canvas id="heatChart"></canvas>
        </div>

        <div class="chart-card">
            <h3>Temperature Alarm Activity</h3>
            <canvas id="alarmChart"></canvas>
        </div>

        <div class="chart-card">
            <h3>MQ-135 Smoke Raw Value</h3>
            <canvas id="MQ135Chart"></canvas>
        </div>

        <div class="chart-card">
            <h3>MQ-135 Air Quality Index (AQI)</h3>
            <canvas id="smokeLevelChart"></canvas>
        </div>

        <div class="chart-card">
            <h3>HL-01 Flame Detection</h3>
            <canvas id="flameChart"></canvas>
        </div>

        <div class="chart-card">
            <h3>Safety Alarm Activity</h3>
            <canvas id="safetyAlarmChart"></canvas>
        </div>
    </div>
    <div class="table-card">

        <h3>Recent Sensor Logs</h3>
        <div class="small" id="recentLogsRefreshText" style="margin-bottom:10px;">Auto-refreshes every 5 seconds. New rows appear after the ESP8266 posts to MySQL.</div>
        <div style="overflow:auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Device</th>
                        <th>Temperature (°C)</th>
                        <th>Humidity (%)</th>
                        <th>Heat Index (°C)</th>
                        <th>Temp Alarm</th>
                        <th>MQ-135 Raw</th>
                        <th>AQI</th>
                        <th>HL-01 Flame</th>
                        <th>Safety Alarm</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody id="recentSensorLogsBody">
                <?php if (!$historyRows): ?>
                    <tr><td colspan="11">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($historyRows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo e((string)$row['device']); ?></td>
                            <td><?php echo e((string)$row['temp_c']); ?></td>
                            <td><?php echo e((string)$row['humidity']); ?></td>
                            <td><?php echo e((string)$row['heat_index_c']); ?></td>
                            <td class="<?php echo ((int)($row['alarm'] ?? 0) === 1) ? 'status-alarm' : 'status-normal'; ?>" style="font-weight:bold;"><?php echo ((int)($row['alarm'] ?? 0) === 1) ? 'ACTIVE' : 'NORMAL'; ?></td>
                            <td><?php echo e((string)($row['MQ135_raw'] ?? '')); ?></td>
                            <td class="<?php echo ((int)($row['smoke_level_value'] ?? 0) >= 2) ? 'status-alarm' : (((int)($row['smoke_level_value'] ?? 0) === 1) ? 'status-warning' : 'status-normal'); ?>" style="font-weight:bold;"><?php echo e((string)($row['smoke_level'] ?? 'NORMAL')); ?></td>
                            <td class="<?php echo ((int)($row['flame_detected'] ?? 0) === 1) ? 'status-alarm' : 'status-normal'; ?>" style="font-weight:bold;"><?php echo ((int)($row['flame_detected'] ?? 0) === 1) ? 'DETECTED' : 'NORMAL'; ?></td>
                            <td class="<?php echo ((int)($row['safety_alarm'] ?? 0) === 1) ? 'status-alarm' : 'status-normal'; ?>" style="font-weight:bold;"><?php echo ((int)($row['safety_alarm'] ?? 0) === 1) ? 'ACTIVE' : 'NORMAL'; ?></td>
                            <td><?php echo e((string)$row['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="footer">
        Secure session timeout: <?php echo (int)(SESSION_IDLE_TIMEOUT / 60); ?> minutes idle.
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode($csrf); ?>;
const mustChangePassword = <?php echo $mustChangePassword ? 'true' : 'false'; ?>;

let tempChart, humidityChart, heatChart, alarmChart, MQ135Chart, smokeLevelChart, flameChart, safetyAlarmChart;
const initialDashboard = <?php echo json_encode($dashboard, JSON_UNESCAPED_SLASHES); ?>;
const initialControl = <?php echo json_encode($coolingControl, JSON_UNESCAPED_SLASHES); ?>;
let latestDashboard = initialDashboard;
let selectedChartType = <?php echo json_encode($chartType); ?>;
let activeChartType = selectedChartType;

function currentTheme() {
    return document.body.getAttribute('data-theme') || 'dark';
}

function chartPalette(theme = currentTheme()) {
    if (theme === 'light') {
        return {
            text: '#334155',
            grid: 'rgba(51,65,85,0.12)',
            temperature: '#dc2626',
            humidity: '#0284c7',
            heat: '#d97706',
            MQ135: '#7c3aed',
            smoke: '#ea580c',
            flame: '#b91c1c',
            safety: '#be123c',
            alarmOn: 'rgba(220, 38, 38, 0.82)',
            alarmOff: 'rgba(14, 165, 233, 0.72)'
        };
    }
    return {
        text: '#cbd5e1',
        grid: 'rgba(255,255,255,0.08)',
        temperature: '#38bdf8',
        humidity: '#22c55e',
        heat: '#f59e0b',
        MQ135: '#a78bfa',
        smoke: '#fb923c',
        flame: '#f87171',
        safety: '#f43f5e',
        alarmOn: 'rgba(239, 68, 68, 0.82)',
        alarmOff: 'rgba(34, 197, 94, 0.72)'
    };
}

function chartScales() {
    const palette = chartPalette();
    return {
        x: {
            ticks: {
                color: palette.text,
                maxRotation: 45,
                minRotation: 0,
                autoSkip: true,
                maxTicksLimit: 8
            },
            grid: { color: palette.grid },
            title: {
                display: true,
                text: 'Date and Time',
                color: palette.text
            }
        },
        y: {
            ticks: { color: palette.text },
            grid: { color: palette.grid }
        }
    };
}

function isRadialChart(type = selectedChartType) {
    return ['pie', 'doughnut', 'polarArea'].includes(type);
}

function chartSubtitle(dashboard = latestDashboard) {
    const count = dashboard && dashboard.pointCount ? dashboard.pointCount : ((dashboard && dashboard.labels) ? dashboard.labels.length : 0);
    const range = dashboard && dashboard.rangeLabel ? dashboard.rangeLabel : 'No records available';
    return count > 0 ? `${count} records | ${range}` : 'No records found for selected filter';
}


function normalizeTooltipTime(label) {
    if (label === null || label === undefined || label === '') {
        return 'No timestamp available';
    }

    // Preserve the exact MySQL timestamp from created_at: YYYY-MM-DD HH:mm:ss
    return String(label);
}

function tooltipTitleFromItems(items, radialTitle = '') {
    if (!items || !items.length) {
        return '';
    }

    const item = items[0];

    if (isRadialChart()) {
        return radialTitle ? `${radialTitle}: ${item.label}` : String(item.label || '');
    }

    const chartLabels = item.chart && item.chart.data && Array.isArray(item.chart.data.labels)
        ? item.chart.data.labels
        : [];
    const exactTime = chartLabels[item.dataIndex] || item.label || '';

    return `Exact Time: ${normalizeTooltipTime(exactTime)}`;
}

function radialColors(count) {
    const base = currentTheme() === 'light'
        ? ['rgba(37,99,235,0.82)', 'rgba(220,38,38,0.82)', 'rgba(2,132,199,0.82)', 'rgba(217,119,6,0.82)', 'rgba(22,163,74,0.82)', 'rgba(124,58,237,0.82)']
        : ['rgba(56,189,248,0.82)', 'rgba(239,68,68,0.82)', 'rgba(34,197,94,0.82)', 'rgba(245,158,11,0.82)', 'rgba(168,85,247,0.82)', 'rgba(20,184,166,0.82)'];
    return Array.from({ length: count }, (_, i) => base[i % base.length]);
}

function metricDataset(label, data, colorKey) {
    const palette = chartPalette();
    const type = selectedChartType;

    if (isRadialChart(type)) {
        return {
            label,
            data,
            borderWidth: 1,
            backgroundColor: radialColors(data.length),
            borderColor: currentTheme() === 'light' ? '#ffffff' : '#0f172a'
        };
    }

    return {
        label,
        data,
        borderColor: palette[colorKey],
        backgroundColor: type === 'bar' ? palette[colorKey] : 'rgba(0,0,0,0)',
        borderWidth: 2,
        tension: 0.3,
        fill: false,
        pointRadius: 3,
        pointHoverRadius: 5
    };
}

function chartOptions(unitLabel, dashboard = latestDashboard, extra = {}) {
    const palette = chartPalette();
    const radial = isRadialChart();

    const baseOptions = {
        responsive: true,
        animation: false,
        interaction: radial ? undefined : {
            mode: 'index',
            intersect: false,
            axis: 'x'
        },
        hover: radial ? undefined : {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    color: palette.text,
                    usePointStyle: false,
                    boxWidth: 28,
                    boxHeight: 12
                }
            },
            subtitle: {
                display: true,
                text: chartSubtitle(dashboard),
                color: palette.text,
                padding: { bottom: 10 },
                font: { size: 12 }
            },
            tooltip: {
                enabled: true,
                displayColors: true,
                backgroundColor: currentTheme() === 'light' ? 'rgba(15,23,42,0.92)' : 'rgba(2,6,23,0.94)',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                borderColor: palette.grid,
                borderWidth: 1,
                padding: 12,
                caretPadding: 8,
                callbacks: {
                    title: function(items) {
                        return tooltipTitleFromItems(items);
                    },
                    label: function(context) {
                        const datasetLabel = context.dataset.label || unitLabel;
                        return `${datasetLabel}: ${context.formattedValue}`;
                    }
                }
            }
        }
    };

    if (!radial) {
        baseOptions.scales = chartScales();
    }

    return Object.assign(baseOptions, extra);
}

function alarmLegendLabels(chart) {
    const palette = chartPalette();
    return [{
        text: 'Alarm ACTIVE',
        fillStyle: palette.alarmOn,
        strokeStyle: palette.alarmOn,
        lineWidth: 1,
        hidden: false,
        datasetIndex: 0
    }];
}

function alarmDataset(data) {
    const palette = chartPalette();

    if (isRadialChart()) {
        const normalCount = data.filter(v => parseInt(v, 10) !== 1).length;
        const activeCount = data.filter(v => parseInt(v, 10) === 1).length;
        return {
            labels: ['NORMAL', 'ACTIVE'],
            datasets: [{
                label: 'Alarm Count',
                data: [normalCount, activeCount],
                borderWidth: 1,
                backgroundColor: [palette.alarmOff, palette.alarmOn]
            }]
        };
    }

    return {
        labels: latestDashboard.labels || [],
        datasets: [{
            label: 'Alarm ACTIVE',
            data,
            borderWidth: 1,
            backgroundColor: data.map(v => parseInt(v, 10) === 1 ? palette.alarmOn : palette.alarmOff),
            borderColor: data.map(v => parseInt(v, 10) === 1 ? palette.alarmOn : palette.alarmOff)
        }]
    };
}

function createMetricChart(canvasId, label, data, labels, colorKey, unitLabel, dashboard = latestDashboard) {
    return new Chart(document.getElementById(canvasId), {
        type: selectedChartType,
        data: {
            labels,
            datasets: [metricDataset(label, data, colorKey)]
        },
        options: chartOptions(unitLabel, dashboard)
    });
}

function createAlarmChart(data, labels, dashboard = latestDashboard) {
    const chartData = alarmDataset(data);
    if (!isRadialChart()) {
        chartData.labels = labels;
    }

    return new Chart(document.getElementById('alarmChart'), {
        type: selectedChartType === 'line' ? 'bar' : selectedChartType,
        data: chartData,
        options: chartOptions('Alarm Status', dashboard, {
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: chartPalette().text,
                        usePointStyle: false,
                        boxWidth: 28,
                        boxHeight: 12,
                        generateLabels: alarmLegendLabels
                    }
                },
                subtitle: {
                    display: true,
                    text: chartSubtitle(dashboard),
                    color: chartPalette().text,
                    padding: { bottom: 10 },
                    font: { size: 12 }
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            return tooltipTitleFromItems(items, 'Alarm Status');
                        },
                        label: function(context) {
                            if (isRadialChart()) {
                                return `${context.label}: ${context.formattedValue} record(s)`;
                            }
                            return parseInt(context.raw, 10) === 1 ? 'Alarm: ACTIVE' : 'Alarm: NORMAL';
                        }
                    }
                }
            },
            scales: isRadialChart() ? undefined : {
                x: chartScales().x,
                y: {
                    ...chartScales().y,
                    beginAtZero: true,
                    max: 1,
                    ticks: {
                        color: chartPalette().text,
                        stepSize: 1,
                        callback: function(value) {
                            return value === 1 ? 'ACTIVE' : 'NORMAL';
                        }
                    },
                    title: {
                        display: true,
                        text: 'Alarm State',
                        color: chartPalette().text
                    }
                }
            }
        })
    });
}


function dynamicMaxValue(data, minimumMax = 100, paddingPercent = 0.15) {
    if (!Array.isArray(data) || data.length === 0) {
        return minimumMax;
    }

    const numericValues = data
        .map(v => Number(v))
        .filter(v => Number.isFinite(v));

    if (numericValues.length === 0) {
        return minimumMax;
    }

    const highest = Math.max(...numericValues);

    if (highest <= 0) {
        return minimumMax;
    }

    const paddedMax = Math.ceil(highest + (highest * paddingPercent));

    return Math.max(minimumMax, paddedMax);
}

function createBoundedMetricChart(canvasId, label, data, labels, colorKey, unitLabel, yTitle, yMax, yTickCallback, dashboard = latestDashboard) {
    const extraOptions = isRadialChart() ? {} : {
        scales: {
            x: chartScales().x,
            y: {
                ...chartScales().y,
                beginAtZero: true,
                suggestedMax: yMax,
                max: yMax,
                ticks: {
                    color: chartPalette().text,
                    callback: yTickCallback || undefined
                },
                title: {
                    display: true,
                    text: yTitle,
                    color: chartPalette().text
                }
            }
        }
    };

    return new Chart(document.getElementById(canvasId), {
        type: selectedChartType,
        data: {
            labels,
            datasets: [metricDataset(label, data, colorKey)]
        },
        options: chartOptions(unitLabel, dashboard, extraOptions)
    });
}

function createBinaryStateChart(canvasId, title, data, labels, activeLabel, colorKey, dashboard = latestDashboard) {
    const palette = chartPalette();

    if (isRadialChart()) {
        const normalCount = data.filter(v => parseInt(v, 10) !== 1).length;
        const activeCount = data.filter(v => parseInt(v, 10) === 1).length;

        return new Chart(document.getElementById(canvasId), {
            type: selectedChartType,
            data: {
                labels: ['NORMAL', activeLabel],
                datasets: [{
                    label: title,
                    data: [normalCount, activeCount],
                    borderWidth: 1,
                    backgroundColor: [palette.alarmOff, palette[colorKey] || palette.alarmOn]
                }]
            },
            options: chartOptions(title, dashboard)
        });
    }

    return new Chart(document.getElementById(canvasId), {
        type: selectedChartType === 'line' ? 'bar' : selectedChartType,
        data: {
            labels,
            datasets: [{
                label: title,
                data,
                borderWidth: 1,
                backgroundColor: data.map(v => parseInt(v, 10) === 1 ? (palette[colorKey] || palette.alarmOn) : palette.alarmOff),
                borderColor: data.map(v => parseInt(v, 10) === 1 ? (palette[colorKey] || palette.alarmOn) : palette.alarmOff)
            }]
        },
        options: chartOptions(title, dashboard, {
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: chartPalette().text,
                        usePointStyle: false,
                        boxWidth: 28,
                        boxHeight: 12
                    }
                },
                subtitle: {
                    display: true,
                    text: chartSubtitle(dashboard),
                    color: chartPalette().text,
                    padding: { bottom: 10 },
                    font: { size: 12 }
                },
                tooltip: {
                    enabled: true,
                    displayColors: true,
                    backgroundColor: currentTheme() === 'light' ? 'rgba(15,23,42,0.92)' : 'rgba(2,6,23,0.94)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: chartPalette().grid,
                    borderWidth: 1,
                    padding: 12,
                    caretPadding: 8,
                    callbacks: {
                        title: function(items) {
                            return tooltipTitleFromItems(items);
                        },
                        label: function(context) {
                            return parseInt(context.raw, 10) === 1 ? `${title}: ${activeLabel}` : `${title}: NORMAL`;
                        }
                    }
                }
            },
            scales: {
                x: chartScales().x,
                y: {
                    ...chartScales().y,
                    beginAtZero: true,
                    max: 1,
                    ticks: {
                        color: chartPalette().text,
                        stepSize: 1,
                        callback: function(value) {
                            return value === 1 ? activeLabel : 'NORMAL';
                        }
                    },
                    title: {
                        display: true,
                        text: title,
                        color: chartPalette().text
                    }
                }
            }
        })
    });
}

// ── AQI helpers ──────────────────────────────────────────────────────────────
// Maps smoke_level_value (0–4) to the standard AQI colour scale.
const AQI_LABELS = ['GOOD', 'MODERATE', 'POOR', 'UNHEALTHY', 'HAZARDOUS'];
const AQI_COLORS_DARK  = [
    'rgba(34,197,94,0.82)',   // 0 GOOD        – green
    'rgba(234,179,8,0.82)',   // 1 MODERATE     – yellow
    'rgba(249,115,22,0.82)',  // 2 POOR         – orange
    'rgba(239,68,68,0.82)',   // 3 UNHEALTHY    – red
    'rgba(126,34,206,0.82)'  // 4 HAZARDOUS    – purple
];
const AQI_COLORS_LIGHT = [
    'rgba(21,128,61,0.82)',   // 0 GOOD
    'rgba(161,98,7,0.82)',    // 1 MODERATE
    'rgba(194,65,12,0.82)',   // 2 POOR
    'rgba(185,28,28,0.82)',   // 3 UNHEALTHY
    'rgba(88,28,135,0.82)'   // 4 HAZARDOUS
];

function aqiColor(value) {
    const idx = Math.min(Math.max(parseInt(value, 10) || 0, 0), 4);
    return (currentTheme() === 'light' ? AQI_COLORS_LIGHT : AQI_COLORS_DARK)[idx];
}

function createAqiChart(data, labels, dashboard = latestDashboard) {
    const palette = chartPalette();
    const radial  = isRadialChart();

    let chartData;
    if (radial) {
        // Count occurrences of each AQI tier for pie/doughnut/polar
        const counts = [0, 0, 0, 0, 0];
        data.forEach(v => { const i = Math.min(Math.max(parseInt(v, 10) || 0, 0), 4); counts[i]++; });
        chartData = {
            labels: AQI_LABELS,
            datasets: [{
                label: 'AQI Distribution',
                data: counts,
                borderWidth: 1,
                backgroundColor: currentTheme() === 'light' ? AQI_COLORS_LIGHT : AQI_COLORS_DARK,
                borderColor: currentTheme() === 'light' ? '#ffffff' : '#0f172a'
            }]
        };
    } else {
        chartData = {
            labels,
            datasets: [{
                label: 'Air Quality Index',
                data,
                borderWidth: 1,
                backgroundColor: data.map(aqiColor),
                borderColor: data.map(aqiColor),
                tension: 0.3,
                pointRadius: 3,
                pointHoverRadius: 5
            }]
        };
    }

    const extraOptions = radial ? {} : {
        scales: {
            x: chartScales().x,
            y: {
                ...chartScales().y,
                beginAtZero: true,
                min: 0,
                max: 4,
                ticks: {
                    color: palette.text,
                    stepSize: 1,
                    callback: function(value) {
                        return AQI_LABELS[value] ?? value;
                    }
                },
                title: {
                    display: true,
                    text: 'AQI Level',
                    color: palette.text
                }
            }
        }
    };

    return new Chart(document.getElementById('smokeLevelChart'), {
        type: radial ? selectedChartType : (selectedChartType === 'line' ? 'bar' : selectedChartType),
        data: chartData,
        options: chartOptions('AQI Level', dashboard, {
            ...extraOptions,
            plugins: {
                ...(chartOptions('AQI Level', dashboard, extraOptions).plugins || {}),
                tooltip: {
                    enabled: true,
                    displayColors: true,
                    backgroundColor: currentTheme() === 'light' ? 'rgba(15,23,42,0.92)' : 'rgba(2,6,23,0.94)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: palette.grid,
                    borderWidth: 1,
                    padding: 12,
                    caretPadding: 8,
                    callbacks: {
                        title: function(items) {
                            return tooltipTitleFromItems(items, 'AQI');
                        },
                        label: function(context) {
                            if (radial) {
                                return `${context.label}: ${context.formattedValue} record(s)`;
                            }
                            const idx = Math.min(Math.max(parseInt(context.raw, 10) || 0, 0), 4);
                            return `AQI: ${AQI_LABELS[idx]} (${idx})`;
                        }
                    }
                }
            }
        })
    });
}
// ─────────────────────────────────────────────────────────────────────────────

function destroyCharts() {
    [tempChart, humidityChart, heatChart, alarmChart, MQ135Chart, smokeLevelChart, flameChart, safetyAlarmChart].forEach(chart => {
        if (chart) {
            chart.destroy();
        }
    });
}

function rebuildCharts(dashboard = latestDashboard) {
    latestDashboard = dashboard;
    activeChartType = selectedChartType;
    destroyCharts();
    initCharts(dashboard);
}

function applyTheme(theme) {
    document.body.setAttribute('data-theme', theme);
    localStorage.setItem('tempDashTheme', theme);
    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
        btn.textContent = theme === 'light' ? 'Switch to Dark Theme' : 'Switch to Light Theme';
    }
    if (tempChart) {
        updateChartTheme();
    }
}

function updateChartTheme() {
    rebuildCharts(latestDashboard);
}

function initCharts(dashboard = initialDashboard) {
    latestDashboard = dashboard;
    tempChart = createMetricChart('tempChart', 'Temperature (°C)', dashboard.tempData || [], dashboard.labels || [], 'temperature', 'Temperature (°C)', dashboard);
    humidityChart = createMetricChart('humidityChart', 'Humidity (%)', dashboard.humidityData || [], dashboard.labels || [], 'humidity', 'Humidity (%)', dashboard);
    heatChart = createMetricChart('heatChart', 'Heat Index (°C)', dashboard.heatIndexData || [], dashboard.labels || [], 'heat', 'Heat Index (°C)', dashboard);
    alarmChart = createAlarmChart(dashboard.alarmData || [], dashboard.labels || [], dashboard);

    MQ135Chart = createBoundedMetricChart(
        'MQ135Chart',
        'MQ-135 Raw Value',
        dashboard.MQ135Data || [],
        dashboard.labels || [],
        'MQ135',
        'MQ-135 Raw Value',
        'MQ-135 Analog Raw Value',
        dynamicMaxValue(dashboard.MQ135Data || [], 100, 0.15),
        null,
        dashboard
    );

    smokeLevelChart = createAqiChart(
        dashboard.smokeLevelData || [],
        dashboard.labels || [],
        dashboard
    );

    flameChart = createBinaryStateChart(
        'flameChart',
        'HL-01 Flame Detection',
        dashboard.flameData || [],
        dashboard.labels || [],
        'DETECTED',
        'flame',
        dashboard
    );

    safetyAlarmChart = createBinaryStateChart(
        'safetyAlarmChart',
        'Safety Alarm',
        dashboard.safetyAlarmData || [],
        dashboard.labels || [],
        'ACTIVE',
        'safety',
        dashboard
    );
}

function updateCharts(dashboard) {
    latestDashboard = dashboard;

    if (!tempChart || activeChartType !== selectedChartType) {
        rebuildCharts(dashboard);
        return;
    }

    if (isRadialChart()) {
        rebuildCharts(dashboard);
        return;
    }

    tempChart.data.labels = dashboard.labels;
    tempChart.data.datasets[0].data = dashboard.tempData;
    tempChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    tempChart.update();

    humidityChart.data.labels = dashboard.labels;
    humidityChart.data.datasets[0].data = dashboard.humidityData;
    humidityChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    humidityChart.update();

    heatChart.data.labels = dashboard.labels;
    heatChart.data.datasets[0].data = dashboard.heatIndexData;
    heatChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    heatChart.update();

    alarmChart.data.labels = dashboard.labels;
    alarmChart.data.datasets[0].data = dashboard.alarmData;
    const palette = chartPalette();
    alarmChart.data.datasets[0].backgroundColor = dashboard.alarmData.map(v => parseInt(v, 10) === 1 ? palette.alarmOn : palette.alarmOff);
    alarmChart.data.datasets[0].borderColor = dashboard.alarmData.map(v => parseInt(v, 10) === 1 ? palette.alarmOn : palette.alarmOff);
    alarmChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    alarmChart.update();

    MQ135Chart.data.labels = dashboard.labels;
    MQ135Chart.data.datasets[0].data = dashboard.MQ135Data;
    const mq135DynamicMax = dynamicMaxValue(dashboard.MQ135Data || [], 100, 0.15);
    if (MQ135Chart.options.scales && MQ135Chart.options.scales.y) {
        MQ135Chart.options.scales.y.suggestedMax = mq135DynamicMax;
        MQ135Chart.options.scales.y.max = mq135DynamicMax;
    }
    MQ135Chart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    MQ135Chart.update();

    smokeLevelChart.data.labels = dashboard.labels;
    smokeLevelChart.data.datasets[0].data = dashboard.smokeLevelData;
    smokeLevelChart.data.datasets[0].backgroundColor = dashboard.smokeLevelData.map(aqiColor);
    smokeLevelChart.data.datasets[0].borderColor    = dashboard.smokeLevelData.map(aqiColor);
    smokeLevelChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    smokeLevelChart.update();

    flameChart.data.labels = dashboard.labels;
    flameChart.data.datasets[0].data = dashboard.flameData;
    flameChart.data.datasets[0].backgroundColor = dashboard.flameData.map(v => parseInt(v, 10) === 1 ? palette.flame : palette.alarmOff);
    flameChart.data.datasets[0].borderColor = dashboard.flameData.map(v => parseInt(v, 10) === 1 ? palette.flame : palette.alarmOff);
    flameChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    flameChart.update();

    safetyAlarmChart.data.labels = dashboard.labels;
    safetyAlarmChart.data.datasets[0].data = dashboard.safetyAlarmData;
    safetyAlarmChart.data.datasets[0].backgroundColor = dashboard.safetyAlarmData.map(v => parseInt(v, 10) === 1 ? palette.safety : palette.alarmOff);
    safetyAlarmChart.data.datasets[0].borderColor = dashboard.safetyAlarmData.map(v => parseInt(v, 10) === 1 ? palette.safety : palette.alarmOff);
    safetyAlarmChart.options.plugins.subtitle.text = chartSubtitle(dashboard);
    safetyAlarmChart.update();
}

function updateSummary(latest) {
    document.getElementById('deviceValue').textContent = latest ? latest.device : 'N/A';
    document.getElementById('tempValue').textContent = latest ? `${latest.temp_c} °C` : 'N/A';
    document.getElementById('humidityValue').textContent = latest ? `${latest.humidity} %` : 'N/A';
    document.getElementById('heatIndexValue').textContent = latest ? `${latest.heat_index_c} °C` : 'N/A';
    document.getElementById('MQ135Value').textContent = latest ? `${latest.MQ135_raw ?? 0} / 1023` : 'N/A';
    document.getElementById('smokeLevelValue').textContent = latest ? (latest.smoke_level || 'NORMAL') : 'N/A';
    document.getElementById('flameValue').textContent = latest ? (parseInt(latest.flame_detected || 0, 10) === 1 ? 'DETECTED' : 'NORMAL') : 'N/A';
    document.getElementById('safetyAlarmValue').textContent = latest ? (parseInt(latest.safety_alarm || 0, 10) === 1 ? 'ACTIVE' : 'NORMAL') : 'N/A';
    document.getElementById('lastUpdateValue').textContent = latest ? latest.created_at : 'N/A';

    const alarmValue = document.getElementById('alarmValue');
    const smokeLevelValue = document.getElementById('smokeLevelValue');
    const flameValue = document.getElementById('flameValue');
    const safetyAlarmValue = document.getElementById('safetyAlarmValue');

    if (!latest) {
        alarmValue.textContent = 'N/A';
        alarmValue.className = 'value';
        smokeLevelValue.className = 'value';
        flameValue.className = 'value';
        safetyAlarmValue.className = 'value';
        return;
    }

    if (parseInt(latest.alarm, 10) === 1) {
        alarmValue.textContent = 'ACTIVE';
        alarmValue.className = 'value status-alarm';
    } else {
        alarmValue.textContent = 'NORMAL';
        alarmValue.className = 'value status-normal';
    }

    const smokeValue = parseInt(latest.smoke_level_value || 0, 10);
    if (smokeValue >= 2) {
        smokeLevelValue.className = 'value status-alarm';
    } else if (smokeValue === 1) {
        smokeLevelValue.className = 'value status-warning';
    } else {
        smokeLevelValue.className = 'value status-normal';
    }

    flameValue.className = parseInt(latest.flame_detected || 0, 10) === 1 ? 'value status-alarm' : 'value status-normal';
    safetyAlarmValue.className = parseInt(latest.safety_alarm || 0, 10) === 1 ? 'value status-alarm' : 'value status-normal';
}


function updateRecentSensorLogs(rows) {
    const tbody = document.getElementById('recentSensorLogsBody');
    if (!tbody) return;

    tbody.replaceChildren();

    if (!Array.isArray(rows) || rows.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 11;
        td.textContent = 'No records found.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
    }

    rows.forEach(row => {
        const tr = document.createElement('tr');
        const values = [
            row.id,
            row.device,
            row.temp_c,
            row.humidity,
            row.heat_index_c,
            parseInt(row.alarm || 0, 10) === 1 ? 'ACTIVE' : 'NORMAL',
            row.MQ135_raw ?? '',
            row.smoke_level || 'NORMAL',
            parseInt(row.flame_detected || 0, 10) === 1 ? 'DETECTED' : 'NORMAL',
            parseInt(row.safety_alarm || 0, 10) === 1 ? 'ACTIVE' : 'NORMAL',
            row.created_at
        ];

        values.forEach((value, index) => {
            const td = document.createElement('td');
            td.textContent = value === null || value === undefined ? '' : String(value);

            if (index === 5) {
                td.className = parseInt(row.alarm || 0, 10) === 1 ? 'status-alarm' : 'status-normal';
                td.style.fontWeight = 'bold';
            }

            if (index === 7) {
                const smokeValue = parseInt(row.smoke_level_value || 0, 10);
                td.className = smokeValue >= 2 ? 'status-alarm' : (smokeValue === 1 ? 'status-warning' : 'status-normal');
                td.style.fontWeight = 'bold';
            }

            if (index === 8) {
                td.className = parseInt(row.flame_detected || 0, 10) === 1 ? 'status-alarm' : 'status-normal';
                td.style.fontWeight = 'bold';
            }

            if (index === 9) {
                td.className = parseInt(row.safety_alarm || 0, 10) === 1 ? 'status-alarm' : 'status-normal';
                td.style.fontWeight = 'bold';
            }

            tr.appendChild(td);
        });

        tbody.appendChild(tr);
    });

    const refreshText = document.getElementById('recentLogsRefreshText');
    if (refreshText) {
        const now = new Date();
        refreshText.textContent = 'Live table refresh: ' + now.toLocaleString() + '. New rows appear after the ESP8266 posts to MySQL.';
    }
}


function updateCoolingUi(control) {
    if (!control) return;
    const isAuto = control.mode === 'AUTO';
    const autoSwitch = document.getElementById('autoModeSwitch');
    const manualSwitch = document.getElementById('manualRelaySwitch');

    autoSwitch.checked = isAuto;
    autoSwitch.disabled = mustChangePassword;

    manualSwitch.checked = parseInt(control.manual_state, 10) === 1;
    manualSwitch.disabled = isAuto || mustChangePassword;

    document.getElementById('coolingModeText').textContent = control.mode;
    document.getElementById('manualStateText').textContent = parseInt(control.manual_state, 10) === 1 ? 'ON' : 'OFF';
    document.getElementById('relayStateText').textContent = parseInt(control.relay_state, 10) === 1 ? 'ON' : 'OFF';
}

async function postCooling(mode, manualState) {
    const form = new URLSearchParams();
    form.append('action', 'set_cooling');
    form.append('mode', mode);
    form.append('manual_state', manualState ? '1' : '0');
    form.append('csrf_token', csrfToken);

    const response = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: form.toString()
    });

    const data = await response.json();
    if (!response.ok || !data.ok) {
        throw new Error(data.error || 'Failed to update cooling control.');
    }
    updateCoolingUi(data.control);
}

document.getElementById('autoModeSwitch').addEventListener('change', async (e) => {
    const autoOn = e.target.checked;
    try {
        // When AUTO is selected, always clear the manual trigger request.
        await postCooling(autoOn ? 'AUTO' : 'MANUAL', autoOn ? false : document.getElementById('manualRelaySwitch').checked);
    } catch (err) {
        alert(err.message);
    }
});

document.getElementById('manualRelaySwitch').addEventListener('change', async (e) => {
    try {
        await postCooling('MANUAL', e.target.checked);
    } catch (err) {
        alert(err.message);
    }
});

async function refreshDashboard() {
    try {
        const response = await fetch(`?ajax=1&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&chart_type=${encodeURIComponent(selectedChartType)}`, {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        if (!response.ok) return;
        const data = await response.json();
        updateCharts(data.dashboard);
        updateSummary(data.dashboard.latest);
        updateRecentSensorLogs(data.history_rows);
        updateCoolingUi(data.control);
    } catch (err) {
        console.error(err);
    }
}

const savedTheme = localStorage.getItem('tempDashTheme') || 'dark';
applyTheme(savedTheme);
document.getElementById('themeToggleBtn').addEventListener('click', () => {
    applyTheme(currentTheme() === 'light' ? 'dark' : 'light');
});

const chartTypeSelect = document.getElementById('chart_type');
if (chartTypeSelect) {
    chartTypeSelect.addEventListener('change', (e) => {
        selectedChartType = e.target.value;
        rebuildCharts(latestDashboard);
    });
}

initCharts();
updateCoolingUi(initialControl);
updateChartTheme();
setInterval(refreshDashboard, 5000);
</script>
</body>
</html>
