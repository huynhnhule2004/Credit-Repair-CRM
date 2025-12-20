<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CreditItem;
use App\Services\PdfParsing\DataNormalizer;
use App\Services\PdfParsing\OcrServiceInterface;
use App\Services\PdfParsing\TesseractOcrService;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditReportParserService
{
    protected DataNormalizer $normalizer;
    protected ?OcrServiceInterface $ocrService;

    public function __construct()
    {
        $this->normalizer = new DataNormalizer();
        // Try to initialize OCR service (may not be available if Tesseract not installed)
        try {
            $ocrService = new TesseractOcrService();
            // Only use OCR service if Tesseract is actually available
            if ($ocrService->isAvailable()) {
                $this->ocrService = $ocrService;
            } else {
                $this->ocrService = null;
                Log::info('OCR service not available: Tesseract not found');
            }
        } catch (\Exception $e) {
            $this->ocrService = null;
            Log::info('OCR service not available: ' . $e->getMessage());
        }
    }
    /**
     * Parse HTML content from IdentityIQ and save credit items to database.
     *
     * @param Client $client The client whose report is being parsed
     * @param string $htmlContent The HTML source code from IdentityIQ
     * @return int Number of items successfully imported
     * @throws \Exception If parsing fails
     */
    public function parseAndSave(Client $client, string $htmlContent): int
    {
        try {
            DB::beginTransaction();

            $crawler = new Crawler($htmlContent);
            $importedCount = 0;

            // Parse negative items from each bureau
            // Note: Adjust selectors based on actual IdentityIQ HTML structure
            $bureaus = ['transunion', 'experian', 'equifax'];

            foreach ($bureaus as $bureau) {
                $items = $this->parseItemsForBureau($crawler, $bureau);

                foreach ($items as $itemData) {
                    // Check if item already exists to avoid duplicates
                    $exists = CreditItem::where('client_id', $client->id)
                        ->where('bureau', $bureau)
                        ->where('account_number', $itemData['account_number'])
                        ->exists();

                    if (!$exists) {
                        CreditItem::create([
                            'client_id' => $client->id,
                            'bureau' => $bureau,
                            'account_name' => $itemData['account_name'],
                            'account_number' => $itemData['account_number'],
                            'balance' => $itemData['balance'],
                            'reason' => $itemData['reason'] ?? null,
                            'status' => $itemData['status'] ?? null,
                            'dispute_status' => CreditItem::STATUS_PENDING,
                        ]);

                        $importedCount++;
                    }
                }
            }

            DB::commit();

            Log::info("Successfully imported {$importedCount} credit items for client {$client->id}");

            return $importedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to parse credit report: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Parse a PDF credit report file and save credit items to database.
     * 
     * This method uses multiple parsing strategies to handle various PDF formats:
     * 1. Pipe-separated format (original)
     * 2. Tab-separated format
     * 3. Comma-separated format (CSV-like)
     * 4. Regex pattern matching
     * 5. Fixed-width column parsing
     * 6. Keyword-based section parsing
     *
     * @param Client $client The client whose report is being parsed
     * @param string $pdfPath Absolute path to the uploaded PDF file
     * @return int Number of items successfully imported
     * @throws \Exception If parsing fails
     */
    public function parsePdfAndSave(Client $client, string $pdfPath): int
    {
        try {
            DB::beginTransaction();

            // Step 1: Extract text from PDF
            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();

            // Step 2: Check if OCR is needed (scanned PDF)
            if ($this->ocrService && $this->ocrService->needsOcr($pdfPath, $text)) {
                Log::info('PDF appears to be scanned, attempting OCR...');
                try {
                    $text = $this->ocrService->extractText($pdfPath);
                    Log::info('OCR extraction successful');
                } catch (\Exception $e) {
                    Log::warning('OCR failed, using original text: ' . $e->getMessage());
                }
            }

            // Step 3: Try multiple parsing strategies
            // Collect all items first, then deduplicate before saving
            $allItems = [];
            
            // Strategy 1: Pipe-separated format
            $items1 = $this->parsePipeSeparated($client, $text);
            $allItems = array_merge($allItems, $items1);
            if (!empty($items1)) {
                Log::info("Found " . count($items1) . " items using pipe-separated format");
            }

            // Strategy 2: Tab-separated format
            if (empty($allItems)) {
                $items2 = $this->parseTabSeparated($client, $text);
                $allItems = array_merge($allItems, $items2);
                if (!empty($items2)) {
                    Log::info("Found " . count($items2) . " items using tab-separated format");
                }
            }

            // Strategy 3: Comma-separated format (CSV-like)
            if (empty($allItems)) {
                $items3 = $this->parseCommaSeparated($client, $text);
                $allItems = array_merge($allItems, $items3);
                if (!empty($items3)) {
                    Log::info("Found " . count($items3) . " items using comma-separated format");
                }
            }

            // Strategy 4: Regex pattern matching
            if (empty($allItems)) {
                $items4 = $this->parseWithRegex($client, $text);
                $allItems = array_merge($allItems, $items4);
                if (!empty($items4)) {
                    Log::info("Found " . count($items4) . " items using regex pattern matching");
                }
            }

            // Strategy 5: Fixed-width column parsing
            if (empty($allItems)) {
                $items5 = $this->parseFixedWidth($client, $text);
                $allItems = array_merge($allItems, $items5);
                if (!empty($items5)) {
                    Log::info("Found " . count($items5) . " items using fixed-width format");
                }
            }

            // Strategy 6: Keyword-based section parsing
            if (empty($allItems)) {
                $items6 = $this->parseByKeywords($client, $text);
                $allItems = array_merge($allItems, $items6);
                if (!empty($items6)) {
                    Log::info("Found " . count($items6) . " items using keyword-based parsing");
                }
            }

            // Strategy 7: IdentityIQ Full Parser (NEW - Complete parser)
            // Try this first if text looks like IdentityIQ format
            $isIdentityIqFormat = preg_match('/IdentityIQ|CREDIT SCORE DASHBOARD|PERSONAL PROFILE|CREDIT ACCOUNTS/i', $text);
            
            if ($isIdentityIqFormat || empty($allItems)) {
                try {
                    $fullParser = new \App\Services\IdentityIqFullParser();
                    $result = $fullParser->parseAndSave($client, $pdfPath);
                    
                    // If accounts were found, return success
                    if ($result['accounts'] > 0) {
                        DB::commit();
                        Log::info("Parsed IdentityIQ full report: " . 
                            ($result['scores'] ? 'scores saved, ' : '') .
                            "{$result['personal_profiles']} profiles, {$result['accounts']} accounts, " .
                            count($result['discrepancies']) . " discrepancies");
                        
                        return $result['accounts'];
                    }
                    
                    // If only scores/profiles but no accounts, continue to other strategies
                    Log::info("IdentityIQ parser found scores/profiles but no accounts, trying other strategies");
                } catch (\Exception $e) {
                    Log::warning("IdentityIQ full parser failed: " . $e->getMessage());
                    // Continue to other strategies
                }
            }
            
            // Fallback: Try IdentityIQ Structured Parser
            if (empty($allItems)) {
                try {
                    $identityIqParser = new \App\Services\PdfParsing\IdentityIqStructuredParser();
                    $items7 = $identityIqParser->parse($client, $text);
                    $allItems = array_merge($allItems, $items7);
                    if (!empty($items7)) {
                        Log::info("Found " . count($items7) . " items using IdentityIQ structured format");
                    }
                } catch (\Exception $e) {
                    Log::warning("IdentityIQ structured parser failed: " . $e->getMessage());
                }
            }

            // Step 4: Normalize and deduplicate items
            $normalizedItems = [];
            $seenKeys = [];
            
            foreach ($allItems as $item) {
                // Normalize item
                $normalized = $this->normalizer->normalizeItem($item);
                
                // Create unique key for deduplication
                $uniqueKey = $this->createUniqueKey($client->id, $normalized);
                
                if (!isset($seenKeys[$uniqueKey])) {
                    $seenKeys[$uniqueKey] = true;
                    $normalizedItems[] = $normalized;
                }
            }

            // Step 5: Save all unique items
            $importedCount = 0;
            foreach ($normalizedItems as $item) {
                if ($this->saveCreditItem($client, $item)) {
                    $importedCount++;
                }
            }

            DB::commit();

            if ($importedCount === 0) {
                // Log detailed information for debugging
                $textPreview = substr($text, 0, 2000);
                Log::error("Failed to parse any credit items from PDF. Text preview:\n" . $textPreview);
                Log::error("PDF path: {$pdfPath}");
                Log::error("Text length: " . strlen($text));
                
                // Check if it's IdentityIQ format but parser failed
                $isIdentityIq = preg_match('/IdentityIQ|CREDIT SCORE DASHBOARD|PERSONAL PROFILE|CREDIT ACCOUNTS/i', $text);
                if ($isIdentityIq) {
                    Log::error("PDF appears to be IdentityIQ format but parsing failed");
                }
                
                throw new \Exception("Could not parse any credit items from PDF. Please check the file format. Check logs for details.");
            }

            Log::info("Successfully imported {$importedCount} credit items from PDF for client {$client->id}");

            return $importedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to parse credit report PDF: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Strategy 1: Parse pipe-separated format
     * Format: Bureau | Account Name | Account Number | Balance | Status | Reason
     */
    private function parsePipeSeparated(Client $client, string $text): array
    {
        $lines = preg_split('/\R+/', $text);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 5) {
                continue;
            }

            $item = $this->extractItemFromParts($parts);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Strategy 2: Parse tab-separated format
     * Format: Bureau \t Account Name \t Account Number \t Balance \t Status \t Reason
     */
    private function parseTabSeparated(Client $client, string $text): array
    {
        $lines = preg_split('/\R+/', $text);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, "\t") === false) {
                continue;
            }

            $parts = array_map('trim', explode("\t", $line));
            if (count($parts) < 5) {
                continue;
            }

            $item = $this->extractItemFromParts($parts);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Strategy 3: Parse comma-separated format (CSV-like)
     * Format: Bureau, Account Name, Account Number, Balance, Status, Reason
     */
    private function parseCommaSeparated(Client $client, string $text): array
    {
        $lines = preg_split('/\R+/', $text);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ',') === false) {
                continue;
            }

            // Use str_getcsv to handle quoted values
            $parts = str_getcsv($line);
            if (count($parts) < 5) {
                continue;
            }

            $parts = array_map('trim', $parts);
            $item = $this->extractItemFromParts($parts);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Strategy 4: Parse using regex patterns
     * Tries to match common patterns in credit reports
     * IMPROVED: Now handles masked account numbers (XXXX1234, 1234****)
     */
    private function parseWithRegex(Client $client, string $text): array
    {
        $items = [];
        
        // Pattern 1: Bureau name followed by account info
        // Example: "TransUnion ABC BANK Account: 1234567890 Balance: $1,250.00"
        // IMPROVED: Handles masked accounts - Account: XXXX1234 or Account: 1234****
        $pattern1 = '/(?:TransUnion|Experian|Equifax)\s+([A-Z][A-Z\s&]+?)\s+(?:Account|Acct|#)[:\s]*([X\*\d]{4,})\s+(?:Balance|Bal|Amount)[:\s]*\$?([\d,]+\.?\d*)/i';
        
        preg_match_all($pattern1, $text, $matches1, PREG_SET_ORDER);
        foreach ($matches1 as $match) {
            $bureau = $this->normalizer->normalizeBureau($match[0]);
            if (!$bureau) continue;
            
            $items[] = [
                'bureau' => $bureau,
                'account_name' => trim($match[1]),
                'account_number' => trim($match[2]), // Can be masked: XXXX1234
                'balance' => $this->normalizer->normalizeBalance($match[3]),
                'status' => null,
                'reason' => null,
            ];
        }

        // Pattern 2: Account number (including masked), name, balance in various orders
        // IMPROVED: Handles masked accounts - XXXX1234, 1234****, ****1234
        $pattern2 = '/([X\*\d]{4,})\s+([A-Z][A-Z\s&]+?)\s+\$?([\d,]+\.?\d*)\s+([A-Z][A-Z\s]+)/i';
        
        if (empty($items)) {
            preg_match_all($pattern2, $text, $matches2, PREG_SET_ORDER);
            foreach ($matches2 as $match) {
                // Try to find bureau in surrounding context
                $context = $this->findBureauInContext($text, $match[0]);
                $bureau = $context ?: 'transunion'; // Default fallback
                
                $items[] = [
                    'bureau' => $bureau,
                    'account_name' => trim($match[2]),
                    'account_number' => trim($match[1]), // Can be masked
                    'balance' => $this->normalizer->normalizeBalance($match[3]),
                    'status' => trim($match[4]),
                    'reason' => null,
                ];
            }
        }

        // Pattern 3: Masked account in various formats
        // Examples: "XXXX1234", "1234****", "****-****-****-1234"
        $pattern3 = '/(?:Account|Acct|#)[:\s]*([X\*\-]{0,}\d{4,}[X\*\-]{0,})\s+([A-Z][A-Z\s&]+?)\s+\$?([\d,]+\.?\d*)/i';
        
        if (empty($items)) {
            preg_match_all($pattern3, $text, $matches3, PREG_SET_ORDER);
            foreach ($matches3 as $match) {
                $context = $this->findBureauInContext($text, $match[0]);
                $bureau = $context ?: 'transunion';
                
                $items[] = [
                    'bureau' => $bureau,
                    'account_name' => trim($match[2]),
                    'account_number' => trim($match[1]), // Masked format
                    'balance' => $this->normalizer->normalizeBalance($match[3]),
                    'status' => null,
                    'reason' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Strategy 5: Parse fixed-width columns with vertical alignment
     * IMPROVED: Uses vertical alignment logic instead of just spacing
     * Detects column positions by analyzing multiple lines together
     */
    private function parseFixedWidth(Client $client, string $text): array
    {
        $lines = preg_split('/\R+/', $text);
        $items = [];
        $dataLines = [];

        // Find lines that look like data (contain numbers and text)
        foreach ($lines as $line) {
            $line = rtrim($line); // Keep trailing spaces for alignment
            if (empty(trim($line))) continue;
            
            // Check if line contains account number pattern (including masked) and balance
            if (preg_match('/[X\*\d]{4,}/', $line) && preg_match('/\$?[\d,]+\.?\d*/', $line)) {
                $dataLines[] = $line;
            }
        }

        if (empty($dataLines)) {
            return [];
        }

        // IMPROVED: Vertical alignment - find column positions by analyzing all lines
        $columnPositions = $this->detectColumnPositions($dataLines);
        
        if (empty($columnPositions)) {
            // Fallback: Use spacing-based approach
            foreach ($dataLines as $line) {
                $parts = preg_split('/\s{2,}/', trim($line));
                if (count($parts) >= 4) {
                    $item = $this->extractItemFromFixedWidth($parts);
                    if ($item) {
                        $items[] = $item;
                    }
                }
            }
        } else {
            // Use vertical alignment positions
            foreach ($dataLines as $line) {
                $item = $this->extractItemFromAlignedColumns($line, $columnPositions);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Detect column positions using vertical alignment
     * Analyzes multiple lines to find consistent column boundaries
     */
    private function detectColumnPositions(array $lines): array
    {
        if (count($lines) < 3) {
            return []; // Need at least 3 lines to detect alignment
        }

        // Find common positions where columns start/end
        $positions = [];
        $maxLength = max(array_map('strlen', $lines));

        // Look for patterns: spaces followed by non-space (column start)
        for ($pos = 0; $pos < $maxLength; $pos++) {
            $columnStartCount = 0;
            $hasContent = false;

            foreach ($lines as $line) {
                if ($pos >= strlen($line)) continue;
                
                $char = $line[$pos];
                $prevChar = $pos > 0 ? ($line[$pos - 1] ?? ' ') : ' ';
                
                // Column start: space followed by non-space
                if ($prevChar === ' ' && $char !== ' ') {
                    $columnStartCount++;
                    $hasContent = true;
                }
            }

            // If most lines have a column start at this position, it's a column boundary
            if ($hasContent && $columnStartCount >= count($lines) * 0.6) {
                $positions[] = $pos;
            }
        }

        return $positions;
    }

    /**
     * Extract item from line using detected column positions
     */
    private function extractItemFromAlignedColumns(string $line, array $positions): ?array
    {
        if (empty($positions)) {
            return null;
        }

        $columns = [];
        $prevPos = 0;

        foreach ($positions as $pos) {
            $columns[] = trim(substr($line, $prevPos, $pos - $prevPos));
            $prevPos = $pos;
        }
        // Last column
        $columns[] = trim(substr($line, $prevPos));

        return $this->extractItemFromFixedWidth($columns);
    }

    /**
     * Strategy 6: Parse by keywords with dynamic boundary
     * IMPROVED: Uses dynamic boundary detection instead of fixed 2000 chars
     * Section ends at next bureau keyword or "End of Report"
     */
    private function parseByKeywords(Client $client, string $text): array
    {
        $items = [];
        $bureaus = [
            'transunion' => ['TransUnion', 'Trans Union', 'TU'],
            'experian' => ['Experian', 'EXP'],
            'equifax' => ['Equifax', 'EFX'],
        ];
        
        // Find all bureau positions
        $bureauPositions = [];
        foreach ($bureaus as $bureauKey => $bureauNames) {
            foreach ($bureauNames as $bureauName) {
                $pattern = '/\b' . preg_quote($bureauName, '/') . '\b/i';
                preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as $match) {
                    $bureauPositions[] = [
                        'bureau' => $bureauKey,
                        'position' => $match[1],
                        'name' => $bureauName,
                    ];
                }
            }
        }

        // Sort by position
        usort($bureauPositions, fn($a, $b) => $a['position'] <=> $b['position']);

        // Extract sections with dynamic boundaries
        foreach ($bureauPositions as $idx => $bureauPos) {
            $startPos = $bureauPos['position'];
            
            // Find end position: next bureau or end of text
            $endPos = strlen($text);
            if (isset($bureauPositions[$idx + 1])) {
                $endPos = $bureauPositions[$idx + 1]['position'];
            } else {
                // Look for "End of Report" or similar markers
                $endMarkers = ['/End of Report/i', '/End of Credit Report/i', '/Summary/i'];
                foreach ($endMarkers as $marker) {
                    if (preg_match($marker, $text, $endMatch, PREG_OFFSET_CAPTURE, $startPos)) {
                        $endPos = min($endPos, $endMatch[0][1]);
                    }
                }
            }

            // Extract section with dynamic boundary
            $section = substr($text, $startPos, $endPos - $startPos);
            
            // Extract account numbers (including masked) and balances
            preg_match_all('/([X\*\d]{4,})/i', $section, $accountMatches, PREG_SET_ORDER);
            preg_match_all('/\$?([\d,]+\.?\d*)/', $section, $balanceMatches, PREG_SET_ORDER);
            
            // Match accounts with balances (proximity-based)
            foreach ($accountMatches as $accountMatch) {
                $accountNumber = trim($accountMatch[1]);
                $accountPos = $accountMatch[0][1];
                
                // Find nearest balance
                $nearestBalance = 0;
                $minDistance = PHP_INT_MAX;
                foreach ($balanceMatches as $balanceMatch) {
                    $balancePos = $balanceMatch[0][1];
                    $distance = abs($balancePos - $accountPos);
                    if ($distance < $minDistance && $distance < 200) { // Within 200 chars
                        $minDistance = $distance;
                        $nearestBalance = $this->normalizer->normalizeBalance($balanceMatch[1]);
                    }
                }
                
                // Extract account name near account number
                $accountName = $this->extractAccountNameNearNumber($section, $accountNumber);
                
                $items[] = [
                    'bureau' => $bureauPos['bureau'],
                    'account_name' => $accountName ?: 'Unknown',
                    'account_number' => $accountNumber,
                    'balance' => $nearestBalance,
                    'status' => null,
                    'reason' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * Extract item data from array of parts
     */
    private function extractItemFromParts(array $parts): ?array
    {
        if (count($parts) < 5) {
            return null;
        }

        [$bureauRaw, $accountName, $accountNumber, $balanceRaw, $status] = $parts;
        $reason = $parts[5] ?? null;

        $bureau = $this->normalizer->normalizeBureau($bureauRaw);
        if (!$bureau) {
            return null;
        }

        return [
            'bureau' => $bureau,
            'account_name' => trim($accountName),
            'account_number' => trim($accountNumber),
            'balance' => $this->normalizer->normalizeBalance($balanceRaw),
            'status' => trim($status) ?: null,
            'reason' => trim($reason) ?: null,
        ];
    }

    /**
     * Extract item from fixed-width columns
     */
    private function extractItemFromFixedWidth(array $parts): ?array
    {
        // Try to identify which part is which
        $bureau = null;
        $accountName = null;
        $accountNumber = null;
        $balance = 0;
        $status = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            // Check if it's a bureau
            $normalized = $this->normalizeBureau($part);
            if ($normalized) {
                $bureau = $normalized;
                continue;
            }

            // Check if it's an account number (8+ digits)
            if (preg_match('/^\d{8,}$/', $part)) {
                $accountNumber = $part;
                continue;
            }

            // Check if it's a balance (contains $ or number with decimals)
            if (preg_match('/\$?[\d,]+\.?\d*/', $part, $matches)) {
                $balance = floatval(str_replace([',', '$'], '', $matches[0]));
                continue;
            }

            // Check if it's a status (common credit status terms)
            if (preg_match('/\b(charge.?off|collection|late|delinquent|default|closed|paid|current)\b/i', $part)) {
                $status = $part;
                continue;
            }

            // Otherwise, assume it's account name
            if (!$accountName && strlen($part) > 3) {
                $accountName = $part;
            }
        }

        if (!$bureau || !$accountName || !$accountNumber) {
            return null;
        }

        return [
            'bureau' => $bureau,
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'balance' => $balance,
            'status' => $status,
            'reason' => null,
        ];
    }

    /**
     * Find bureau name in context around a match
     */
    private function findBureauInContext(string $text, string $match): ?string
    {
        $pos = strpos($text, $match);
        if ($pos === false) {
            return null;
        }

        // Look 500 chars before and after
        $context = substr($text, max(0, $pos - 500), 1000);
        return $this->normalizer->normalizeBureau($context);
    }

    /**
     * Create unique key for deduplication
     * Uses: client_id + bureau + normalized account number
     */
    private function createUniqueKey(int $clientId, array $item): string
    {
        $accountNumber = $this->normalizer->normalizeAccountNumber($item['account_number'] ?? '');
        return md5("{$clientId}_{$item['bureau']}_{$accountNumber}");
    }

    /**
     * Extract account name near an account number
     */
    private function extractAccountNameNearNumber(string $text, string $accountNumber): ?string
    {
        $pos = strpos($text, $accountNumber);
        if ($pos === false) {
            return null;
        }

        // Get text before account number (up to 100 chars)
        $before = substr($text, max(0, $pos - 100), 100);
        
        // Try to find company/bank name pattern
        if (preg_match('/([A-Z][A-Z\s&]+(?:BANK|CREDIT|LOAN|FINANCIAL|CORP|INC))/i', $before, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: get first capitalized words before number
        if (preg_match('/([A-Z][A-Z\s&]{3,})/i', $before, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Save credit item to database (with duplicate check)
     * IMPROVED: Uses normalized account number for duplicate detection
     */
    private function saveCreditItem(Client $client, array $item): bool
    {
        // Validate required fields
        if (empty($item['bureau']) || empty($item['account_name']) || empty($item['account_number'])) {
            return false;
        }

        // Normalize account number for duplicate check
        $normalizedAccountNumber = $this->normalizer->normalizeAccountNumber($item['account_number']);

        // Check for duplicates using normalized account number
        $exists = CreditItem::where('client_id', $client->id)
            ->where('bureau', $item['bureau'])
            ->where(function($query) use ($item, $normalizedAccountNumber) {
                $query->where('account_number', $item['account_number'])
                      ->orWhereRaw('SUBSTRING(account_number, -4) = ?', [$normalizedAccountNumber]);
            })
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            CreditItem::create([
                'client_id' => $client->id,
                'bureau' => $item['bureau'],
                'account_name' => $item['account_name'],
                'account_number' => $item['account_number'], // Keep original format
                'account_type' => $item['account_type'] ?? null,
                'date_opened' => $item['date_opened'] ?? null,
                'balance' => $item['balance'] ?? 0,
                'high_limit' => $item['high_limit'] ?? null,
                'monthly_pay' => $item['monthly_pay'] ?? null,
                'reason' => $item['reason'] ?? null,
                'status' => $item['status'] ?? null,
                'dispute_status' => CreditItem::STATUS_PENDING,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::warning("Failed to save credit item: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Parse items for a specific bureau from the crawler.
     *
     * @param Crawler $crawler The DOM crawler instance
     * @param string $bureau The bureau name
     * @return array<int, array<string, mixed>> Array of parsed item data
     */
    private function parseItemsForBureau(Crawler $crawler, string $bureau): array
    {
        $items = [];

        try {
            // Example selectors - adjust based on actual IdentityIQ HTML structure
            // This is a flexible approach that looks for common patterns

            // Try to find bureau-specific sections
            $bureauSection = $crawler->filter("[data-bureau=\"{$bureau}\"], .bureau-{$bureau}, #{$bureau}-section");

            if ($bureauSection->count() === 0) {
                // Fallback: try to find all account items and filter by bureau text
                $bureauSection = $crawler;
            }

            // Look for negative/derogatory items
            $accountItems = $bureauSection->filter(
                '.account-item, .negative-item, .derogatory-item, .trade-line, [data-account-type="negative"]'
            );

            $accountItems->each(function (Crawler $node) use (&$items) {
                try {
                    $item = $this->extractItemData($node);
                    if (!empty($item['account_name'])) {
                        $items[] = $item;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to parse individual item: {$e->getMessage()}");
                }
            });
        } catch (\Exception $e) {
            Log::warning("Failed to parse items for bureau {$bureau}: {$e->getMessage()}");
        }

        return $items;
    }

    /**
     * Extract item data from a single account node.
     *
     * @param Crawler $node The DOM node for a single account
     * @return array<string, mixed> The extracted item data
     */
    private function extractItemData(Crawler $node): array
    {
        $data = [
            'account_name' => '',
            'account_number' => '',
            'balance' => 0,
            'reason' => '',
            'status' => '',
        ];

        // Extract account name (creditor/company name)
        $accountNameNode = $node->filter(
            '.account-name, .creditor-name, .company-name, [data-field="account-name"]'
        );
        if ($accountNameNode->count() > 0) {
            $data['account_name'] = trim($accountNameNode->text());
        }

        // Extract account number
        $accountNumberNode = $node->filter(
            '.account-number, .account-id, [data-field="account-number"]'
        );
        if ($accountNumberNode->count() > 0) {
            $data['account_number'] = trim($accountNumberNode->text());
        }

        // Extract balance
        $balanceNode = $node->filter(
            '.balance, .amount, .debt-amount, [data-field="balance"]'
        );
        if ($balanceNode->count() > 0) {
            $balanceText = trim($balanceNode->text());
            // Remove currency symbols and commas
            $balanceText = preg_replace('/[^0-9.]/', '', $balanceText);
            $data['balance'] = floatval($balanceText);
        }

        // Extract status
        $statusNode = $node->filter(
            '.status, .account-status, [data-field="status"]'
        );
        if ($statusNode->count() > 0) {
            $data['status'] = trim($statusNode->text());
        }

        // Extract reason (payment status, remarks, etc.)
        $reasonNode = $node->filter(
            '.reason, .remarks, .payment-status, [data-field="reason"]'
        );
        if ($reasonNode->count() > 0) {
            $data['reason'] = trim($reasonNode->text());
        }

        // If no specific selectors work, try to extract from the entire node text
        if (empty($data['account_name'])) {
            $fullText = trim($node->text());
            // Try to extract account name from first line or strong text
            $strongNode = $node->filter('strong, b, .title');
            if ($strongNode->count() > 0) {
                $data['account_name'] = trim($strongNode->first()->text());
            } elseif (!empty($fullText)) {
                // Take first line as account name
                $lines = explode("\n", $fullText);
                $data['account_name'] = trim($lines[0]);
            }
        }

        return $data;
    }

    /**
     * Alternative method: Parse from simple HTML table structure.
     * Use this if IdentityIQ provides data in a table format.
     *
     * @param Client $client
     * @param string $htmlContent
     * @return int
     */
    public function parseFromTable(Client $client, string $htmlContent): int
    {
        try {
            DB::beginTransaction();

            $crawler = new Crawler($htmlContent);
            $importedCount = 0;

            // Find tables with credit data
            $tables = $crawler->filter('table');

            $tables->each(function (Crawler $table) use ($client, &$importedCount) {
                $rows = $table->filter('tr');

                // Skip header row
                $rows->slice(1)->each(function (Crawler $row) use ($client, &$importedCount) {
                    $cells = $row->filter('td');

                    if ($cells->count() >= 5) {
                        $bureau = strtolower(trim($cells->eq(0)->text()));
                        $accountName = trim($cells->eq(1)->text());
                        $accountNumber = trim($cells->eq(2)->text());
                        $balanceText = preg_replace('/[^0-9.]/', '', $cells->eq(3)->text());
                        $status = trim($cells->eq(4)->text());

                        // Validate bureau
                        if (!in_array($bureau, ['transunion', 'experian', 'equifax'])) {
                            return;
                        }

                        CreditItem::create([
                            'client_id' => $client->id,
                            'bureau' => $bureau,
                            'account_name' => $accountName,
                            'account_number' => $accountNumber,
                            'balance' => floatval($balanceText),
                            'status' => $status,
                            'dispute_status' => CreditItem::STATUS_PENDING,
                        ]);

                        $importedCount++;
                    }
                });
            });

            DB::commit();

            return $importedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to parse credit report from table: {$e->getMessage()}");
            throw $e;
        }
    }
}