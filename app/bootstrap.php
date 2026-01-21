<?php
/**
 * WebP Converter API - Bootstrap
 * 
 * Initializes application environment and loads core classes
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

// Load configuration
class Config {
    private static $config = null;
    
    public static function load() {
        $configFile = APP_ROOT . '/config/config.php';
        if (!file_exists($configFile)) {
            http_response_code(500);
            die(json_encode(['error' => ['code' => 'CONFIG_MISSING', 'message' => 'Configuration file not found. Run /install first.']]));
        }
        self::$config = require $configFile;
    }
    
    public static function get($key, $default = null) {
        if (self::$config === null) {
            self::load();
        }
        
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    public static function all() {
        if (self::$config === null) {
            self::load();
        }
        return self::$config;
    }
}

// Database connection singleton
class DB {
    private static $connection = null;
    
    public static function connect() {
        if (self::$connection !== null) {
            return self::$connection;
        }
        
        $config = Config::get('db');
        
        try {
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            self::$connection = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => ['code' => 'DB_CONNECTION_FAILED', 'message' => 'Database connection failed']]));
        }
        
        return self::$connection;
    }
    
    public static function query($sql, $params = []) {
        $conn = self::connect();
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public static function fetch($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }
    
    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }
    
    public static function insert($sql, $params = []) {
        self::query($sql, $params);
        return self::connect()->lastInsertId();
    }
}

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = APP_ROOT . '/app/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Set timezone
date_default_timezone_set('America/Sao_Paulo');
