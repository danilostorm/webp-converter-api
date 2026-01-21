<?php
namespace App;

/**
 * Security utilities (SSRF protection, validation)
 */
class Security {
    // Private IP ranges for SSRF protection
    private static $blockedRanges = [
        '127.0.0.0/8',      // Loopback
        '10.0.0.0/8',       // Private
        '172.16.0.0/12',    // Private
        '192.168.0.0/16',   // Private
        '169.254.0.0/16',   // Link-local
        '::1/128',          // IPv6 loopback
        'fc00::/7',         // IPv6 private
        'fe80::/10'         // IPv6 link-local
    ];
    
    public static function isValidImageUrl($url) {
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $parsed = parse_url($url);
        
        // Check scheme
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
            return false;
        }
        
        // Check host
        if (!isset($parsed['host'])) {
            return false;
        }
        
        // Resolve hostname to IP
        $ip = gethostbyname($parsed['host']);
        
        // Check if IP is in blocked ranges
        if (self::isIpBlocked($ip)) {
            Logger::warning("Blocked SSRF attempt: {$url} resolves to {$ip}");
            return false;
        }
        
        return true;
    }
    
    private static function isIpBlocked($ip) {
        // Check if localhost
        if ($ip === 'localhost' || $ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        
        // Check private ranges
        foreach (self::$blockedRanges as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function ipInRange($ip, $range) {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $bits) = explode('/', $range);
        
        // Handle IPv6
        if (strpos($subnet, ':') !== false) {
            return false; // Simplified: skip IPv6 range check
        }
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        
        return ($ip & $mask) === ($subnet & $mask);
    }
    
    public static function isValidImageMime($mime) {
        $allowed = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif'
        ];
        
        return in_array($mime, $allowed);
    }
    
    public static function sanitizeFilename($filename) {
        // Remove path traversal
        $filename = basename($filename);
        
        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Limit length
        if (strlen($filename) > 255) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = substr($filename, 0, 250);
            $filename = $name . '.' . $ext;
        }
        
        return $filename;
    }
    
    public static function getClientIp() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        
        return '0.0.0.0';
    }
    
    public static function downloadImage($url, $outputPath) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'WebP-Converter-API/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE => \Config::get('max_upload_size', 15728640)
        ]);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($data === false || $httpCode !== 200) {
            Logger::error("Failed to download image from {$url}: HTTP {$httpCode}, {$error}");
            return false;
        }
        
        // Validate MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_buffer($finfo, $data);
        finfo_close($finfo);
        
        if (!self::isValidImageMime($mime)) {
            Logger::error("Downloaded file has invalid MIME type: {$mime}");
            return false;
        }
        
        return file_put_contents($outputPath, $data) !== false;
    }
}
