<?php
declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Block private app files and database
if (preg_match('#^/(app|database|\.|\.git)#', $uri) || preg_match('#\.(sql|sqlite|ini|env|log)$#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$path = __DIR__ . $uri;

// If accessing a directory, look for index.php
if (is_dir($path)) {
    $index = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($index)) {
        require $index;
        return true;
    }
}

// Serve existing file
if (is_file($path)) {
    return false;
}

// Fallback to 404
http_response_code(404);
echo 'Not Found';
return true;
