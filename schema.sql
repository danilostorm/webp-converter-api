-- WebP Converter API - Database Schema
-- MySQL 5.7+ / MariaDB compatible

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID do job',
  `status` ENUM('queued', 'processing', 'done', 'error') NOT NULL DEFAULT 'queued',
  `source_type` ENUM('url', 'upload') NOT NULL COMMENT 'Origem da imagem',
  `source_url` VARCHAR(2048) DEFAULT NULL COMMENT 'URL da imagem (se source_type=url)',
  `input_path` VARCHAR(512) DEFAULT NULL COMMENT 'Caminho local do arquivo original',
  `output_path` VARCHAR(512) DEFAULT NULL COMMENT 'Caminho local do webp gerado',
  `filename` VARCHAR(255) DEFAULT NULL COMMENT 'Nome do arquivo desejado',
  
  -- Parâmetros de conversão
  `quality` TINYINT UNSIGNED NOT NULL DEFAULT 85 COMMENT 'Qualidade webp (0-100)',
  `width` INT UNSIGNED DEFAULT NULL COMMENT 'Largura desejada (null = manter original)',
  `height` INT UNSIGNED DEFAULT NULL COMMENT 'Altura desejada (null = manter original)',
  `fit` ENUM('contain', 'cover', 'inside', 'outside') DEFAULT NULL COMMENT 'Modo de ajuste ao redimensionar',
  `strip_metadata` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Remover metadados EXIF',
  
  -- Timestamps
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `started_at` DATETIME DEFAULT NULL COMMENT 'Quando o processamento iniciou',
  `finished_at` DATETIME DEFAULT NULL COMMENT 'Quando o processamento terminou',
  
  -- Lock para worker (evitar processamento duplicado)
  `locked_at` DATETIME DEFAULT NULL,
  `locked_by` VARCHAR(64) DEFAULT NULL COMMENT 'PID do worker que pegou o job',
  
  -- Resultados e metadados
  `error_message` TEXT DEFAULT NULL,
  `input_mime` VARCHAR(64) DEFAULT NULL,
  `input_size` INT UNSIGNED DEFAULT NULL COMMENT 'Tamanho do arquivo original em bytes',
  `output_size` INT UNSIGNED DEFAULT NULL COMMENT 'Tamanho do webp em bytes',
  `processing_time_ms` INT UNSIGNED DEFAULT NULL COMMENT 'Tempo de processamento em milissegundos',
  
  -- Auditoria e segurança
  `client_ip` VARCHAR(45) DEFAULT NULL COMMENT 'IP do cliente que criou o job',
  `api_key_hash` VARCHAR(64) NOT NULL COMMENT 'Hash da API key usada',
  
  -- Indexes
  INDEX `idx_status_created` (`status`, `created_at`),
  INDEX `idx_api_key` (`api_key_hash`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_locked` (`locked_at`, `locked_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para rate limiting
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(128) NOT NULL COMMENT 'api_key:ip ou apenas api_key',
  `requests` INT UNSIGNED NOT NULL DEFAULT 1,
  `window_start` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY `idx_identifier_window` (`identifier`, `window_start`),
  INDEX `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para API keys
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `key_hash` VARCHAR(64) NOT NULL UNIQUE COMMENT 'SHA256 da API key',
  `key_prefix` VARCHAR(16) NOT NULL COMMENT 'Prefixo da key para identificação',
  `name` VARCHAR(128) DEFAULT NULL COMMENT 'Nome descritivo da key',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `rate_limit` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Requests por minuto',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  
  INDEX `idx_key_hash` (`key_hash`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
