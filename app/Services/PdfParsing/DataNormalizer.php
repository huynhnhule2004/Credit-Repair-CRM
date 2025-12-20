<?php

namespace App\Services\PdfParsing;

class DataNormalizer
{
    /**
     * Normalize account number (handle masked accounts)
     * Examples: XXXX1234, 1234****, 1234-****-****-5678 -> 1234
     *
     * @param string $accountNumber
     * @return string Normalized account number (last 4 digits or full if unmasked)
     */
    public function normalizeAccountNumber(string $accountNumber): string
    {
        $accountNumber = trim($accountNumber);
        
        // Remove common separators
        $accountNumber = str_replace(['-', ' ', '_'], '', $accountNumber);
        
        // Extract last 4 digits if masked
        // Pattern: XXXX1234, 1234****, ****1234, etc.
        if (preg_match('/(\d{4})(?:[X\*]+|\d*)$/', $accountNumber, $matches)) {
            return $matches[1];
        }
        
        // If fully masked, try to extract any digits
        if (preg_match('/(\d+)/', $accountNumber, $matches)) {
            return $matches[1];
        }
        
        // Return as-is if no pattern matches
        return $accountNumber;
    }

    /**
     * Normalize balance to float
     * Handles: $1,200.00, 1200, 1.200,00 (European format), etc.
     *
     * @param string|float $balance
     * @return float
     */
    public function normalizeBalance($balance): float
    {
        if (is_numeric($balance)) {
            return (float) $balance;
        }

        $balance = trim((string) $balance);
        
        // Remove currency symbols
        $balance = preg_replace('/[^\d.,\-]/', '', $balance);
        
        // Handle European format (1.200,00)
        if (preg_match('/^(\d{1,3}(?:\.\d{3})*),(\d+)$/', $balance, $matches)) {
            $balance = str_replace('.', '', $matches[1]) . '.' . $matches[2];
        } else {
            // Remove thousand separators (commas or dots)
            $balance = str_replace(',', '', $balance);
        }
        
        return (float) $balance;
    }

    /**
     * Normalize status to standard format
     * Maps: Chrg Off, Charged-off, C/O -> CHARGED_OFF
     *
     * @param string|null $status
     * @return string|null Normalized status
     */
    public function normalizeStatus(?string $status): ?string
    {
        if (empty($status)) {
            return null;
        }

        $status = strtolower(trim($status));
        
        // Status mapping
        $statusMap = [
            // Charged Off variations
            'charged off' => 'CHARGED_OFF',
            'charge off' => 'CHARGED_OFF',
            'charge-off' => 'CHARGED_OFF',
            'charged-off' => 'CHARGED_OFF',
            'chrg off' => 'CHARGED_OFF',
            'c/o' => 'CHARGED_OFF',
            'co' => 'CHARGED_OFF',
            
            // Collection variations
            'collection' => 'COLLECTION',
            'collections' => 'COLLECTION',
            'in collection' => 'COLLECTION',
            
            // Late Payment variations
            'late payment' => 'LATE_PAYMENT',
            'late' => 'LATE_PAYMENT',
            'delinquent' => 'LATE_PAYMENT',
            'delinquency' => 'LATE_PAYMENT',
            
            // Default variations
            'default' => 'DEFAULT',
            'defaulted' => 'DEFAULT',
            
            // Closed variations
            'closed' => 'CLOSED',
            'closed account' => 'CLOSED',
            
            // Paid variations
            'paid' => 'PAID',
            'paid off' => 'PAID',
            'satisfied' => 'PAID',
            
            // Current variations
            'current' => 'CURRENT',
            'open' => 'CURRENT',
            'active' => 'CURRENT',
        ];

        // Check exact match first
        if (isset($statusMap[$status])) {
            return $statusMap[$status];
        }

        // Check partial match
        foreach ($statusMap as $key => $value) {
            if (strpos($status, $key) !== false) {
                return $value;
            }
        }

        // Return uppercase version if no match
        return strtoupper(str_replace(' ', '_', $status));
    }

    /**
     * Normalize bureau name
     *
     * @param string $bureau
     * @return string|null Normalized bureau (transunion, experian, equifax) or null
     */
    public function normalizeBureau(string $bureau): ?string
    {
        $bureau = strtolower(trim($bureau));
        
        if (stripos($bureau, 'transunion') !== false || stripos($bureau, 'trans union') !== false) {
            return 'transunion';
        }
        if (stripos($bureau, 'experian') !== false) {
            return 'experian';
        }
        if (stripos($bureau, 'equifax') !== false) {
            return 'equifax';
        }
        
        return null;
    }

    /**
     * Normalize account name (remove extra spaces, clean up)
     *
     * @param string $accountName
     * @return string
     */
    public function normalizeAccountName(string $accountName): string
    {
        $accountName = trim($accountName);
        
        // Remove multiple spaces
        $accountName = preg_replace('/\s+/', ' ', $accountName);
        
        // Remove common prefixes/suffixes that might be noise
        $accountName = preg_replace('/^(THE|A|AN)\s+/i', '', $accountName);
        
        return $accountName;
    }

    /**
     * Normalize entire credit item array
     *
     * @param array $item
     * @return array Normalized item
     */
    public function normalizeItem(array $item): array
    {
        return [
            'bureau' => $this->normalizeBureau($item['bureau'] ?? ''),
            'account_name' => $this->normalizeAccountName($item['account_name'] ?? ''),
            'account_number' => $this->normalizeAccountNumber($item['account_number'] ?? ''),
            'balance' => $this->normalizeBalance($item['balance'] ?? 0),
            'status' => $this->normalizeStatus($item['status'] ?? null),
            'reason' => !empty($item['reason']) ? trim($item['reason']) : null,
        ];
    }
}

