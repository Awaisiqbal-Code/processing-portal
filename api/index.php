<?php
declare(strict_types=1);

/**
 * Production Condition 3: Vercel Ephemeral Storage Safeguard
 *
 * Vercel serverless functions have an ephemeral `/tmp` filesystem where SQLite
 * writes and uploaded documents are lost on cold start. All live traffic on
 * Vercel is redirected (`302 Found`) to the primary persistent Railway deployment
 * unless `?vercel_readonly=1` is passed (which enforces strict read-only mode).
 */
$railwayBaseUrl = rtrim((string)(getenv('RAILWAY_PUBLIC_URL') ?: 'https://processing-portal-production.up.railway.app'), '/');
$isVercelRuntime = (bool)(getenv('VERCEL') || getenv('VERCEL_ENV') || str_contains($_SERVER['HTTP_HOST'] ?? '', '.vercel.app'));

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($uri === '' || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

if ($isVercelRuntime && ($_GET['vercel_readonly'] ?? '') !== '1') {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $targetUrl = $railwayBaseUrl . $uri . ($qs !== '' ? '?' . $qs : '');
    // 307 Temporary Redirect preserves HTTP method (POST/PUT/DELETE) and request payload for API calls
    $redirectCode = (str_starts_with($uri, '/api/') || strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') ? 307 : 302;
    header('Location: ' . $targetUrl, true, $redirectCode);
    exit;
}

if ($isVercelRuntime && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Write operations are disabled on the Vercel read-only mirror. Please use the primary Railway portal: ' . $railwayBaseUrl;
    exit;
}

$portalRoot = realpath(__DIR__ . '/../portal');
$_SERVER['DOCUMENT_ROOT'] = $portalRoot;

// Block private app files, secure storage and raw database access
if (preg_match('#^/(app|storage_secure|database|\.|\.git)#i', $uri) || preg_match('#\.(sql|sqlite|ini|env|log)$#i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$target = $portalRoot . $uri;

// If accessing a directory (e.g. /, /admin, /officer, /student), resolve index.php
if (is_dir($target)) {
    $index = rtrim($target, '/\\') . '/index.php';
    if (is_file($index)) {
        $target = $index;
        $uri = rtrim($uri, '/') . '/index.php';
    }
}

if (is_file($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    // Serve static assets (CSS, JS, images, fonts, PDFs)
    if ($ext !== 'php') {
        $mimes = [
            'css'   => 'text/css; charset=utf-8',
            'js'    => 'application/javascript; charset=utf-8',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'pdf'   => 'application/pdf',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=3600');
        readfile($target);
        exit;
    }

    $_SERVER['SCRIPT_NAME'] = $uri;
    $_SERVER['SCRIPT_FILENAME'] = $target;
    chdir(dirname($target));
    require $target;
    exit;
}

http_response_code(404);
echo 'Not Found';
