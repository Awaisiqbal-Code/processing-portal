<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Gatekeeper: Verifies if REST API is enabled globally.
 * Returns 404 if setting 'api_enabled' is 0 or unset.
 */
function check_api_enabled(): void {
    $enabled = (int)setting('api_enabled', 0);
    if ($enabled !== 1) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Not Found']);
        exit;
    }
}

/**
 * Generates a signed, stateless API token (expires after 12 hours).
 */
function generate_api_token(array $user, int $validitySeconds = 43200): string {
    global $CONFIG;
    $secret = $CONFIG['session_secret'] ?? 'ptp_default_secret_key_change_in_prod';
    $payload = [
        'uid'  => (int)$user['id'],
        'role' => (string)$user['role'],
        'iat'  => time(),
        'exp'  => time() + $validitySeconds,
    ];
    $data = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $secret, true)), '+/', '-_'), '=');
    return $data . '.' . $sig;
}

/**
 * Validates a signed API token and returns the user payload, or null if invalid/expired.
 */
function verify_api_token(string $token): ?array {
    global $CONFIG;
    $secret = $CONFIG['session_secret'] ?? 'ptp_default_secret_key_change_in_prod';
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$data, $sig] = $parts;
    $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expectedSig, $sig)) return null;
    $raw = base64_decode(strtr($data, '-_', '+/'));
    if (!$raw) return null;
    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['uid']) || empty($payload['exp'])) return null;
    if ($payload['exp'] < time()) return null;
    return $payload;
}

/**
 * Authenticates incoming API request via Bearer token or active session.
 */
function require_api_user(?string $requiredRole = null): array {
    check_api_enabled();
    header('Content-Type: application/json; charset=utf-8');

    // 1. Check Bearer token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with(trim($authHeader), 'Bearer ')) {
        $token = trim(substr(trim($authHeader), 7));
        $payload = verify_api_token($token);
        if ($payload) {
            $user = q("SELECT id, name, username, role, student_id, active FROM users WHERE id = ?", [$payload['uid']])->fetch();
            if ($user && (int)$user['active'] === 1) {
                if ($requiredRole !== null && !role_satisfies($user['role'], $requiredRole)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Permission denied: Insufficient role permissions.']);
                    exit;
                }
                return $user;
            }
        }
    }

    // 2. Check active session user
    $sessionUser = current_user($requiredRole);
    if ($sessionUser) {
        return $sessionUser;
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required. Provide a valid Bearer token or session.']);
    exit;
}

/**
 * Standardized JSON input helper (handles application/json body and regular POST).
 */
function api_input(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower($contentType), 'application/json')) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw ?: '{}', true);
        if (is_array($json)) {
            return array_merge($_GET, $json);
        }
    }
    return array_merge($_GET, $_POST);
}
