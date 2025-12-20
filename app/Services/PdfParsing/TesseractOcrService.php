<?php

namespace App\Services\PdfParsing;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

class TesseractOcrService implements OcrServiceInterface
{
    /**
     * Minimum text length to consider PDF as text-based (not scanned)
     */
    private const MIN_TEXT_LENGTH = 100;

    /**
     * Tesseract command path (can be configured)
     * Nullable because Tesseract may not be installed
     */
    private ?string $tesseractPath = null;

    public function __construct()
    {
        // Try to find tesseract in common locations
        $this->tesseractPath = $this->findTesseractPath();
        
        if (!$this->tesseractPath) {
            Log::info('Tesseract OCR not found. OCR functionality will be disabled.');
        }
    }

    /**
     * Check if PDF needs OCR
     */
    public function needsOcr(string $pdfPath, string $extractedText): bool
    {
        // If extracted text is too short, likely a scanned PDF
        if (strlen(trim($extractedText)) < self::MIN_TEXT_LENGTH) {
            return true;
        }

        // Check if text contains mostly non-alphanumeric (likely OCR needed)
        $alphanumericRatio = preg_match_all('/[a-zA-Z0-9]/', $extractedText) / max(strlen($extractedText), 1);
        if ($alphanumericRatio < 0.3) {
            return true;
        }

        return false;
    }

    /**
     * Check if Tesseract is available
     */
    public function isAvailable(): bool
    {
        return $this->tesseractPath !== null;
    }

    /**
     * Extract text using OCR
     */
    public function extractText(string $pdfPath): string
    {
        if (!$this->tesseractPath) {
            throw new \Exception('Tesseract OCR is not installed. Please install tesseract-ocr package.');
        }

        // Convert PDF to images first (using pdftoppm or similar)
        $images = $this->pdfToImages($pdfPath);
        
        $allText = '';
        
        foreach ($images as $imagePath) {
            try {
                $text = $this->ocrImage($imagePath);
                $allText .= $text . "\n";
                
                // Clean up temporary image
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            } catch (\Exception $e) {
                Log::warning("OCR failed for image {$imagePath}: {$e->getMessage()}");
            }
        }

        return trim($allText);
    }

    /**
     * Convert PDF pages to images
     */
    private function pdfToImages(string $pdfPath): array
    {
        $outputDir = storage_path('app/temp/ocr');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $baseName = basename($pdfPath, '.pdf');
        $outputPattern = $outputDir . '/' . $baseName . '_%03d.png';

        // Use pdftoppm (part of poppler-utils) to convert PDF to images
        $command = sprintf(
            'pdftoppm -png -r 300 "%s" "%s"',
            escapeshellarg($pdfPath),
            escapeshellarg($outputDir . '/' . $baseName)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Failed to convert PDF to images. Is pdftoppm installed?');
        }

        // Find generated images
        $images = glob($outputDir . '/' . $baseName . '_*.png');
        sort($images);

        return $images;
    }

    /**
     * Perform OCR on a single image
     */
    private function ocrImage(string $imagePath): string
    {
        $command = sprintf(
            '"%s" "%s" stdout',
            escapeshellarg($this->tesseractPath),
            escapeshellarg($imagePath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("OCR failed with return code: {$returnCode}");
        }

        return implode("\n", $output);
    }

    /**
     * Find tesseract executable path
     */
    private function findTesseractPath(): ?string
    {
        $possiblePaths = [
            'tesseract', // In PATH
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
            'C:\Program Files\Tesseract-OCR\tesseract.exe',
            'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
        ];

        foreach ($possiblePaths as $path) {
            if ($this->isExecutable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if command is executable
     */
    private function isExecutable(string $command): bool
    {
        $testCommand = strpos($command, ' ') === false 
            ? sprintf('"%s" --version', escapeshellarg($command))
            : sprintf('%s --version', $command);
        
        exec($testCommand . ' 2>&1', $output, $returnCode);
        
        return $returnCode === 0;
    }
}

