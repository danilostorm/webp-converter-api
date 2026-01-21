<?php
namespace App;

/**
 * Simple file-based logger
 */
class Logger {
    private static function write($level, $message) {
        $logLevel = \Config::get('log_level', 'error');
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        
        if ($levels[$level] < $levels[$logLevel]) {
            return;
        }
        
        $logPath = \Config::get('log_path', APP_ROOT . '/storage/logs/app.log');
        $logDir = dirname($logPath);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $level = strtoupper($level);
        $line = "[{$timestamp}] {$level}: {$message}" . PHP_EOL;
        
        file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
    
    public static function debug($message) {
        self::write('debug', $message);
    }
    
    public static function info($message) {
        self::write('info', $message);
    }
    
    public static function warning($message) {
        self::write('warning', $message);
    }
    
    public static function error($message) {
        self::write('error', $message);
    }
}
