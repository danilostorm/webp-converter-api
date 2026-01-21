<?php
/**
 * WebP Converter API - Main Entry Point
 * 
 * Routes all API requests through the router
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Powered-By: WebP-Converter-API/1.0');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/app/bootstrap.php';

use App\Router;
use App\Response;
use App\Auth;
use App\RateLimiter;
use App\Logger;
use App\JobRepository;
use App\ImageConverter;
use App\Security;

try {
    $router = new Router();
    
    // Health check endpoint (PUBLIC - no auth required)
    $router->get('/api/v1/health', function() {
        $converter = new ImageConverter();
        Response::json([
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'capabilities' => $converter->getCapabilities()
        ]);
    }, true); // true = public route
    
    // Middleware for authenticated routes
    $router->middleware(function() {
        $auth = new Auth();
        $apiKey = $auth->getApiKeyFromRequest();
        
        if (!$apiKey || !$auth->validateApiKey($apiKey)) {
            Response::error('UNAUTHORIZED', 'Invalid or missing API key', 401);
        }
        
        // Rate limiting
        $rateLimiter = new RateLimiter();
        $clientIp = Security::getClientIp();
        
        if (!$rateLimiter->checkLimit($apiKey, $clientIp)) {
            Response::error('RATE_LIMIT_EXCEEDED', 'Too many requests. Limit: 60 req/min', 429);
        }
        
        // Store for use in routes
        return ['api_key' => $apiKey, 'client_ip' => $clientIp];
    });
    
    // POST /api/v1/jobs - Create conversion job
    $router->post('/api/v1/jobs', function($context) {
        $db = Database::getInstance()->getConnection();
        $jobRepo = new JobRepository($db);
        $security = new Security();
        
        // Parse input (JSON or multipart)
        $input = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'multipart/form-data') !== false) {
            // File upload
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                Response::error('MISSING_FILE', 'No file uploaded or upload error', 400);
            }
            
            $file = $_FILES['file'];
            
            // Validate file size
            if ($file['size'] > MAX_FILE_SIZE) {
                Response::error('FILE_TOO_LARGE', 'File exceeds maximum size of 15MB', 413);
            }
            
            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!$security->isValidImageMime($mimeType)) {
                Response::error('INVALID_MIME', 'File must be an image (jpg, png, gif, webp, avif)', 415);
            }
            
            // Move to incoming storage
            $jobId = $jobRepo->generateUuid();
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $inputPath = STORAGE_PATH . "/incoming/{$jobId}.{$ext}";
            
            if (!move_uploaded_file($file['tmp_name'], $inputPath)) {
                Response::error('UPLOAD_FAILED', 'Failed to save uploaded file', 500);
            }
            
            $input = [
                'source_type' => 'upload',
                'source_url' => null,
                'input_path' => $inputPath,
                'input_mime' => $mimeType,
                'input_size' => $file['size'],
                'quality' => $_POST['quality'] ?? 85,
                'width' => $_POST['width'] ?? null,
                'height' => $_POST['height'] ?? null,
                'fit' => $_POST['fit'] ?? 'contain',
                'strip_metadata' => isset($_POST['strip_metadata']) ? (bool)$_POST['strip_metadata'] : true,
                'filename' => $_POST['filename'] ?? null
            ];
            
        } else {
            // JSON input
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$data || !isset($data['source_url'])) {
                Response::error('INVALID_INPUT', 'Missing source_url in JSON payload', 400);
            }
            
            // Validate URL
            if (!$security->isValidImageUrl($data['source_url'])) {
                Response::error('INVALID_URL', 'Invalid or unsafe URL (SSRF protection)', 400);
            }
            
            $input = [
                'source_type' => 'url',
                'source_url' => $data['source_url'],
                'input_path' => null,
                'input_mime' => null,
                'input_size' => null,
                'quality' => $data['quality'] ?? 85,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'fit' => $data['fit'] ?? 'contain',
                'strip_metadata' => $data['strip_metadata'] ?? true,
                'filename' => $data['filename'] ?? null
            ];
        }
        
        // Validate quality
        $input['quality'] = max(1, min(100, (int)$input['quality']));
        
        // Validate fit
        if (!in_array($input['fit'], ['contain', 'cover', 'inside', 'outside'])) {
            $input['fit'] = 'contain';
        }
        
        // Create job
        $job = $jobRepo->createJob(
            $input['source_type'],
            $input['source_url'],
            $input['input_path'],
            $input['quality'],
            $input['width'],
            $input['height'],
            $input['fit'],
            $input['strip_metadata'],
            $input['filename'],
            $context['client_ip'],
            hash('sha256', $context['api_key']),
            $input['input_mime'],
            $input['input_size']
        );
        
        $baseUrl = Config::get('base_url', 'https://' . $_SERVER['HTTP_HOST']);
        
        Response::json([
            'job_id' => $job['id'],
            'status' => 'queued',
            'poll_url' => "{$baseUrl}/api/v1/jobs/{$job['id']}",
            'result_url' => null
        ], 201);
    });
    
    // GET /api/v1/jobs/{id} - Get job status
    $router->get('/api/v1/jobs/([a-f0-9-]+)', function($context, $jobId) {
        $db = Database::getInstance()->getConnection();
        $jobRepo = new JobRepository($db);
        $job = $jobRepo->getJob($jobId);
        
        if (!$job) {
            Response::error('JOB_NOT_FOUND', 'Job not found', 404);
        }
        
        $baseUrl = Config::get('base_url', 'https://' . $_SERVER['HTTP_HOST']);
        $resultUrl = null;
        
        if ($job['status'] === 'done' && $job['output_path']) {
            $filename = basename($job['output_path']);
            $resultUrl = "{$baseUrl}/download/{$filename}";
        }
        
        $progress = 0;
        if ($job['status'] === 'processing') $progress = 50;
        if ($job['status'] === 'done') $progress = 100;
        
        $timeMs = null;
        if ($job['processing_time_ms']) {
            $timeMs = (int)$job['processing_time_ms'];
        }
        
        Response::json([
            'job_id' => $job['id'],
            'status' => $job['status'],
            'progress' => $progress,
            'result_url' => $resultUrl,
            'error' => $job['error_message'],
            'meta' => [
                'input_mime' => $job['input_mime'],
                'input_size' => (int)$job['input_size'],
                'output_size' => (int)$job['output_size'],
                'time_ms' => $timeMs,
                'created_at' => $job['created_at'],
                'finished_at' => $job['finished_at']
            ]
        ]);
    });
    
    // POST /api/v1/convert - Synchronous conversion
    $router->post('/api/v1/convert', function($context) {
        $db = Database::getInstance()->getConnection();
        $jobRepo = new JobRepository($db);
        $security = new Security();
        $converter = new ImageConverter();
        
        // Same input parsing as /jobs
        $input = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'multipart/form-data') !== false) {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                Response::error('MISSING_FILE', 'No file uploaded', 400);
            }
            
            $file = $_FILES['file'];
            if ($file['size'] > MAX_FILE_SIZE) {
                Response::error('FILE_TOO_LARGE', 'File exceeds 15MB', 413);
            }
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!$security->isValidImageMime($mimeType)) {
                Response::error('INVALID_MIME', 'Invalid image type', 415);
            }
            
            $jobId = $jobRepo->generateUuid();
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $inputPath = STORAGE_PATH . "/incoming/{$jobId}.{$ext}";
            move_uploaded_file($file['tmp_name'], $inputPath);
            
            $input = [
                'input_path' => $inputPath,
                'quality' => (int)($_POST['quality'] ?? 85),
                'width' => isset($_POST['width']) ? (int)$_POST['width'] : null,
                'height' => isset($_POST['height']) ? (int)$_POST['height'] : null,
                'fit' => $_POST['fit'] ?? null,
                'strip_metadata' => isset($_POST['strip_metadata']) ? (bool)$_POST['strip_metadata'] : true
            ];
        } else {
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || !isset($data['source_url'])) {
                Response::error('INVALID_INPUT', 'Missing source_url', 400);
            }
            
            if (!$security->isValidImageUrl($data['source_url'])) {
                Response::error('INVALID_URL', 'Invalid URL', 400);
            }
            
            // Download image
            $jobId = $jobRepo->generateUuid();
            $inputPath = STORAGE_PATH . "/incoming/{$jobId}_temp";
            
            $ch = curl_init($data['source_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'WebP-Converter-API/1.0',
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$imageData) {
                Response::error('DOWNLOAD_FAILED', 'Failed to download image', 500);
            }
            
            file_put_contents($inputPath, $imageData);
            
            $input = [
                'input_path' => $inputPath,
                'quality' => (int)($data['quality'] ?? 85),
                'width' => isset($data['width']) ? (int)$data['width'] : null,
                'height' => isset($data['height']) ? (int)$data['height'] : null,
                'fit' => $data['fit'] ?? null,
                'strip_metadata' => $data['strip_metadata'] ?? true
            ];
        }
        
        // Process synchronously
        $outputPath = STORAGE_PATH . "/output/{$jobId}.webp";
        
        $options = [
            'quality' => $input['quality'],
            'width' => $input['width'],
            'height' => $input['height'],
            'fit' => $input['fit'],
            'strip_metadata' => $input['strip_metadata']
        ];
        
        $converter->convert($input['input_path'], $outputPath, $options);
        
        // Cleanup input
        @unlink($input['input_path']);
        
        if (!file_exists($outputPath)) {
            Response::error('CONVERSION_FAILED', 'Failed to convert image', 500);
        }
        
        // Check Accept header
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'image/webp') !== false) {
            // Return binary
            header('Content-Type: image/webp');
            header('Content-Length: ' . filesize($outputPath));
            readfile($outputPath);
            @unlink($outputPath);
            exit;
        }
        
        $baseUrl = Config::get('base_url', 'https://' . $_SERVER['HTTP_HOST']);
        $filename = basename($outputPath);
        
        Response::json([
            'success' => true,
            'result_url' => "{$baseUrl}/download/{$filename}",
            'output_size' => filesize($outputPath)
        ]);
    });
    
    $router->dispatch();
    
} catch (Exception $e) {
    Logger::error('Unhandled exception: ' . $e->getMessage());
    Response::error('INTERNAL_ERROR', 'An internal error occurred', 500);
}
