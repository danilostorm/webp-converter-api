<?php
/**
 * WebP Converter API - Database Schema
 */

return [
    'schema' => "
        CREATE TABLE IF NOT EXISTS jobs (
            id CHAR(36) PRIMARY KEY,
            status ENUM('queued', 'processing', 'done', 'error') NOT NULL DEFAULT 'queued',
            source_type ENUM('url', 'upload') NOT NULL,
            source_url TEXT,
            input_path VARCHAR(512),
            output_path VARCHAR(512),
            quality TINYINT UNSIGNED NOT NULL DEFAULT 85,
            width INT UNSIGNED,
            height INT UNSIGNED,
            fit ENUM('contain', 'cover', 'inside', 'outside') NOT NULL DEFAULT 'contain',
            strip_metadata BOOLEAN NOT NULL DEFAULT TRUE,
            filename VARCHAR(255),
            input_mime VARCHAR(50),
            input_size INT UNSIGNED,
            output_size INT UNSIGNED,
            client_ip VARCHAR(45),
            api_key_hash VARCHAR(64),
            error_message TEXT,
            locked_at DATETIME,
            locked_by VARCHAR(64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            started_at DATETIME,
            finished_at DATETIME,
            INDEX idx_status_created (status, created_at),
            INDEX idx_api_key (api_key_hash),
            INDEX idx_locked (locked_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(128) NOT NULL,
            requests INT UNSIGNED NOT NULL DEFAULT 1,
            window_start DATETIME NOT NULL,
            UNIQUE KEY idx_identifier_window (identifier, window_start),
            INDEX idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    "
];
