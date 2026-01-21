<?php
namespace App;

/**
 * Rate limiter using database
 */
class RateLimiter {
    private $limit;
    private $window = 60; // seconds
    
    public function __construct() {
        $this->limit = \Config::get('rate_limit', 60);
    }
    
    public function checkLimit($apiKey, $clientIp) {
        $identifier = hash('sha256', $apiKey . ':' . $clientIp);
        $windowStart = date('Y-m-d H:i:00'); // Current minute
        
        // Clean old windows
        $this->cleanup();
        
        // Get current count
        $sql = "SELECT requests FROM rate_limits WHERE identifier = ? AND window_start = ?";
        $row = \DB::fetch($sql, [$identifier, $windowStart]);
        
        if ($row) {
            $count = (int)$row['requests'];
            
            if ($count >= $this->limit) {
                return false;
            }
            
            // Increment
            $sql = "UPDATE rate_limits SET requests = requests + 1 WHERE identifier = ? AND window_start = ?";
            \DB::query($sql, [$identifier, $windowStart]);
        } else {
            // Insert new record
            $sql = "INSERT INTO rate_limits (identifier, requests, window_start) VALUES (?, 1, ?)";
            \DB::query($sql, [$identifier, $windowStart]);
        }
        
        return true;
    }
    
    private function cleanup() {
        // Delete records older than 5 minutes
        $threshold = date('Y-m-d H:i:s', time() - 300);
        $sql = "DELETE FROM rate_limits WHERE window_start < ?";
        \DB::query($sql, [$threshold]);
    }
}
