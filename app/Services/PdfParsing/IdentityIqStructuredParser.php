<?php

namespace App\Services\PdfParsing;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Specialized parser for IdentityIQ structured credit reports
 * Handles nested bureau information and account details
 */
class IdentityIqStructuredParser
{
    protected DataNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new DataNormalizer();
    }

    /**
     * Parse IdentityIQ structured format
     * 
     * Expected structure:
     * CREDIT ACCOUNTS (TRADE LINES):
     * 1. ACCOUNT NAME
     *    Account #: XXXX****
     *    Bureau: All Bureaus / TransUnion / Experian / Equifax
     *    Details by Bureau:
     *      TransUnion:
     *        Balance: $X,XXX.XX
     *        Pay Status: ...
     */
    public function parse(Client $client, string $text): array
    {
        $items = [];
        
        // Find CREDIT ACCOUNTS section
        $accountsSection = $this->extractAccountsSection($text);
        if (empty($accountsSection)) {
            return [];
        }

        // Parse each account
        $accounts = $this->extractAccounts($accountsSection);
        
        foreach ($accounts as $accountData) {
            // If "All Bureaus", create items for each bureau
            if (isset($accountData['bureau']) && strtolower($accountData['bureau']) === 'all bureaus') {
                $bureaus = ['transunion', 'experian', 'equifax'];
                foreach ($bureaus as $bureau) {
                    $item = $this->buildItemFromAccount($accountData, $bureau);
                    if ($item) {
                        $items[] = $item;
                    }
                }
            } else {
                // Single bureau or specific bureau
                $bureau = $this->normalizer->normalizeBureau($accountData['bureau'] ?? 'transunion');
                $item = $this->buildItemFromAccount($accountData, $bureau);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Extract the CREDIT ACCOUNTS section from text
     */
    private function extractAccountsSection(string $text): string
    {
        // Look for section markers
        $patterns = [
            '/CREDIT ACCOUNTS[^\n]*\n(.*?)(?=PERSONAL PROFILE|CREDIT SCORE|$)/is',
            '/TRADE LINES[^\n]*\n(.*?)(?=PERSONAL PROFILE|CREDIT SCORE|$)/is',
            '/ACCOUNTS[^\n]*\n(.*?)(?=PERSONAL PROFILE|CREDIT SCORE|$)/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $matches[1];
            }
        }

        // Fallback: return text after "CREDIT" if found
        if (preg_match('/CREDIT/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            return substr($text, $pos);
        }

        return $text;
    }

    /**
     * Extract individual accounts from section
     */
    private function extractAccounts(string $section): array
    {
        $accounts = [];
        
        // Pattern 1: Numbered accounts "1. ACCOUNT NAME"
        $pattern1 = '/(\d+)\.\s+([A-Z][A-Z\s&]+?)\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/i';
        preg_match_all($pattern1, $section, $matches1, PREG_SET_ORDER);
        
        foreach ($matches1 as $match) {
            $accountNumber = trim($match[3]);
            $accountName = trim($match[2]);
            
            // Extract details for this account
            $accountDetails = $this->extractAccountDetails($section, $accountName, $accountNumber);
            $accountDetails['account_name'] = $accountName;
            $accountDetails['account_number'] = $accountNumber;
            
            $accounts[] = $accountDetails;
        }

        // Pattern 2: Account name followed by Account #
        if (empty($accounts)) {
            $pattern2 = '/([A-Z][A-Z\s&]{3,})\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/i';
            preg_match_all($pattern2, $section, $matches2, PREG_SET_ORDER);
            
            foreach ($matches2 as $match) {
                $accountName = trim($match[1]);
                $accountNumber = trim($match[2]);
                
                $accountDetails = $this->extractAccountDetails($section, $accountName, $accountNumber);
                $accountDetails['account_name'] = $accountName;
                $accountDetails['account_number'] = $accountNumber;
                
                $accounts[] = $accountDetails;
            }
        }

        return $accounts;
    }

    /**
     * Extract details for a specific account
     * IMPROVED: Now extracts account_type, date_opened, high_limit, monthly_pay
     */
    private function extractAccountDetails(string $section, string $accountName, string $accountNumber): array
    {
        $details = [
            'bureau' => null,
            'account_type' => null,
            'date_opened' => null,
            'balance' => 0,
            'high_limit' => null,
            'monthly_pay' => null,
            'status' => null,
            'reason' => null,
        ];

        // Find section for this account (between account name and next account or end)
        $accountPattern = preg_quote($accountName, '/');
        if (preg_match('/' . $accountPattern . '.*?(?=\d+\.\s+[A-Z]|$)/is', $section, $accountMatch)) {
            $accountSection = $accountMatch[0];
            
            // Extract Bureau information
            if (preg_match('/Bureau[:\s]*(All Bureaus|TransUnion|Experian|Equifax)/i', $accountSection, $bureauMatch)) {
                $details['bureau'] = trim($bureauMatch[1]);
            }

            // Extract Account Type (NEW)
            if (preg_match('/Account Type[:\s]*([^\n]+)/i', $accountSection, $typeMatch)) {
                $details['account_type'] = trim($typeMatch[1]);
            }

            // Extract Date Opened (NEW)
            if (preg_match('/Date Opened[:\s]*([0-9\/\-]+)/i', $accountSection, $dateMatch)) {
                $dateStr = trim($dateMatch[1]);
                $details['date_opened'] = $this->parseDate($dateStr);
            }

            // Extract balance (look for "Balance: $X,XXX.XX")
            if (preg_match('/Balance[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $balanceMatch)) {
                $details['balance'] = $this->normalizer->normalizeBalance($balanceMatch[1]);
            }

            // Extract High Limit (NEW)
            if (preg_match('/High Limit[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $limitMatch)) {
                $details['high_limit'] = $this->normalizer->normalizeBalance($limitMatch[1]);
            }

            // Extract Monthly Pay (NEW)
            if (preg_match('/Monthly Pay[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $payMatch)) {
                $details['monthly_pay'] = $this->normalizer->normalizeBalance($payMatch[1]);
            }

            // Extract Pay Status
            if (preg_match('/Pay Status[:\s]*([^\n]+)/i', $accountSection, $statusMatch)) {
                $details['status'] = trim($statusMatch[1]);
            }

            // Extract Comments/Reason
            if (preg_match('/Comments?[:\s]*([^\n]+)/i', $accountSection, $commentMatch)) {
                $details['reason'] = trim($commentMatch[1]);
            }

            // If "Details by Bureau", extract bureau-specific info
            if (preg_match('/Details by Bureau/i', $accountSection)) {
                $details['bureau_details'] = $this->extractBureauDetails($accountSection);
            }
        }

        return $details;
    }

    /**
     * Extract details for each bureau when "Details by Bureau" is present
     * IMPROVED: Now extracts high_limit and monthly_pay per bureau
     */
    private function extractBureauDetails(string $accountSection): array
    {
        $bureauDetails = [];
        $bureaus = ['TransUnion', 'Experian', 'Equifax'];

        foreach ($bureaus as $bureau) {
            $pattern = '/' . preg_quote($bureau, '/') . '[:\s]*\n(.*?)(?=' . implode('|', array_map('preg_quote', $bureaus)) . '|$)/is';
            
            if (preg_match($pattern, $accountSection, $matches)) {
                $bureauSection = $matches[1];
                
                $details = [
                    'balance' => 0,
                    'high_limit' => null,
                    'monthly_pay' => null,
                    'status' => null,
                    'reason' => null,
                ];

                // Extract balance
                if (preg_match('/Balance[:\s]*\$?([\d,]+\.?\d*)/i', $bureauSection, $balanceMatch)) {
                    $details['balance'] = $this->normalizer->normalizeBalance($balanceMatch[1]);
                }

                // Extract High Limit (per bureau)
                if (preg_match('/High Limit[:\s]*\$?([\d,]+\.?\d*)/i', $bureauSection, $limitMatch)) {
                    $details['high_limit'] = $this->normalizer->normalizeBalance($limitMatch[1]);
                }

                // Extract Monthly Pay (per bureau)
                if (preg_match('/Monthly Pay[:\s]*\$?([\d,]+\.?\d*)/i', $bureauSection, $payMatch)) {
                    $details['monthly_pay'] = $this->normalizer->normalizeBalance($payMatch[1]);
                }

                // Extract Pay Status
                if (preg_match('/Pay Status[:\s]*([^\n]+)/i', $bureauSection, $statusMatch)) {
                    $details['status'] = trim($statusMatch[1]);
                }

                // Extract Comments
                if (preg_match('/Comments?[:\s]*([^\n]+)/i', $bureauSection, $commentMatch)) {
                    $details['reason'] = trim($commentMatch[1]);
                }

                $bureauDetails[strtolower($bureau)] = $details;
            }
        }

        return $bureauDetails;
    }

    /**
     * Build credit item from account data
     * IMPROVED: Now includes account_type, date_opened, high_limit, monthly_pay
     */
    private function buildItemFromAccount(array $accountData, string $bureau): ?array
    {
        // If bureau-specific details exist, use them
        if (isset($accountData['bureau_details'][$bureau])) {
            $bureauData = $accountData['bureau_details'][$bureau];
            return [
                'bureau' => $bureau,
                'account_name' => $accountData['account_name'],
                'account_number' => $accountData['account_number'],
                'account_type' => $accountData['account_type'] ?? null,
                'date_opened' => $accountData['date_opened'] ?? null,
                'balance' => $bureauData['balance'] ?? $accountData['balance'] ?? 0,
                'high_limit' => $bureauData['high_limit'] ?? $accountData['high_limit'] ?? null,
                'monthly_pay' => $bureauData['monthly_pay'] ?? $accountData['monthly_pay'] ?? null,
                'status' => $bureauData['status'] ?? $accountData['status'] ?? null,
                'reason' => $bureauData['reason'] ?? $accountData['reason'] ?? null,
            ];
        }

        // Otherwise use general account data
        return [
            'bureau' => $bureau,
            'account_name' => $accountData['account_name'] ?? 'Unknown',
            'account_number' => $accountData['account_number'] ?? '',
            'account_type' => $accountData['account_type'] ?? null,
            'date_opened' => $accountData['date_opened'] ?? null,
            'balance' => $accountData['balance'] ?? 0,
            'high_limit' => $accountData['high_limit'] ?? null,
            'monthly_pay' => $accountData['monthly_pay'] ?? null,
            'status' => $accountData['status'] ?? null,
            'reason' => $accountData['reason'] ?? null,
        ];
    }

    /**
     * Parse date string to Carbon date
     * Handles formats: MM/DD/YYYY, YYYY-MM-DD, DD-MM-YYYY
     */
    private function parseDate(string $dateStr): ?string
    {
        $dateStr = trim($dateStr);
        
        // Try MM/DD/YYYY format (most common in US)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }

        // Try YYYY-MM-DD format
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateStr, $matches)) {
            return $dateStr;
        }

        // Try DD-MM-YYYY format
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $dateStr, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }

        // Try to parse with Carbon
        try {
            return \Carbon\Carbon::parse($dateStr)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning("Failed to parse date: {$dateStr}");
            return null;
        }
    }
}

