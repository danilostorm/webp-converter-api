<?php
/**
 * WebP Converter API - Worker
 * Processa jobs em background (via cron ou execução manual)
 * 
 * Uso:
 *   php worker.php
 *   
 * Cron (a cada minuto):
 *   * * * * * cd /path/to/webp-converter-api && php worker.php >> /dev/null 2>&1
 */

require_once __DIR__ . '/app/bootstrap.php';

class Worker {
    private $db;
    private $jobRepo;
    private $converter;
    private $logger;
    private $lockId;
    private $maxExecutionTime = 50; // segundos (deixa margem para cron de 1min)
    private $startTime;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->jobRepo = new JobRepository($this->db);
        $this->converter = new ImageConverter();
        $this->logger = new Logger();
        $this->lockId = getmypid() . '_' . gethostname();
        $this->startTime = time();
    }
    
    public function run() {
        $this->logger->info("Worker started [PID: {$this->lockId}]");
        
        $processedCount = 0;
        
        while (time() - $this->startTime < $this->maxExecutionTime) {
            // Pega próximo job disponível e trava
            $job = $this->getNextJob();
            
            if (!$job) {
                $this->logger->info("No jobs available. Worker finished. Processed: {$processedCount}");
                break;
            }
            
            try {
                $this->processJob($job);
                $processedCount++;
                $this->logger->info("Job {$job['id']} processed successfully");
            } catch (Exception $e) {
                $this->logger->error("Job {$job['id']} failed: " . $e->getMessage());
                $this->markJobError($job['id'], $e->getMessage());
            }
            
            // Libera memória
            gc_collect_cycles();
        }
        
        $this->logger->info("Worker finished. Total processed: {$processedCount}");
    }
    
    private function getNextJob() {
        // Usa transação para pegar e travar job atomicamente
        try {
            $this->db->beginTransaction();
            
            // Pega o job mais antigo que está queued e não travado
            $stmt = $this->db->prepare("
                SELECT * FROM jobs 
                WHERE status = 'queued' 
                AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                ORDER BY created_at ASC 
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($job) {
                // Trava o job
                $updateStmt = $this->db->prepare("
                    UPDATE jobs 
                    SET locked_at = NOW(), 
                        locked_by = :lock_id,
                        status = 'processing',
                        started_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'lock_id' => $this->lockId,
                    'id' => $job['id']
                ]);
            }
            
            $this->db->commit();
            return $job;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error("Error getting next job: " . $e->getMessage());
            return null;
        }
    }
    
    private function processJob($job) {
        $startTime = microtime(true);
        
        // 1. Obtém a imagem
        if ($job['source_type'] === 'url') {
            $inputPath = $this->downloadImage($job['source_url'], $job['id']);
        } else {
            $inputPath = $job['input_path'];
        }
        
        if (!file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }
        
        // 2. Valida tipo de imagem
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $inputPath);
        finfo_close($finfo);
        
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception("Invalid image type: {$mimeType}");
        }
        
        $inputSize = filesize($inputPath);
        
        // 3. Prepara output
        $outputFilename = $job['filename'] ?: ($job['id'] . '.webp');
        $outputPath = STORAGE_PATH . '/output/' . $outputFilename;
        
        // 4. Converte
        $options = [
            'quality' => (int)$job['quality'],
            'width' => $job['width'] ? (int)$job['width'] : null,
            'height' => $job['height'] ? (int)$job['height'] : null,
            'fit' => $job['fit'],
            'strip_metadata' => (bool)$job['strip_metadata']
        ];
        
        $this->converter->convert($inputPath, $outputPath, $options);
        
        if (!file_exists($outputPath)) {
            throw new Exception("Conversion failed - output file not created");
        }
        
        $outputSize = filesize($outputPath);
        $processingTime = round((microtime(true) - $startTime) * 1000);
        
        // 5. Atualiza job
        $this->jobRepo->updateJob($job['id'], [
            'status' => 'done',
            'output_path' => $outputPath,
            'input_mime' => $mimeType,
            'input_size' => $inputSize,
            'output_size' => $outputSize,
            'processing_time_ms' => $processingTime,
            'finished_at' => date('Y-m-d H:i:s')
        ]);
        
        // 6. Limpa input se foi download
        if ($job['source_type'] === 'url' && file_exists($inputPath)) {
            @unlink($inputPath);
        }
    }
    
    private function downloadImage($url, $jobId) {
        $filename = $jobId . '_' . time() . '.tmp';
        $outputPath = STORAGE_PATH . '/incoming/' . $filename;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'WebP-Converter-API/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE => MAX_FILE_SIZE
        ]);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$data) {
            throw new Exception("Failed to download image: HTTP {$httpCode} - {$error}");
        }
        
        if (strlen($data) > MAX_FILE_SIZE) {
            throw new Exception("Downloaded file exceeds maximum size");
        }
        
        file_put_contents($outputPath, $data);
        return $outputPath;
    }
    
    private function markJobError($jobId, $errorMessage) {
        $this->jobRepo->updateJob($jobId, [
            'status' => 'error',
            'error_message' => $errorMessage,
            'finished_at' => date('Y-m-d H:i:s')
        ]);
    }
}

// Executa worker
try {
    $worker = new Worker();
    $worker->run();
} catch (Exception $e) {
    $logger = new Logger();
    $logger->error("Worker fatal error: " . $e->getMessage());
    exit(1);
}
