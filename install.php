<?php
/**
 * WebP Converter API - Instalador Web
 * Executa verificações de ambiente, cria banco de dados e gera API key
 */

// Define constantes básicas
define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . '/storage');

$step = $_GET['step'] ?? 'welcome';
$errors = [];
$warnings = [];

// Funções auxiliares
function checkRequirements() {
    $checks = [];
    
    // PHP Version
    $checks['php_version'] = [
        'name' => 'PHP Version >= 8.1',
        'status' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'value' => PHP_VERSION,
        'required' => true
    ];
    
    // PDO MySQL
    $checks['pdo_mysql'] = [
        'name' => 'PDO MySQL Extension',
        'status' => extension_loaded('pdo_mysql'),
        'value' => extension_loaded('pdo_mysql') ? 'Installed' : 'Not installed',
        'required' => true
    ];
    
    // cURL
    $checks['curl'] = [
        'name' => 'cURL Extension',
        'status' => extension_loaded('curl'),
        'value' => extension_loaded('curl') ? 'Installed' : 'Not installed',
        'required' => true
    ];
    
    // Fileinfo
    $checks['fileinfo'] = [
        'name' => 'Fileinfo Extension',
        'status' => extension_loaded('fileinfo'),
        'value' => extension_loaded('fileinfo') ? 'Installed' : 'Not installed',
        'required' => true
    ];
    
    // GD
    $checks['gd'] = [
        'name' => 'GD Extension',
        'status' => extension_loaded('gd'),
        'value' => extension_loaded('gd') ? 'Installed' : 'Not installed',
        'required' => false,
        'note' => 'Required if Imagick is not available'
    ];
    
    // Imagick
    $checks['imagick'] = [
        'name' => 'Imagick Extension (Preferred)',
        'status' => extension_loaded('imagick'),
        'value' => extension_loaded('imagick') ? 'Installed' : 'Not installed',
        'required' => false,
        'note' => 'Recommended for better WebP quality'
    ];
    
    // WebP support in GD
    if (extension_loaded('gd')) {
        $gdInfo = gd_info();
        $checks['gd_webp'] = [
            'name' => 'GD WebP Support',
            'status' => !empty($gdInfo['WebP Support']),
            'value' => !empty($gdInfo['WebP Support']) ? 'Supported' : 'Not supported',
            'required' => false
        ];
    }
    
    // cwebp binary (apenas se shell_exec estiver habilitado)
    $cwebpPath = '';
    $shellExecDisabled = false;
    
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        try {
            $cwebpPath = @shell_exec('which cwebp 2>/dev/null');
            if ($cwebpPath) {
                $cwebpPath = trim($cwebpPath);
            }
        } catch (Exception $e) {
            $cwebpPath = '';
        }
    } else {
        $shellExecDisabled = true;
    }
    
    $checks['cwebp'] = [
        'name' => 'cwebp Binary (Optional)',
        'status' => !empty($cwebpPath),
        'value' => $shellExecDisabled ? 'shell_exec disabled' : ($cwebpPath ?: 'Not found'),
        'required' => false,
        'note' => 'Fallback option for WebP conversion (optional)'
    ];
    
    // Permissões de pasta
    $storageDirs = ['storage', 'storage/incoming', 'storage/output', 'storage/logs'];
    foreach ($storageDirs as $dir) {
        $path = BASE_PATH . '/' . $dir;
        $writable = is_dir($path) && is_writable($path);
        $checks['dir_' . str_replace('/', '_', $dir)] = [
            'name' => "Directory {$dir} writable",
            'status' => $writable,
            'value' => $writable ? 'Writable' : 'Not writable',
            'required' => true
        ];
    }
    
    // Config writable
    $configPath = BASE_PATH . '/config';
    $checks['config_writable'] = [
        'name' => 'Config directory writable',
        'status' => is_writable($configPath),
        'value' => is_writable($configPath) ? 'Writable' : 'Not writable',
        'required' => true
    ];
    
    // Verifica se tem pelo menos Imagick OU GD com WebP
    $hasImageProcessor = false;
    if (extension_loaded('imagick')) {
        $hasImageProcessor = true;
    } elseif (extension_loaded('gd')) {
        $gdInfo = gd_info();
        if (!empty($gdInfo['WebP Support'])) {
            $hasImageProcessor = true;
        }
    }
    
    $checks['image_processor'] = [
        'name' => 'Image Processor Available',
        'status' => $hasImageProcessor,
        'value' => $hasImageProcessor ? 'OK (Imagick or GD+WebP)' : 'Missing',
        'required' => true,
        'note' => 'Need Imagick OR GD with WebP support'
    ];
    
    return $checks;
}

function generateApiKey() {
    return 'wca_' . bin2hex(random_bytes(32));
}

function createConfigFile($dbConfig, $apiKey) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . $host;
    
    $configContent = "<?php\n\nreturn [\n";
    $configContent .= "    'app_name' => 'WebP Converter API',\n";
    $configContent .= "    'base_url' => '{$baseUrl}',\n";
    $configContent .= "    \n";
    $configContent .= "    'database' => [\n";
    $configContent .= "        'host' => '{$dbConfig['host']}',\n";
    $configContent .= "        'port' => '{$dbConfig['port']}',\n";
    $configContent .= "        'database' => '{$dbConfig['database']}',\n";
    $configContent .= "        'username' => '{$dbConfig['username']}',\n";
    $configContent .= "        'password' => '{$dbConfig['password']}',\n";
    $configContent .= "        'charset' => 'utf8mb4',\n";
    $configContent .= "    ],\n";
    $configContent .= "    \n";
    $configContent .= "    'storage' => [\n";
    $configContent .= "        'path' => __DIR__ . '/../storage',\n";
    $configContent .= "        'max_file_size' => 15 * 1024 * 1024, // 15MB\n";
    $configContent .= "    ],\n";
    $configContent .= "    \n";
    $configContent .= "    'rate_limit' => [\n";
    $configContent .= "        'default' => 60, // requests per minute\n";
    $configContent .= "        'window' => 60, // seconds\n";
    $configContent .= "    ],\n";
    $configContent .= "    \n";
    $configContent .= "    'conversion' => [\n";
    $configContent .= "        'default_quality' => 85,\n";
    $configContent .= "        'max_width' => 4096,\n";
    $configContent .= "        'max_height' => 4096,\n";
    $configContent .= "    ],\n";
    $configContent .= "];\n";
    
    return file_put_contents(BASE_PATH . '/config/config.php', $configContent);
}

// Processa instalação
if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbConfig = [
        'host' => $_POST['db_host'] ?? 'localhost',
        'port' => $_POST['db_port'] ?? '3306',
        'database' => $_POST['db_name'] ?? '',
        'username' => $_POST['db_user'] ?? '',
        'password' => $_POST['db_pass'] ?? ''
    ];
    
    try {
        // Conecta ao banco
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Cria database se não existir
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbConfig['database']}`");
        
        // Executa schema
        $schema = file_get_contents(BASE_PATH . '/schema.sql');
        $pdo->exec($schema);
        
        // Gera e salva API key
        $apiKey = generateApiKey();
        $keyHash = hash('sha256', $apiKey);
        $keyPrefix = substr($apiKey, 0, 10);
        
        $stmt = $pdo->prepare("
            INSERT INTO api_keys (key_hash, key_prefix, name, is_active, rate_limit) 
            VALUES (:hash, :prefix, :name, 1, 60)
        ");
        $stmt->execute([
            'hash' => $keyHash,
            'prefix' => $keyPrefix,
            'name' => 'Default API Key'
        ]);
        
        // Cria arquivo de config
        createConfigFile($dbConfig, $apiKey);
        
        // Redireciona para sucesso
        header('Location: install.php?step=success&key=' . urlencode($apiKey));
        exit;
        
    } catch (Exception $e) {
        $errors[] = "Installation failed: " . $e->getMessage();
        $step = 'database';
    }
}

// HTML
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebP Converter API - Installer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 700px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .check-list {
            margin: 20px 0;
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin: 8px 0;
            border-radius: 6px;
            background: #f8f9fa;
        }
        .check-item.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .check-item.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .check-item.warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .check-icon {
            margin-right: 12px;
            font-size: 20px;
        }
        .check-info {
            flex: 1;
        }
        .check-name {
            font-weight: 600;
            color: #333;
        }
        .check-value {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .check-note {
            font-size: 11px;
            color: #999;
            font-style: italic;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"],
        input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn {
            background: #667eea;
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
            width: 100%;
            margin-top: 10px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .success-box {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 20px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .api-key {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 15px 0;
            border: 2px dashed #667eea;
        }
        .steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-bottom: 3px solid #e0e0e0;
            color: #999;
        }
        .step.active {
            border-bottom-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        .step.completed {
            border-bottom-color: #28a745;
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($step === 'welcome'): ?>
            <h1>WebP Converter API</h1>
            <p class="subtitle">Bem-vindo ao instalador</p>
            
            <p style="margin: 20px 0; line-height: 1.6; color: #666;">
                Este instalador irá verificar os requisitos do sistema, configurar o banco de dados
                e gerar sua chave de API.
            </p>
            
            <a href="?step=requirements" class="btn">Iniciar Instalação</a>
            
        <?php elseif ($step === 'requirements'): ?>
            <?php $checks = checkRequirements(); ?>
            <?php $canContinue = true; ?>
            
            <div class="steps">
                <div class="step completed">1. Welcome</div>
                <div class="step active">2. Requirements</div>
                <div class="step">3. Database</div>
                <div class="step">4. Complete</div>
            </div>
            
            <h1>System Requirements</h1>
            <p class="subtitle">Verificando compatibilidade do servidor</p>
            
            <div class="check-list">
                <?php foreach ($checks as $check): ?>
                    <?php 
                        $class = $check['status'] ? 'success' : ($check['required'] ? 'error' : 'warning');
                        $icon = $check['status'] ? '✓' : ($check['required'] ? '✗' : '⚠');
                        if (!$check['status'] && $check['required']) $canContinue = false;
                    ?>
                    <div class="check-item <?= $class ?>">
                        <span class="check-icon"><?= $icon ?></span>
                        <div class="check-info">
                            <div class="check-name"><?= $check['name'] ?></div>
                            <div class="check-value"><?= $check['value'] ?></div>
                            <?php if (!empty($check['note'])): ?>
                                <div class="check-note"><?= $check['note'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($canContinue): ?>
                <a href="?step=database" class="btn">Continuar</a>
            <?php else: ?>
                <div class="error-box">
                    <strong>Requisitos não atendidos!</strong><br>
                    Por favor, instale as extensões necessárias e ajuste as permissões antes de continuar.
                </div>
                <a href="?step=requirements" class="btn btn-secondary">Verificar Novamente</a>
            <?php endif; ?>
            
        <?php elseif ($step === 'database'): ?>
            <div class="steps">
                <div class="step completed">1. Welcome</div>
                <div class="step completed">2. Requirements</div>
                <div class="step active">3. Database</div>
                <div class="step">4. Complete</div>
            </div>
            
            <h1>Database Configuration</h1>
            <p class="subtitle">Configure a conexão com o banco de dados MySQL</p>
            
            <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="?step=install">
                <div class="form-group">
                    <label>Host do Banco de Dados</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                
                <div class="form-group">
                    <label>Porta</label>
                    <input type="number" name="db_port" value="3306" required>
                </div>
                
                <div class="form-group">
                    <label>Nome do Banco</label>
                    <input type="text" name="db_name" value="webp_converter" required>
                </div>
                
                <div class="form-group">
                    <label>Usuário</label>
                    <input type="text" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="db_pass">
                </div>
                
                <button type="submit" class="btn">Instalar</button>
            </form>
            
        <?php elseif ($step === 'success'): ?>
            <div class="steps">
                <div class="step completed">1. Welcome</div>
                <div class="step completed">2. Requirements</div>
                <div class="step completed">3. Database</div>
                <div class="step active">4. Complete</div>
            </div>
            
            <h1>✓ Instalação Concluída!</h1>
            <p class="subtitle">Sua API WebP Converter está pronta para uso</p>
            
            <div class="success-box">
                <strong>✓ Banco de dados criado e configurado</strong><br>
                <strong>✓ Tabelas criadas com sucesso</strong><br>
                <strong>✓ API Key gerada</strong>
            </div>
            
            <h3 style="margin: 25px 0 10px 0; color: #333;">Sua API Key:</h3>
            <div class="api-key">
                <?= htmlspecialchars($_GET['key'] ?? 'ERROR') ?>
            </div>
            <p style="color: #dc3545; font-size: 13px; margin: 10px 0;">
                <strong>⚠ IMPORTANTE:</strong> Salve esta chave em local seguro! Ela não será mostrada novamente.
            </p>
            
            <h3 style="margin: 30px 0 15px 0; color: #333;">Próximos Passos:</h3>
            <ol style="margin-left: 20px; line-height: 2; color: #666;">
                <li>Delete o arquivo <code>install.php</code> por segurança</li>
                <li>Configure o cron para processar jobs:
                    <div class="api-key" style="margin: 10px 0;">* * * * * cd <?= BASE_PATH ?> && php worker.php</div>
                </li>
                <li>Teste a API com um request GET para <code>/api/v1/health</code></li>
                <li>Integre com n8n usando a documentação no README.md</li>
            </ol>
            
            <a href="/api/v1/health" class="btn" style="margin-top: 30px;">Testar API (Health Check)</a>
        <?php endif; ?>
    </div>
</body>
</html>
