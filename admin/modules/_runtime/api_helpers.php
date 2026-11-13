<?php
/**
 * Luminal CMS — API Response Helpers
 *
 * Provides the ok()/bad() response pattern used by thoht.io modules.
 * Optional include — modules can define these locally or use this shared version.
 */
declare(strict_types=1);

if (!function_exists('ok')) {
    function ok($data = null): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('bad')) {
    function bad(string $msg, int $code = 400): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
