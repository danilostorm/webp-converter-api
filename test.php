<?php
/**
 * Diagnostic Test File
 * Acesse: https://img.hoststorm.cloud/test.php
 * DELETE este arquivo após resolver o problema!
 */

header('Content-Type: application/json');

echo json_encode([
    'status' => 'OK',
    'message' => 'PHP is working!',
    'php_version' => PHP_VERSION,
    'server' => [
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'unknown'
    ],
    'mod_rewrite' => function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules()) : 'unknown',
    'files_exist' => [
        'index.php' => file_exists(__DIR__ . '/index.php'),
        '.htaccess' => file_exists(__DIR__ . '/.htaccess'),
        'config.php' => file_exists(__DIR__ . '/config/config.php'),
        'bootstrap.php' => file_exists(__DIR__ . '/app/bootstrap.php')
    ],
    'permissions' => [
        'storage_writable' => is_writable(__DIR__ . '/storage'),
        'config_readable' => is_readable(__DIR__ . '/config')
    ]
], JSON_PRETTY_PRINT);
