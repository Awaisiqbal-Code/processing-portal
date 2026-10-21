<?php
declare(strict_types=1);

$portalRoot = realpath(__DIR__ . '/../portal');
$_SERVER['DOCUMENT_ROOT'] = $portalRoot;

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($uri === '' || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

// Block private app files and raw database access
if (preg_match('#^/(app|database|\.|\.git)#i', $uri) || preg_match('#\.(sql|sqlite|ini|env|log)$#i', $uri)) {
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
