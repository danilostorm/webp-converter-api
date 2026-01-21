<?php
namespace App;

/**
 * Image conversion to WebP using Imagick, GD or cwebp
 */
class ImageConverter {
    private $method = null;
    
    public function __construct() {
        $this->detectMethod();
    }
    
    private function detectMethod() {
        // Prefer Imagick
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $imagick = new \Imagick();
            $formats = $imagick->queryFormats('WEBP');
            if (!empty($formats)) {
                $this->method = 'imagick';
                return;
            }
        }
        
        // Try cwebp binary
        $cwebpPath = $this->findCwebp();
        if ($cwebpPath) {
            $this->method = 'cwebp';
            return;
        }
        
        // Fallback to GD (if imagewebp available)
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            $this->method = 'gd';
            return;
        }
        
        $this->method = null;
    }
    
    private function findCwebp() {
        $paths = ['/usr/bin/cwebp', '/usr/local/bin/cwebp', 'cwebp'];
        
        foreach ($paths as $path) {
            if (@is_executable($path)) {
                return $path;
            }
        }
        
        // Try which command
        $output = @shell_exec('which cwebp 2>/dev/null');
        if ($output && trim($output)) {
            return trim($output);
        }
        
        return null;
    }
    
    public function getCapabilities() {
        return [
            'method' => $this->method,
            'imagick' => extension_loaded('imagick'),
            'gd' => extension_loaded('gd'),
            'cwebp' => $this->findCwebp() !== null,
            'imagewebp' => function_exists('imagewebp')
        ];
    }
    
    public function convert($inputPath, $outputPath, $quality = 85, $width = null, $height = null, $fit = 'contain', $stripMetadata = true) {
        $startTime = microtime(true);
        
        if (!$this->method) {
            return [
                'success' => false,
                'error' => 'No WebP conversion method available. Install Imagick, GD with WebP support, or cwebp binary.'
            ];
        }
        
        if (!file_exists($inputPath)) {
            return ['success' => false, 'error' => 'Input file not found'];
        }
        
        try {
            switch ($this->method) {
                case 'imagick':
                    $result = $this->convertWithImagick($inputPath, $outputPath, $quality, $width, $height, $fit, $stripMetadata);
                    break;
                case 'cwebp':
                    $result = $this->convertWithCwebp($inputPath, $outputPath, $quality, $width, $height);
                    break;
                case 'gd':
                    $result = $this->convertWithGd($inputPath, $outputPath, $quality, $width, $height, $fit, $stripMetadata);
                    break;
                default:
                    return ['success' => false, 'error' => 'Unknown conversion method'];
            }
            
            if ($result) {
                $timeMs = round((microtime(true) - $startTime) * 1000);
                return ['success' => true, 'time_ms' => $timeMs];
            }
            
            return ['success' => false, 'error' => 'Conversion failed'];
            
        } catch (\Exception $e) {
            Logger::error("Conversion error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function convertWithImagick($inputPath, $outputPath, $quality, $width, $height, $fit, $stripMetadata) {
        $image = new \Imagick($inputPath);
        
        // Handle EXIF orientation before processing
        $orientation = $image->getImageOrientation();
        if ($orientation !== \Imagick::ORIENTATION_UNDEFINED && $orientation !== \Imagick::ORIENTATION_TOPLEFT) {
            $image->autoOrientImage();
        }
        
        // Resize if dimensions provided
        if ($width || $height) {
            $this->resizeImagick($image, $width, $height, $fit);
        }
        
        // Set WebP format and quality
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        
        // Strip metadata if requested
        if ($stripMetadata) {
            $image->stripImage();
        }
        
        // Save
        $success = $image->writeImage($outputPath);
        $image->clear();
        $image->destroy();
        
        return $success;
    }
    
    private function resizeImagick($image, $width, $height, $fit) {
        $origWidth = $image->getImageWidth();
        $origHeight = $image->getImageHeight();
        
        list($newWidth, $newHeight) = $this->calculateDimensions($origWidth, $origHeight, $width, $height, $fit);
        
        if ($fit === 'cover') {
            // Resize and crop
            $image->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
            $image->cropThumbnailImage($width ?: $newWidth, $height ?: $newHeight);
        } else {
            // Just resize
            $image->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
        }
    }
    
    private function convertWithCwebp($inputPath, $outputPath, $quality, $width, $height) {
        $cwebp = $this->findCwebp();
        
        $cmd = escapeshellcmd($cwebp);
        $cmd .= ' -q ' . escapeshellarg($quality);
        
        if ($width || $height) {
            if ($width) $cmd .= ' -resize ' . escapeshellarg($width) . ' 0';
            if ($height) $cmd .= ' -resize 0 ' . escapeshellarg($height);
        }
        
        $cmd .= ' ' . escapeshellarg($inputPath);
        $cmd .= ' -o ' . escapeshellarg($outputPath);
        $cmd .= ' 2>&1';
        
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Logger::error("cwebp failed: " . implode("\n", $output));
            return false;
        }
        
        return file_exists($outputPath);
    }
    
    private function convertWithGd($inputPath, $outputPath, $quality, $width, $height, $fit, $stripMetadata) {
        // Detect input format
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $inputPath);
        finfo_close($finfo);
        
        // Load image
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($inputPath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($inputPath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($inputPath);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($inputPath);
                break;
            default:
                return false;
        }
        
        if (!$image) {
            return false;
        }
        
        // Handle EXIF orientation for JPEG
        if ($mime === 'image/jpeg' && !$stripMetadata) {
            $exif = @exif_read_data($inputPath);
            if ($exif && isset($exif['Orientation'])) {
                $image = $this->rotateGdImage($image, $exif['Orientation']);
            }
        }
        
        // Resize if needed
        if ($width || $height) {
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);
            
            list($newWidth, $newHeight) = $this->calculateDimensions($origWidth, $origHeight, $width, $height, $fit);
            
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
            
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($image);
            $image = $resized;
        }
        
        // Save as WebP
        $success = imagewebp($image, $outputPath, $quality);
        imagedestroy($image);
        
        return $success;
    }
    
    private function rotateGdImage($image, $orientation) {
        switch ($orientation) {
            case 3:
                return imagerotate($image, 180, 0);
            case 6:
                return imagerotate($image, -90, 0);
            case 8:
                return imagerotate($image, 90, 0);
            default:
                return $image;
        }
    }
    
    private function calculateDimensions($origWidth, $origHeight, $targetWidth, $targetHeight, $fit) {
        // If no dimensions specified, return original
        if (!$targetWidth && !$targetHeight) {
            return [$origWidth, $origHeight];
        }
        
        // If only one dimension specified
        if (!$targetWidth) {
            $targetWidth = round($origWidth * ($targetHeight / $origHeight));
        }
        if (!$targetHeight) {
            $targetHeight = round($origHeight * ($targetWidth / $origWidth));
        }
        
        $ratio = $origWidth / $origHeight;
        $targetRatio = $targetWidth / $targetHeight;
        
        switch ($fit) {
            case 'contain':
            case 'inside':
                // Fit inside box, maintaining aspect ratio
                if ($ratio > $targetRatio) {
                    return [$targetWidth, round($targetWidth / $ratio)];
                } else {
                    return [round($targetHeight * $ratio), $targetHeight];
                }
            
            case 'cover':
            case 'outside':
                // Cover entire box, maintaining aspect ratio (may crop)
                if ($ratio > $targetRatio) {
                    return [round($targetHeight * $ratio), $targetHeight];
                } else {
                    return [$targetWidth, round($targetWidth / $ratio)];
                }
            
            default:
                return [$targetWidth, $targetHeight];
        }
    }
}
