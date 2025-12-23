<?php

namespace App\Services\PdfParsing;

interface OcrServiceInterface
{
    /**
     * Perform OCR on PDF file
     *
     * @param string $pdfPath Absolute path to PDF file
     * @return string Extracted text
     * @throws \Exception If OCR fails
     */
    public function extractText(string $pdfPath): string;

    /**
     * Check if PDF needs OCR (is scanned/image-based)
     *
     * @param string $pdfPath Absolute path to PDF file
     * @param string $extractedText Text extracted by regular PDF parser
     * @return bool True if OCR is needed
     */
    public function needsOcr(string $pdfPath, string $extractedText): bool;
}





