<?php
namespace App;

/**
 * Standardized JSON response handler
 */
class Response {
    public static function json($data, $status = 200) {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public static function error($code, $message, $status = 400, $details = null) {
        Logger::error("[{$code}] {$message}" . ($details ? ': ' . json_encode($details) : ''));
        
        $response = [
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
        
        if ($details !== null) {
            $response['error']['details'] = $details;
        }
        
        self::json($response, $status);
    }
}
