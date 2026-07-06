<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '1122';
const DB_NAME = 'tempLogger';

const FORCE_HTTPS = false; // set true on live HTTPS domain
const SESSION_IDLE_TIMEOUT = 600;
const SESSION_ABSOLUTE_TIMEOUT = 28800;
const PASSWORD_MIN_LENGTH = 10;
const ALLOWED_HOSTS = [];

const LOGIN_URL = 'dash.php';
const DASHBOARD_URL = 'dash.php';

function getRequestHost(): string {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    $host = strtolower(trim($host));
    if (($pos = strpos($host, ':')) !== false) {
        $host = substr($host, 0, $pos);
    }
    return $host;
}

function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
}

function enforceAllowedHosts(): void {
    if (empty(ALLOWED_HOSTS)) return;
    if (!in_array(getRequestHost(), ALLOWED_HOSTS, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden host.';
        exit;
    }
}

function forceHttpsIfNeeded(): void {
    if (!FORCE_HTTPS || isHttps()) return;
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
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
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
    $target = LOGIN_URL;
    if ($reason !== '') {
        $target .= '?reason=' . urlencode($reason);
    }
    header('Location: ' . $target);
    exit;
}

function enforceSessionPolicy(): void {
    if (!isLoggedIn()) return;

    $now = time();
    $createdAt = (int)($_SESSION['created_at'] ?? $now);
    $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
    $lastRegenerated = (int)($_SESSION['last_regenerated_at'] ?? $now);

    if (($now - $lastActivity) > SESSION_IDLE_TIMEOUT) {
        logoutAndRedirect('timeout');
    }

    if (($now - $createdAt) > SESSION_ABSOLUTE_TIMEOUT) {
        logoutAndRedirect('session_expired');
    }

    if (($now - $lastRegenerated) > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated_at'] = $now;
    }

    $_SESSION['last_activity'] = $now;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
    if (!preg_match('/\d/', $password)) {
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

if (!isLoggedIn()) {
    header('Location: ' . LOGIN_URL . '?reason=login_required');
    exit;
}

$conn = getDb();
$currentUser = $_SESSION['dashboard_user'];
$currentUserId = (int)($currentUser['id'] ?? 0);
$displayName = (string)($currentUser['full_name'] ?? $currentUser['username'] ?? 'User');
$mustChangePassword = (int)($currentUser['must_change_password'] ?? 0) === 1;

$changePasswordError = '';
$changePasswordSuccess = '';

if (isset($_SESSION['change_password_status'])) {
    $flash = $_SESSION['change_password_status'];
    unset($_SESSION['change_password_status']);
    if (($flash['type'] ?? '') === 'success') {
        $changePasswordSuccess = (string)($flash['message'] ?? '');
    } else {
        $changePasswordError = (string)($flash['message'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    requireCsrf();
    logoutAndRedirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    requireCsrf();

    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'All password fields are required.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'New password and confirm password do not match.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }

    if ($currentPassword === $newPassword) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'New password must be different from the current password.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }

    $strengthError = passwordStrengthError($newPassword);
    if ($strengthError !== '') {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => $strengthError];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }

    $stmt = $conn->prepare('SELECT password_hash FROM dashboard_users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'Unable to prepare password check.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }
    $stmt->bind_param('i', $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$account || !password_verify($currentPassword, (string)$account['password_hash'])) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'Current password is incorrect.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $mustChange = 0;
    $stmt = $conn->prepare('UPDATE dashboard_users SET password_hash = ?, must_change_password = ?, last_password_change_at = NOW() WHERE id = ?');
    if (!$stmt) {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'Unable to prepare password update.'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }
    $stmt->bind_param('sii', $newPasswordHash, $mustChange, $currentUserId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['dashboard_user']['must_change_password'] = 0;
        $_SESSION['last_regenerated_at'] = time();
        $_SESSION['change_password_status'] = ['type' => 'success', 'message' => 'Password changed successfully.'];
    } else {
        $_SESSION['change_password_status'] = ['type' => 'error', 'message' => 'Failed to change password. Please try again.'];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    exit;
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a, #111827, #1e293b);
            color: #fff;
            min-height: 100vh;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            background: rgba(15, 23, 42, 0.92);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
        }
        .brand {
            font-weight: bold;
            font-size: 18px;
        }
        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .link-btn, .submit-btn, .logout-btn {
            display: inline-block;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }
        .link-btn {
            background: rgba(59, 130, 246, 0.18);
            color: #bfdbfe;
            border: 1px solid rgba(59,130,246,0.3);
        }
        .submit-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            font-weight: bold;
        }
        .logout-btn {
            background: rgba(239, 68, 68, 0.18);
            color: #fecaca;
            border: 1px solid rgba(239,68,68,0.3);
        }
        .container {
            max-width: 760px;
            margin: 36px auto;
            padding: 0 18px;
        }
        .panel {
            background: rgba(30, 41, 59, 0.82);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 18px 40px rgba(0,0,0,0.28);
            backdrop-filter: blur(10px);
        }
        h1 {
            margin: 0 0 10px;
            font-size: 30px;
        }
        .subtext {
            color: #cbd5e1;
            margin-bottom: 22px;
            line-height: 1.5;
        }
        .notice, .error, .success {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .notice {
            background: rgba(245, 158, 11, 0.12);
            color: #fde68a;
            border: 1px solid rgba(245,158,11,0.28);
        }
        .error {
            background: rgba(239, 68, 68, 0.12);
            color: #fecaca;
            border: 1px solid rgba(239,68,68,0.28);
        }
        .success {
            background: rgba(34, 197, 94, 0.12);
            color: #bbf7d0;
            border: 1px solid rgba(34,197,94,0.28);
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }
        .field.full {
            grid-column: 1 / -1;
        }
        label {
            font-size: 14px;
            color: #e2e8f0;
            font-weight: bold;
        }
        input[type="password"], input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(15, 23, 42, 0.92);
            color: #fff;
            font-size: 14px;
            outline: none;
        }
        input:focus {
            border-color: rgba(96, 165, 250, 0.7);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.14);
        }
        .password-rules {
            margin-top: 20px;
            padding: 16px;
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .password-rules ul {
            margin: 10px 0 0 18px;
            color: #cbd5e1;
            line-height: 1.6;
        }
        .meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .meta-card {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .meta-card .label {
            display: block;
            color: #94a3b8;
            font-size: 13px;
            margin-bottom: 5px;
        }
        .meta-card .value {
            font-size: 16px;
            font-weight: bold;
        }
        @media (max-width: 700px) {
            .grid, .meta { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div>
            <div class="brand">Secure Dashboard</div>
            <div style="color:#94a3b8;font-size:13px;">Dedicated change-password page</div>
        </div>
        <div class="top-actions">
            <a class="link-btn" href="<?php echo e(DASHBOARD_URL); ?>">Back to dashboard</a>
            <form method="post" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>

    <div class="container">
        <div class="panel">
            <h1>Change Password</h1>
            <div class="subtext">
                Signed in as <strong><?php echo e($displayName); ?></strong>.
                Use a strong password before continuing to manage the dashboard.
            </div>

            <?php if ($mustChangePassword): ?>
                <div class="notice">Your account is marked to change the password before normal dashboard controls are allowed.</div>
            <?php endif; ?>

            <?php if ($changePasswordError !== ''): ?>
                <div class="error"><?php echo e($changePasswordError); ?></div>
            <?php endif; ?>

            <?php if ($changePasswordSuccess !== ''): ?>
                <div class="success"><?php echo e($changePasswordSuccess); ?></div>
            <?php endif; ?>

            <div class="meta">
                <div class="meta-card">
                    <span class="label">Username</span>
                    <span class="value"><?php echo e((string)($currentUser['username'] ?? '')); ?></span>
                </div>
                <div class="meta-card">
                    <span class="label">Password policy</span>
                    <span class="value">Minimum <?php echo PASSWORD_MIN_LENGTH; ?> chars</span>
                </div>
            </div>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="grid">
                    <div class="field full">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    <div class="field">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Update Password</button>
            </form>

            <div class="password-rules">
                <strong>Password rules</strong>
                <ul>
                    <li>At least <?php echo PASSWORD_MIN_LENGTH; ?> characters</li>
                    <li>At least one uppercase letter</li>
                    <li>At least one lowercase letter</li>
                    <li>At least one number</li>
                    <li>At least one special character</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
