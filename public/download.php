<?php
/**
 * WebP Converter API - Download Endpoint
 * Serve arquivos WEBP convertidos
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Pega o ID do job da URL
$requestUri = $_SERVER['REQUEST_URI'];
preg_match('/\/download\/([a-f0-9\-]+)(?:\.webp)?$/i', $requestUri, $matches);

if (!isset($matches[1])) {
    http_response_code(404);
    Response::json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Invalid download URL']], 404);
    exit;
}

$jobId = $matches[1];

try {
    $db = Database::getInstance()->getConnection();
    $jobRepo = new JobRepository($db);
    
    // Busca o job
    $job = $jobRepo->getJob($jobId);
    
    if (!$job) {
        http_response_code(404);
        Response::json(['error' => ['code' => 'JOB_NOT_FOUND', 'message' => 'Job not found']], 404);
        exit;
    }
    
    // Verifica se o job foi concluído
    if ($job['status'] !== 'done') {
        http_response_code(404);
        Response::json([
            'error' => [
                'code' => 'FILE_NOT_READY',
                'message' => 'File not ready yet',
                'details' => ['status' => $job['status']]
            ]
        ], 404);
        exit;
    }
    
    // Verifica se o arquivo existe
    $filePath = $job['output_path'];
    
    if (!file_exists($filePath) || !is_readable($filePath)) {
        Logger::error("File not found or not readable: {$filePath} for job {$jobId}");
        http_response_code(404);
        Response::json(['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'File not found on server']], 404);
        exit;
    }
    
    // Prepara nome do arquivo para download
    $filename = $job['filename'] ?: ($jobId . '.webp');
    if (!str_ends_with(strtolower($filename), '.webp')) {
        $filename .= '.webp';
    }
    
    // Define headers para download
    header('Content-Type: image/webp');
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: public, max-age=31536000, immutable'); // Cache de 1 ano
    header('ETag: "' . md5_file($filePath) . '"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT');
    
    // Suporte a cache condicional
    $etag = md5_file($filePath);
    $lastModified = filemtime($filePath);
    
    // Verifica If-None-Match (ETag)
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        http_response_code(304);
        exit;
    }
    
    // Verifica If-Modified-Since
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($ifModifiedSince >= $lastModified) {
            http_response_code(304);
            exit;
        }
    }
    
    // Suporte a range requests (para streaming/partial content)
    $fileSize = filesize($filePath);
    $start = 0;
    $end = $fileSize - 1;
    
    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        
        if (preg_match('/bytes=(\d+)-(\d*)/', $range, $rangeMatches)) {
            $start = (int)$rangeMatches[1];
            $end = $rangeMatches[2] !== '' ? (int)$rangeMatches[2] : $end;
            
            if ($start > $end || $end >= $fileSize) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . ($end - $start + 1));
        }
    }
    
    // Envia o arquivo
    $file = fopen($filePath, 'rb');
    
    if ($start > 0) {
        fseek($file, $start);
    }
    
    $remaining = $end - $start + 1;
    $bufferSize = 8192; // 8KB chunks
    
    while (!feof($file) && $remaining > 0) {
        $bytesToRead = min($bufferSize, $remaining);
        echo fread($file, $bytesToRead);
        $remaining -= $bytesToRead;
        
        if (connection_aborted()) {
            break;
        }
    }
    
    fclose($file);
    exit;
    
} catch (Exception $e) {
    Logger::error("Download error: " . $e->getMessage());
    http_response_code(500);
    Response::json(['error' => ['code' => 'SERVER_ERROR', 'message' => 'Failed to serve file']], 500);
    exit;
}
