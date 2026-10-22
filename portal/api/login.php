<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api_auth.php';

check_api_enabled();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = api_input();
$username = strtolower(trim((string)($input['username'] ?? '')));
$password = (string)($input['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
    exit;
}

// Rate limiting check via login_attempts (max 5 failed attempts in 15 mins)
if (rate_limited($username)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many failed login attempts. Please wait 15 minutes before trying again.']);
    exit;
}

$user = q("SELECT id, name, username, password_hash, role, student_id, active FROM users WHERE LOWER(username) = ?", [$username])->fetch();

if (!$user || !password_verify($password, (string)$user['password_hash'])) {
    q('INSERT INTO login_attempts (ip, username, created_at) VALUES (?,?,?)', [client_ip(), $username, now()]);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
    exit;
}

if ((int)$user['active'] !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account is deactivated. Contact administration.']);
    exit;
}

// Clear failed attempts on successful login
q('DELETE FROM login_attempts WHERE username = ?', [$username]);

// Update last login timestamp
q("UPDATE users SET last_login = ? WHERE id = ?", [now(), $user['id']]);

// Issue 12-hour API token
$token = generate_api_token($user);

echo json_encode([
    'success' => true,
    'token'   => $token,
    'user'    => [
        'id'         => (int)$user['id'],
        'name'       => $user['name'],
        'username'   => $user['username'],
        'role'       => $user['role'],
        'student_id' => $user['student_id'] !== null ? (int)$user['student_id'] : null,
    ],
]);
