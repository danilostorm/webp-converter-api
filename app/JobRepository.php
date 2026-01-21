<?php
namespace App;

/**
 * Job management and database operations
 */
class JobRepository {
    public function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    public function createJob($sourceType, $sourceUrl, $inputPath, $quality, $width, $height, $fit, $stripMetadata, $filename, $clientIp, $apiKey, $inputMime = null, $inputSize = null) {
        $id = $this->generateUuid();
        $apiKeyHash = hash('sha256', $apiKey);
        
        $sql = "INSERT INTO jobs (id, source_type, source_url, input_path, quality, width, height, fit, strip_metadata, filename, client_ip, api_key_hash, input_mime, input_size, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', NOW())";
        
        \DB::query($sql, [
            $id, $sourceType, $sourceUrl, $inputPath, $quality, 
            $width, $height, $fit, $stripMetadata ? 1 : 0, 
            $filename, $clientIp, $apiKeyHash, $inputMime, $inputSize
        ]);
        
        Logger::info("Job created: {$id}");
        
        return ['id' => $id];
    }
    
    public function getJob($id) {
        $sql = "SELECT * FROM jobs WHERE id = ?";
        return \DB::fetch($sql, [$id]);
    }
    
    public function getNextJob() {
        // Get oldest queued job that's not locked
        $timeout = \Config::get('job_timeout', 300);
        $lockExpiry = date('Y-m-d H:i:s', time() - $timeout);
        
        $sql = "SELECT * FROM jobs 
                WHERE status = 'queued' 
                AND (locked_at IS NULL OR locked_at < ?) 
                ORDER BY created_at ASC 
                LIMIT 1";
        
        return \DB::fetch($sql, [$lockExpiry]);
    }
    
    public function lockJob($id) {
        $lockId = uniqid('worker_', true);
        $sql = "UPDATE jobs SET locked_at = NOW(), locked_by = ? WHERE id = ? AND (locked_at IS NULL OR locked_at < ?)";
        
        $timeout = \Config::get('job_timeout', 300);
        $lockExpiry = date('Y-m-d H:i:s', time() - $timeout);
        
        \DB::query($sql, [$lockId, $id, $lockExpiry]);
        
        // Verify lock
        $sql = "SELECT locked_by FROM jobs WHERE id = ?";
        $job = \DB::fetch($sql, [$id]);
        
        return $job && $job['locked_by'] === $lockId;
    }
    
    public function updateJobStatus($id, $status, $errorMessage = null) {
        $sql = "UPDATE jobs SET status = ?, error_message = ?, updated_at = NOW()";
        $params = [$status, $errorMessage];
        
        if ($status === 'processing') {
            $sql .= ", started_at = NOW()";
        } elseif ($status === 'done' || $status === 'error') {
            $sql .= ", finished_at = NOW(), locked_at = NULL, locked_by = NULL";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        \DB::query($sql, $params);
    }
    
    public function updateJobPaths($id, $inputPath, $outputPath, $inputMime, $inputSize, $outputSize) {
        $sql = "UPDATE jobs SET input_path = ?, output_path = ?, input_mime = ?, input_size = ?, output_size = ? WHERE id = ?";
        \DB::query($sql, [$inputPath, $outputPath, $inputMime, $inputSize, $outputSize, $id]);
    }
    
    public function tryProcessJob($jobId) {
        $job = $this->getJob($jobId);
        
        if (!$job || $job['status'] !== 'queued') {
            return false;
        }
        
        // Try to lock
        if (!$this->lockJob($jobId)) {
            return false;
        }
        
        // Process
        $this->processJob($job);
        return true;
    }
    
    public function processJob($job) {
        $this->updateJobStatus($job['id'], 'processing');
        
        try {
            // Download if URL source
            if ($job['source_type'] === 'url') {
                $ext = pathinfo(parse_url($job['source_url'], PHP_URL_PATH), PATHINFO_EXTENSION);
                $inputPath = "storage/incoming/{$job['id']}.{$ext}";
                
                if (!Security::downloadImage($job['source_url'], $inputPath)) {
                    throw new \Exception('Failed to download image from URL');
                }
                
                // Get MIME and size
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $inputMime = finfo_file($finfo, $inputPath);
                finfo_close($finfo);
                $inputSize = filesize($inputPath);
                
                $this->updateJobPaths($job['id'], $inputPath, null, $inputMime, $inputSize, null);
                $job['input_path'] = $inputPath;
            }
            
            // Convert
            $outputFilename = $job['filename'] ?: $job['id'] . '.webp';
            $outputFilename = Security::sanitizeFilename($outputFilename);
            if (pathinfo($outputFilename, PATHINFO_EXTENSION) !== 'webp') {
                $outputFilename .= '.webp';
            }
            
            $outputPath = "storage/output/{$outputFilename}";
            
            $converter = new ImageConverter();
            $result = $converter->convert(
                $job['input_path'],
                $outputPath,
                $job['quality'],
                $job['width'],
                $job['height'],
                $job['fit'],
                $job['strip_metadata']
            );
            
            if (!$result['success']) {
                throw new \Exception($result['error']);
            }
            
            $outputSize = filesize($outputPath);
            $this->updateJobPaths($job['id'], $job['input_path'], $outputPath, null, null, $outputSize);
            
            // Cleanup input file
            if (file_exists($job['input_path'])) {
                @unlink($job['input_path']);
            }
            
            $this->updateJobStatus($job['id'], 'done');
            Logger::info("Job completed: {$job['id']}");
            
        } catch (\Exception $e) {
            Logger::error("Job failed: {$job['id']} - " . $e->getMessage());
            $this->updateJobStatus($job['id'], 'error', $e->getMessage());
            
            // Cleanup
            if (isset($job['input_path']) && file_exists($job['input_path'])) {
                @unlink($job['input_path']);
            }
        }
    }
    
    public function cleanupOldJobs() {
        $retention = \Config::get('job_retention', 86400);
        $threshold = date('Y-m-d H:i:s', time() - $retention);
        
        // Get old jobs
        $sql = "SELECT id, input_path, output_path FROM jobs WHERE created_at < ?";
        $jobs = \DB::fetchAll($sql, [$threshold]);
        
        foreach ($jobs as $job) {
            // Delete files
            if ($job['input_path'] && file_exists($job['input_path'])) {
                @unlink($job['input_path']);
            }
            if ($job['output_path'] && file_exists($job['output_path'])) {
                @unlink($job['output_path']);
            }
        }
        
        // Delete records
        $sql = "DELETE FROM jobs WHERE created_at < ?";
        \DB::query($sql, [$threshold]);
        
        Logger::info("Cleaned up " . count($jobs) . " old jobs");
    }
}
