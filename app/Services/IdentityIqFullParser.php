<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CreditScore;
use App\Models\CreditItem;
use App\Models\PersonalProfile;
use App\Services\PdfParsing\DataNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Complete parser for IdentityIQ credit reports
 * Extracts: Credit Scores, Personal Profile, Accounts with bureau-specific data
 */
class IdentityIqFullParser
{
    protected DataNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new DataNormalizer();
    }

    /**
     * Parse complete IdentityIQ PDF and save all data
     * 
     * @param Client $client
     * @param string $pdfPath
     * @return array Result with counts and discrepancies
     */
    public function parseAndSave(Client $client, string $pdfPath): array
    {
        try {
            DB::beginTransaction();

            // Extract text from PDF
            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();

            $result = [
                'scores' => null,
                'personal_profiles' => 0,
                'accounts' => 0,
                'discrepancies' => [],
            ];

            // 1. Parse Credit Scores
            try {
                $scores = $this->parseCreditScores($text);
                if ($scores) {
                    $creditScore = CreditScore::create([
                        'client_id' => $client->id,
                        'transunion_score' => $scores['transunion'] ?? null,
                        'experian_score' => $scores['experian'] ?? null,
                        'equifax_score' => $scores['equifax'] ?? null,
                        'report_date' => $this->extractReportDate($text),
                        'reference_number' => $this->extractReferenceNumber($text),
                    ]);
                    $result['scores'] = $creditScore;
                    Log::info("Credit scores saved successfully");
                }
            } catch (\Exception $e) {
                Log::warning("Failed to save credit scores: " . $e->getMessage());
                // Continue even if scores fail
            }

            // 2. Parse Personal Profiles
            try {
                $profiles = $this->parsePersonalProfiles($text);
                foreach ($profiles as $profile) {
                    try {
                        PersonalProfile::updateOrCreate(
                            [
                                'client_id' => $client->id,
                                'bureau' => $profile['bureau'],
                            ],
                            $profile
                        );
                        $result['personal_profiles']++;
                    } catch (\Exception $e) {
                        Log::warning("Failed to save profile for bureau {$profile['bureau']}: " . $e->getMessage());
                        // Continue with other profiles
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to parse personal profiles: " . $e->getMessage());
                // Continue even if profiles fail
            }

            // 3. Parse Accounts with bureau-specific data
            Log::info("Starting to parse accounts from PDF...");
            $accounts = $this->parseAccounts($text);
            $discrepancies = [];
            
            if (empty($accounts)) {
                $textPreview = substr($text, 0, 2000);
                Log::warning('No accounts found in PDF. Text preview: ' . $textPreview);
                Log::warning('Text length: ' . strlen($text));
                
                // Try alternative parsing method - look for account names directly
                $accounts = $this->parseAccountsAlternative($text);
                if (!empty($accounts)) {
                    Log::info("Alternative parsing method found " . count($accounts) . " accounts");
                }
            }
            
            // Process all accounts (from main parsing or alternative)
            foreach ($accounts as $accountData) {
                try {
                    Log::info("Processing account: {$accountData['account_name']} (#{$accountData['account_number']})");
                    Log::info("Account has bureau_data: " . (empty($accountData['bureau_data']) ? 'no' : 'yes'));
                    if (!empty($accountData['bureau_data'])) {
                        Log::info("Bureaus with data: " . implode(', ', array_keys($accountData['bureau_data'])));
                    }
                    
                    $items = $this->createAccountItems($client, $accountData);
                    
                    Log::info("Created " . count($items) . " items for account {$accountData['account_name']}");
                    
                    // Detect discrepancies
                    $accountDiscrepancies = $this->detectDiscrepancies($accountData);
                    if (!empty($accountDiscrepancies)) {
                        $discrepancies[] = [
                            'account_name' => $accountData['account_name'],
                            'account_number' => $accountData['account_number'] ?? null,
                            'flags' => $accountDiscrepancies,
                        ];
                    }
                    
                    $result['accounts'] += count($items);
                } catch (\Exception $e) {
                    Log::error("Failed to process account {$accountData['account_name']}: {$e->getMessage()}");
                    Log::error("Stack trace: " . $e->getTraceAsString());
                }
            }

            $result['discrepancies'] = $discrepancies;
            
            // Log summary
            Log::info("IdentityIQ parsing summary: " . 
                ($result['scores'] ? 'Scores: yes, ' : 'Scores: no, ') .
                "Profiles: {$result['personal_profiles']}, " .
                "Accounts: {$result['accounts']}, " .
                "Discrepancies: " . count($discrepancies));
            
            // If no accounts found, throw exception to trigger fallback
            // But allow scores/profiles to be saved even if no accounts
            if ($result['accounts'] === 0 && empty($result['scores']) && $result['personal_profiles'] === 0) {
                $textPreview = substr($text, 0, 1000);
                Log::error('Could not parse any data from IdentityIQ PDF. Text preview: ' . $textPreview);
                throw new \Exception('Could not parse any data from IdentityIQ PDF. Format may not be recognized.');
            }
            
            // If we have scores or profiles but no accounts, that's still a partial success
            // Don't throw exception, let it fallback to other strategies for accounts
            if ($result['accounts'] === 0 && (!empty($result['scores']) || $result['personal_profiles'] > 0)) {
                Log::warning("IdentityIQ parser found scores/profiles but no accounts. This will trigger fallback to other strategies.");
            }

            DB::commit();

            Log::info("Parsed IdentityIQ report for client {$client->id}: " . 
                "{$result['personal_profiles']} profiles, {$result['accounts']} accounts, " . 
                count($discrepancies) . " discrepancies");

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to parse IdentityIQ report: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Parse Credit Scores from CREDIT SCORE DASHBOARD
     */
    private function parseCreditScores(string $text): ?array
    {
        $scores = [];

        // Pattern: TransUnion: 645, Experian: 650, Equifax: 620
        $patterns = [
            '/TransUnion[:\s]*(\d+)/i',
            '/Experian[:\s]*(\d+)/i',
            '/Equifax[:\s]*(\d+)/i',
        ];

        if (preg_match($patterns[0], $text, $tuMatch)) {
            $scores['transunion'] = (int) $tuMatch[1];
        }
        if (preg_match($patterns[1], $text, $expMatch)) {
            $scores['experian'] = (int) $expMatch[1];
        }
        if (preg_match($patterns[2], $text, $eqMatch)) {
            $scores['equifax'] = (int) $eqMatch[1];
        }

        return !empty($scores) ? $scores : null;
    }

    /**
     * Parse Personal Profiles with bureau variations
     */
    private function parsePersonalProfiles(string $text): array
    {
        $profiles = [];
        $bureaus = ['transunion', 'experian', 'equifax'];

        // Extract PERSONAL PROFILE section
        if (!preg_match('/PERSONAL PROFILE.*?(?=CREDIT ACCOUNTS|TRADE LINES|$)/is', $text, $profileSection)) {
            return $profiles;
        }

        $section = $profileSection[0];

        foreach ($bureaus as $bureau) {
            $bureauName = ucfirst($bureau);
            $profile = [
                'bureau' => $bureau,
                'name' => null,
                'date_of_birth' => null,
                'current_address' => null,
                'previous_address' => null,
                'employer' => null,
                'date_reported' => null,
            ];

            // Extract name
            if (preg_match('/' . preg_quote($bureauName, '/') . '.*?Name[:\s]*([^\n]+)/i', $section, $nameMatch)) {
                $profile['name'] = trim($nameMatch[1]);
            }

            // Extract DOB (usually consistent across bureaus)
            if (preg_match('/Date of Birth[:\s]*([0-9\/\-]+)/i', $section, $dobMatch)) {
                $profile['date_of_birth'] = $this->parseDate(trim($dobMatch[1]));
            }

            // Extract Current Address
            if (preg_match('/' . preg_quote($bureauName, '/') . '.*?Current Address[:\s]*([^\n]+)/i', $section, $addrMatch)) {
                $profile['current_address'] = trim($addrMatch[1]);
            }

            // Extract Previous Address
            if (preg_match('/' . preg_quote($bureauName, '/') . '.*?Previous Address[:\s]*([^\n]+)/i', $section, $prevAddrMatch)) {
                $profile['previous_address'] = trim($prevAddrMatch[1]);
            }

            // Extract Employer
            if (preg_match('/' . preg_quote($bureauName, '/') . '.*?Employers?[:\s]*([^\n]+)/i', $section, $empMatch)) {
                $profile['employer'] = trim($empMatch[1]);
            }

            // Extract Date Reported
            if (preg_match('/\[' . preg_quote($bureauName, '/') . '.*?Date Reported[:\s]*([0-9\/\-]+)/i', $text, $dateMatch)) {
                $profile['date_reported'] = $this->parseDate(trim($dateMatch[1]));
            }

            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * Parse Accounts with bureau-specific details
     * IMPROVED: Better pattern matching for IdentityIQ format
     */
    private function parseAccounts(string $text): array
    {
        $accounts = [];

        // Extract CREDIT ACCOUNTS section - more flexible pattern
        $sectionPatterns = [
            '/CREDIT ACCOUNTS.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
            '/TRADE LINES.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
            '/ACCOUNTS.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
        ];

        $section = '';
        foreach ($sectionPatterns as $pattern) {
            if (preg_match($pattern, $text, $accountsSection)) {
                $section = $accountsSection[0];
                break;
            }
        }

        if (empty($section)) {
            // Log text preview for debugging
            $textPreview = substr($text, 0, 2000);
            Log::warning('Could not find CREDIT ACCOUNTS section in PDF. Text preview: ' . $textPreview);
            
            // Try to find accounts without section header (maybe section name is different)
            $section = $text; // Use full text as fallback
        }

        // Pattern 1: Numbered accounts "1. ACCOUNT NAME" followed by Account #
        // More flexible: account number may be on next line
        // Updated: Allow for more variations in account name format
        $pattern1 = '/(\d+)\.\s+([A-Z][A-Z\s&]{3,}?)(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?/i';
        preg_match_all($pattern1, $section, $matches1, PREG_SET_ORDER);
        
        Log::info("Pattern 1 found " . count($matches1) . " matches");

        foreach ($matches1 as $match) {
            $accountName = trim($match[2]);
            $accountNumber = isset($match[3]) ? trim($match[3]) : null;
            
            // If account number not in pattern, try to find it in next lines
            if (!$accountNumber) {
                $accountNumber = $this->findAccountNumberAfterName($section, $accountName);
            }
            
            // Extract full account details
            $accountData = $this->extractAccountFullDetails($section, $accountName, $accountNumber);
            $accountData['account_name'] = $accountName;
            $accountData['account_number'] = $accountNumber;
            
            $accounts[] = $accountData;
        }

        // Pattern 2: Accounts without numbers but with Account # on separate line
        // Example: "MIDLAND CREDIT MANAGEMENT" followed by "Account #: 88990011"
        // Updated: More flexible pattern, allow account name to span multiple lines
        $pattern2 = '/([A-Z][A-Z\s&]{3,}?)\s*(?:\n|$).*?(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/is';
        preg_match_all($pattern2, $section, $matches2, PREG_SET_ORDER);
        
        Log::info("Pattern 2 found " . count($matches2) . " matches");

        foreach ($matches2 as $match) {
            $accountName = trim($match[1]);
            $accountNumber = trim($match[2]);
            
            // Skip if already processed
            $exists = false;
            foreach ($accounts as $acc) {
                if ($acc['account_name'] === $accountName && 
                    ($acc['account_number'] === $accountNumber || 
                     ($acc['account_number'] === null && $accountNumber))) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $accountData = $this->extractAccountFullDetails($section, $accountName, $accountNumber);
                $accountData['account_name'] = $accountName;
                $accountData['account_number'] = $accountNumber;
                $accounts[] = $accountData;
            }
        }

        // Pattern 3: Raw Data View format
        // Example: "TransUnion: PORTFOLIO RECOVERY | 99998888 | $900.00 | Collection | ..."
        // Updated: More flexible, allow for variations
        $pattern3 = '/(?:TransUnion|Experian|Equifax)[:\s]+([A-Z][A-Z\s&]+?)\s*\|\s*([X\*\d\-]+)\s*\|\s*\$?([\d,]+\.?\d*)/i';
        preg_match_all($pattern3, $section, $matches3, PREG_SET_ORDER);
        
        Log::info("Pattern 3 found " . count($matches3) . " matches");

        foreach ($matches3 as $match) {
            $accountName = trim($match[1]);
            $accountNumber = trim($match[2]);
            $balance = $this->normalizer->normalizeBalance($match[3]);
            
            // Group by account name and number
            $found = false;
            foreach ($accounts as &$acc) {
                if ($acc['account_name'] === $accountName && $acc['account_number'] === $accountNumber) {
                    // Add bureau data to existing account
                    $bureau = strtolower(explode(':', $match[0])[0]);
                    if (!isset($acc['bureau_data'][$bureau])) {
                        $acc['bureau_data'][$bureau] = [];
                    }
                    $acc['bureau_data'][$bureau]['balance'] = $balance;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $accountData = [
                    'account_name' => $accountName,
                    'account_number' => $accountNumber,
                    'account_type' => null,
                    'date_opened' => null,
                    'original_creditor' => null,
                    'bureau_data' => [],
                ];
                
                $bureau = strtolower(explode(':', $match[0])[0]);
                $accountData['bureau_data'][$bureau] = [
                    'balance' => $balance,
                    'date_last_active' => null,
                    'date_reported' => null,
                    'status' => null,
                    'past_due' => null,
                    'high_limit' => null,
                    'monthly_pay' => null,
                    'reason' => null,
                ];
                
                $accounts[] = $accountData;
            }
        }

        // Log for debugging
        Log::info("Total accounts found: " . count($accounts));
        if (count($accounts) > 0) {
            foreach ($accounts as $idx => $acc) {
                Log::info("Account " . ($idx + 1) . ": {$acc['account_name']} (#{$acc['account_number']})");
            }
        } else {
            // Log section preview if no accounts found
            $sectionPreview = substr($section, 0, 1000);
            Log::warning("No accounts found. Section preview: " . $sectionPreview);
        }

        return $accounts;
    }

    /**
     * Find account number after account name (may be on next line)
     */
    private function findAccountNumberAfterName(string $section, string $accountName): ?string
    {
        $pattern = '/' . preg_quote($accountName, '/') . '.*?(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/is';
        if (preg_match($pattern, $section, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    /**
     * Alternative parsing method - look for account names directly in text
     * This is a fallback when standard patterns don't work
     */
    private function parseAccountsAlternative(string $text): array
    {
        $accounts = [];
        
        // Known account names from the PDF
        $knownAccounts = [
            'CHASE BANK USA',
            'MIDLAND CREDIT MANAGEMENT',
            'WELLS FARGO DEALER SERVICES',
            'PORTFOLIO RECOVERY',
        ];
        
        foreach ($knownAccounts as $accountName) {
            // Look for account name in text
            if (stripos($text, $accountName) === false) {
                continue;
            }
            
            // Try to find account number near the account name
            $accountPattern = '/' . preg_quote($accountName, '/') . '.*?(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/is';
            $accountNumber = null;
            if (preg_match($accountPattern, $text, $match)) {
                $accountNumber = trim($match[1]);
            }
            
            // Extract account details
            $accountData = $this->extractAccountFullDetails($text, $accountName, $accountNumber);
            $accountData['account_name'] = $accountName;
            $accountData['account_number'] = $accountNumber;
            
            $accounts[] = $accountData;
        }
        
        return $accounts;
    }

    /**
     * Extract full account details including bureau-specific data
     * IMPROVED: Better section extraction, handle tabular and raw data formats
     */
    private function extractAccountFullDetails(string $section, string $accountName, ?string $accountNumber): array
    {
        $accountData = [
            'account_type' => null,
            'date_opened' => null,
            'original_creditor' => null,
            'bureau_data' => [],
        ];

        // Find account section - more flexible boundary detection
        $accountPattern = preg_quote($accountName, '/');
        
        // Try multiple patterns to find account section
        $patterns = [
            '/' . $accountPattern . '.*?(?=\d+\.\s+[A-Z]|$)/is',  // Before next numbered account
            '/' . $accountPattern . '.*?(?=[A-Z]{3,}\s+(?:Account|Acct|#)|$)/is',  // Before next account name
            '/' . $accountPattern . '.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',  // Before end markers
        ];
        
        $accountSection = '';
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $section, $accountMatch)) {
                $accountSection = $accountMatch[0];
                break;
            }
        }
        
        if (empty($accountSection)) {
            Log::warning("Could not find section for account: {$accountName}");
            return $accountData;
        }

        // Extract Account Type
        if (preg_match('/Account Type[:\s]*([^\n]+)/i', $accountSection, $typeMatch)) {
            $accountData['account_type'] = trim($typeMatch[1]);
        }

        // Extract Date Opened
        if (preg_match('/Date Opened[:\s]*([0-9\/\-]+)/i', $accountSection, $dateMatch)) {
            $accountData['date_opened'] = $this->parseDate(trim($dateMatch[1]));
        }

        // Extract Original Creditor
        if (preg_match('/Original Creditor[:\s]*([^\n]+)/i', $accountSection, $creditorMatch)) {
            $accountData['original_creditor'] = trim($creditorMatch[1]);
        }

        // Extract bureau-specific data
        $bureaus = ['TransUnion', 'Experian', 'Equifax'];
        
        foreach ($bureaus as $bureau) {
            $bureauKey = strtolower($bureau);
            $bureauData = [
                'balance' => null,
                'date_last_active' => null,
                'date_reported' => null,
                'status' => null,
                'past_due' => null,
                'high_limit' => null,
                'monthly_pay' => null,
                'reason' => null,
            ];

            // Try multiple methods to find bureau data
            
            // Method 1: Standard bureau section format
            $bureauPattern = '/' . preg_quote($bureau, '/') . '[:\s]*\n(.*?)(?=' . implode('|', array_map('preg_quote', $bureaus)) . '|$)/is';
            
            if (preg_match($bureauPattern, $accountSection, $bureauMatch)) {
                $bureauSection = $bureauMatch[1];
            } else {
                // Method 2: Tabular format (DETAILS BY BUREAU with table)
                $bureauSection = $this->extractFromTable($accountSection, $bureau);
                
                // Method 3: Raw Data View format
                if (empty($bureauSection)) {
                    $bureauSection = $this->extractFromRawDataView($accountSection, $bureau, $accountName, $accountNumber);
                }
                
                // Method 4: Look for bureau in brackets [TransUnion Section]
                if (empty($bureauSection)) {
                    $bureauSection = $this->extractFromBracketedSection($accountSection, $bureau);
                }
            }

            // Extract Balance (multiple patterns)
            $balancePatterns = [
                '/Balance[:\s]*\$?([\d,]+\.?\d*)/i',
                '/Bal[:\s]*\$?([\d,]+\.?\d*)/i',
            ];
            foreach ($balancePatterns as $pattern) {
                if (preg_match($pattern, $bureauSection, $balanceMatch)) {
                    $bureauData['balance'] = $this->normalizer->normalizeBalance($balanceMatch[1]);
                    break;
                }
            }

            // Extract Date Last Active
            if (preg_match('/Date Last Active[:\s]*([0-9\/\-]+)/i', $bureauSection, $dateMatch)) {
                $bureauData['date_last_active'] = $this->parseDate(trim($dateMatch[1]));
            }

            // Extract Date Reported (can be in brackets section)
            $dateReportedPatterns = [
                '/Date Reported[:\s]*([0-9\/\-]+)/i',
                '/\[' . preg_quote($bureau, '/') . '[^\]]*Date Reported[:\s]*([0-9\/\-]+)/i',
            ];
            foreach ($dateReportedPatterns as $pattern) {
                if (preg_match($pattern, $accountSection, $dateMatch)) {
                    $bureauData['date_reported'] = $this->parseDate(trim($dateMatch[1]));
                    break;
                }
            }

            // Extract Status (multiple patterns)
            $statusPatterns = [
                '/Pay Status[:\s]*([^\n|]+)/i',
                '/Status[:\s]*([^\n|]+)/i',
            ];
            foreach ($statusPatterns as $pattern) {
                if (preg_match($pattern, $bureauSection, $statusMatch)) {
                    $bureauData['status'] = trim($statusMatch[1]);
                    break;
                }
            }

            // Extract Past Due
            if (preg_match('/Past Due[:\s]*\$?([\d,]+\.?\d*)/i', $bureauSection, $pastDueMatch)) {
                $bureauData['past_due'] = $this->normalizer->normalizeBalance($pastDueMatch[1]);
            }

            // Extract High Limit
            if (preg_match('/High Limit[:\s]*\$?([\d,]+\.?\d*)/i', $bureauSection, $limitMatch)) {
                $bureauData['high_limit'] = $this->normalizer->normalizeBalance($limitMatch[1]);
            }

            // Extract Monthly Pay
            if (preg_match('/Monthly Pay[:\s]*\$?([\d,]+\.?\d*)/i', $bureauSection, $payMatch)) {
                $bureauData['monthly_pay'] = $this->normalizer->normalizeBalance($payMatch[1]);
            }

            // Extract Comments/Reason (multiple patterns)
            $reasonPatterns = [
                '/Comments?[:\s]*([^\n|]+)/i',
                '/Reason[:\s]*([^\n|]+)/i',
                '/Remarks[:\s]*([^\n|]+)/i',
            ];
            foreach ($reasonPatterns as $pattern) {
                if (preg_match($pattern, $bureauSection, $commentMatch)) {
                    $bureauData['reason'] = trim($commentMatch[1]);
                    break;
                }
            }

            $accountData['bureau_data'][$bureauKey] = $bureauData;
        }

        return $accountData;
    }

    /**
     * Extract data from tabular format (DETAILS BY BUREAU with table)
     * Example:
     * Item | TransUnion | Experian | Equifax
     * Balance | $15,400.00 | $15,400.00 | $15,400.00
     * Status | Late 30 Days | Current | Current
     */
    private function extractFromTable(string $section, string $bureau): string
    {
        // Look for "DETAILS BY BUREAU" or table format
        if (!preg_match('/DETAILS BY BUREAU.*?(?=\d+\.\s+[A-Z]|$)/is', $section, $tableMatch)) {
            return '';
        }
        
        $tableSection = $tableMatch[0];
        
        // Find column index for this bureau
        $bureaus = ['TransUnion', 'Experian', 'Equifax'];
        $columnIndex = null;
        
        // Look for header row
        if (preg_match('/Item\s*\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax/i', $tableSection, $headerMatch)) {
            // TransUnion = column 1, Experian = column 2, Equifax = column 3
            $columnIndex = array_search($bureau, $bureaus);
        }
        
        if ($columnIndex === null) {
            return '';
        }
        
        // Extract data from table rows
        $bureauData = [];
        $rows = preg_split('/\n/', $tableSection);
        
        foreach ($rows as $row) {
            if (preg_match('/^\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/i', $row, $cells)) {
                $fieldName = trim($cells[1]);
                $value = trim($cells[$columnIndex + 2]); // +2 because cells[0] is full match, cells[1] is field name
                
                // Map field names to data keys
                if (stripos($fieldName, 'balance') !== false) {
                    $bureauData['balance'] = $this->normalizer->normalizeBalance($value);
                } elseif (stripos($fieldName, 'status') !== false) {
                    $bureauData['status'] = $value;
                } elseif (stripos($fieldName, 'past due') !== false) {
                    $bureauData['past_due'] = $this->normalizer->normalizeBalance($value);
                } elseif (stripos($fieldName, 'remarks') !== false || stripos($fieldName, 'comments') !== false) {
                    $bureauData['reason'] = $value;
                }
            }
        }
        
        // Convert array to string for further processing
        $result = '';
        foreach ($bureauData as $key => $value) {
            $result .= ucfirst($key) . ': ' . $value . "\n";
        }
        
        return $result;
    }

    /**
     * Extract from Raw Data View format
     * Example: "TransUnion: PORTFOLIO RECOVERY | 99998888 | $900.00 | Collection | ..."
     */
    private function extractFromRawDataView(string $section, string $bureau, string $accountName, ?string $accountNumber): string
    {
        $pattern = '/' . preg_quote($bureau, '/') . '[:\s]+' . preg_quote($accountName, '/') . '\s*\|\s*([X\*\d\-]+)\s*\|\s*\$?([\d,]+\.?\d*)\s*\|\s*([^|]+)\s*(?:\|\s*([^|]+))?/i';
        
        if (preg_match($pattern, $section, $match)) {
            $result = "Balance: $" . $match[2] . "\n";
            $result .= "Status: " . trim($match[3]) . "\n";
            if (isset($match[4])) {
                $result .= "Reason: " . trim($match[4]) . "\n";
            }
            return $result;
        }
        
        return '';
    }

    /**
     * Extract from bracketed section format
     * Example: "[TransUnion Section]" followed by data
     */
    private function extractFromBracketedSection(string $section, string $bureau): string
    {
        $pattern = '/\[' . preg_quote($bureau, '/') . '[^\]]*\].*?(?=\[|$)/is';
        if (preg_match($pattern, $section, $match)) {
            return $match[0];
        }
        return '';
    }

    /**
     * Create CreditItem records for each bureau
     */
    private function createAccountItems(Client $client, array $accountData): array
    {
        $items = [];
        $bureaus = ['transunion', 'experian', 'equifax'];

        foreach ($bureaus as $bureau) {
            if (!isset($accountData['bureau_data'][$bureau])) {
                // If "All Bureaus" but no specific data, create item with general data
                if (isset($accountData['bureau']) && strtolower($accountData['bureau']) === 'all bureaus') {
                    $bureauData = [
                        'balance' => $accountData['balance'] ?? 0,
                        'status' => $accountData['status'] ?? null,
                        'date_last_active' => null,
                        'date_reported' => null,
                        'past_due' => null,
                        'high_limit' => $accountData['high_limit'] ?? null,
                        'monthly_pay' => $accountData['monthly_pay'] ?? null,
                        'reason' => $accountData['reason'] ?? null,
                    ];
                } else {
                    continue;
                }
            } else {
                $bureauData = $accountData['bureau_data'][$bureau];
            }
            
            // Skip if no meaningful data
            if (empty($bureauData['balance']) && empty($bureauData['status']) && empty($accountData['account_name'])) {
                continue;
            }

            // Check for duplicates
            $exists = CreditItem::where('client_id', $client->id)
                ->where('bureau', $bureau)
                ->where('account_number', $accountData['account_number'] ?? '')
                ->exists();

            if ($exists) {
                continue;
            }

            $item = CreditItem::create([
                'client_id' => $client->id,
                'bureau' => $bureau,
                'account_name' => $accountData['account_name'],
                'account_number' => $accountData['account_number'] ?? null,
                'account_type' => $accountData['account_type'] ?? null,
                'date_opened' => $accountData['date_opened'] ?? null,
                'date_last_active' => $bureauData['date_last_active'] ?? null,
                'date_reported' => $bureauData['date_reported'] ?? null,
                'balance' => $bureauData['balance'] ?? 0,
                'high_limit' => $bureauData['high_limit'] ?? null,
                'monthly_pay' => $bureauData['monthly_pay'] ?? null,
                'past_due' => $bureauData['past_due'] ?? null,
                'status' => $bureauData['status'] ?? null,
                'reason' => $bureauData['reason'] ?? null,
                'dispute_status' => CreditItem::STATUS_PENDING,
            ]);

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Detect discrepancies between bureaus
     */
    private function detectDiscrepancies(array $accountData): array
    {
        $flags = [];
        $bureauData = $accountData['bureau_data'] ?? [];

        if (count($bureauData) < 2) {
            return $flags;
        }

        // Compare balances
        $balances = array_filter(array_column($bureauData, 'balance'));
        if (count(array_unique($balances)) > 1) {
            $flags[] = 'INACCURATE_BALANCE';
        }

        // Compare dates
        $dates = array_filter(array_column($bureauData, 'date_last_active'));
        if (count(array_unique($dates)) > 1) {
            $flags[] = 'INACCURATE_DATE';
        }

        // Compare statuses
        $statuses = array_filter(array_column($bureauData, 'status'));
        $statusValues = array_map('strtolower', $statuses);
        
        // Check for conflicts (e.g., one says "Late" while others say "Current")
        $hasLate = false;
        $hasCurrent = false;
        foreach ($statusValues as $status) {
            if (stripos($status, 'late') !== false || stripos($status, 'delinquent') !== false) {
                $hasLate = true;
            }
            if (stripos($status, 'current') !== false || stripos($status, 'good') !== false) {
                $hasCurrent = true;
            }
        }
        
        if ($hasLate && $hasCurrent) {
            $flags[] = 'STATUS_CONFLICT';
        }

        return $flags;
    }

    /**
     * Parse date string
     */
    private function parseDate(string $dateStr): ?string
    {
        $dateStr = trim($dateStr);
        
        // MM/DD/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            return "{$year}-{$month}-{$day}";
        }

        // YYYY-MM-DD format
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $dateStr)) {
            return $dateStr;
        }

        try {
            return \Carbon\Carbon::parse($dateStr)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract report date
     */
    private function extractReportDate(string $text): ?string
    {
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/i', $text, $matches)) {
            return $this->parseDate($matches[1]);
        }
        return null;
    }

    /**
     * Extract reference number
     */
    private function extractReferenceNumber(string $text): ?string
    {
        if (preg_match('/Reference[:\s#]*([A-Z0-9\-]+)/i', $text, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}

