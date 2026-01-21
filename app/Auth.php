<?php
namespace App;

/**
 * API Key authentication
 */
class Auth {
    public function getApiKeyFromRequest() {
        // Check X-API-Key header
        $headers = getallheaders();
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }
        if (isset($headers['X-Api-Key'])) {
            return $headers['X-Api-Key'];
        }
        
        // Check query parameter (less secure, but allowed)
        if (isset($_GET['api_key'])) {
            return $_GET['api_key'];
        }
        
        return null;
    }
    
    public function validateApiKey($key) {
        if (empty($key)) {
            return false;
        }
        
        $hash = hash('sha256', $key);
        $validKeys = \Config::get('api_keys', []);
        
        return isset($validKeys[$hash]) && $validKeys[$hash] === true;
    }
    
    public function generateApiKey() {
        return bin2hex(random_bytes(32));
    }
    
    public function hashApiKey($key) {
        return hash('sha256', $key);
    }
}
