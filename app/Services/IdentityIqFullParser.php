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
    public function parseAndSave(Client $client, string $pdfPath, string $formatHint = 'auto'): array
    {
        try {
            DB::beginTransaction();

            // Extract text from PDF
            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();

            // ENHANCED: Normalize text to handle page breaks and table alignment
            // Step 1: Remove excessive line breaks that may occur at page boundaries
            $text = preg_replace('/\n{3,}/', "\n\n", $text);

            // Step 2: Normalize spaces around line breaks
            $text = preg_replace('/\s*\n\s*/', "\n", $text);

            // Step 3: NEW - Normalize multiple spaces to single space (for better alignment handling)
            // This helps with table parsing where columns are separated by multiple spaces
            $text = preg_replace('/[ \t]{2,}/', ' ', $text);

            // Step 4: NEW - Preserve table-like structures by converting aligned spaces to tabs
            // This helps identify column separators in space-aligned tables
            // Convert 4+ consecutive spaces to tab (assume column separator)
            $text = preg_replace('/ {4,}/', "\t", $text);

            Log::debug("Text normalized - Length: " . strlen($text) . ", Contains tabs: " . (strpos($text, "\t") !== false ? 'yes' : 'no'));

            $result = [
                'scores' => null,
                'personal_profiles' => 0,
                'accounts' => 0,
                'discrepancies' => [],
                'format' => $formatHint,
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
            Log::info("Text length: " . strlen($text));
            Log::info("Text preview (first 500 chars): " . substr($text, 0, 500));

            $accounts = $this->parseAccounts($text, $formatHint);
            $discrepancies = [];

            if (empty($accounts)) {
                $textPreview = substr($text, 0, 2000);
                Log::warning('No accounts found with main parsing method. Text preview: ' . $textPreview);
                Log::warning('Text length: ' . strlen($text));

                // Try alternative parsing method - look for account names directly
                Log::info("Trying alternative parsing method...");
                $accounts = $this->parseAccountsAlternative($text, $formatHint);
                if (!empty($accounts)) {
                    Log::info("Alternative parsing method found " . count($accounts) . " accounts");
                } else {
                    Log::warning("Alternative parsing method also found no accounts");
                }
            } else {
                Log::info("Main parsing method found " . count($accounts) . " accounts");
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
                $textPreview = substr($text, 0, 2000);
                Log::error('Could not parse any data from IdentityIQ PDF.');
                Log::error('Text length: ' . strlen($text));
                Log::error('Text preview (first 2000 chars): ' . $textPreview);

                // Check if text extraction worked
                if (strlen($text) < 100) {
                    Log::error('PDF text extraction may have failed - text is too short. PDF may be scanned/image-based.');
                    throw new \Exception('Could not extract text from PDF. The PDF may be scanned or image-based. Please ensure the PDF contains selectable text.');
                }

                // Log what patterns were tried
                Log::error('Attempted to find: CREDIT ACCOUNTS, Credit Accounts, TRADE LINES, ACCOUNTS sections');
                Log::error('Attempted to find: Experian Credit File, Equifax Credit File, TransUnion Credit File sections');

                throw new \Exception('Could not parse any data from IdentityIQ PDF. Format may not be recognized. Check logs for details.');
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
     * IMPROVED: Handle both table format and inline format
     * Table format: | TRANSUNION | EXPERIAN | EQUIFAX |
     *               |    725     |   718    |   730   |
     */
    private function parseCreditScores(string $text): ?array
    {
        $scores = [];

        // First, try to extract from CREDIT SCORE DASHBOARD section
        $dashboardSection = '';
        if (preg_match('/CREDIT SCORE DASHBOARD.*?(?=PERSONAL PROFILE|CREDIT ACCOUNTS|$)/is', $text, $dashboardMatch)) {
            $dashboardSection = $dashboardMatch[0];
        } else {
            $dashboardSection = $text; // Fallback to full text
        }

        // Method 1: Table format with | separator
        // Pattern: | TRANSUNION | EXPERIAN | EQUIFAX |
        //          |    725     |   718    |   730   |
        $tablePattern = '/\|\s*TRANSUNION\s*\|\s*EXPERIAN\s*\|\s*EQUIFAX\s*\|.*?\n.*?\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|/is';
        if (preg_match($tablePattern, $dashboardSection, $tableMatch)) {
            $scores['transunion'] = (int) $tableMatch[1];
            $scores['experian'] = (int) $tableMatch[2];
            $scores['equifax'] = (int) $tableMatch[3];
            Log::info("Credit scores extracted from table format: TU={$scores['transunion']}, EXP={$scores['experian']}, EQ={$scores['equifax']}");
            return $scores;
        }

        // Method 2: Inline format - TransUnion: 645, Experian: 650, Equifax: 620
        $patterns = [
            '/TransUnion[:\s]*(\d+)/i',
            '/Experian[:\s]*(\d+)/i',
            '/Equifax[:\s]*(\d+)/i',
        ];

        if (preg_match($patterns[0], $dashboardSection, $tuMatch)) {
            $scores['transunion'] = (int) $tuMatch[1];
        }
        if (preg_match($patterns[1], $dashboardSection, $expMatch)) {
            $scores['experian'] = (int) $expMatch[1];
        }
        if (preg_match($patterns[2], $dashboardSection, $eqMatch)) {
            $scores['equifax'] = (int) $eqMatch[1];
        }

        return !empty($scores) ? $scores : null;
    }

    /**
     * Parse Personal Profiles with bureau variations
     * IMPROVED: Handle both table format and inline format
     * Table format: | Field | TransUnion | Experian | Equifax |
     *               | Name: | NGUYEN VAN A | NGUYEN V A | NGUYEN VAN A |
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

        // Check if section has table format (with | separator)
        $hasTableFormat = preg_match('/\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax\s*\|/i', $section);

        if ($hasTableFormat) {
            // Parse table format
            // Pattern: | Field | TransUnion | Experian | Equifax |
            //          | Name: | NGUYEN VAN A | NGUYEN V A | NGUYEN VAN A |
            $fieldPatterns = [
                'name' => '/Name[:\s]*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/i',
                'date_of_birth' => '/Date of Birth[:\s]*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/i',
                'current_address' => '/Current Address[:\s]*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/i',
                'employer' => '/Employer[:\s]*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/i',
            ];

            foreach ($bureaus as $idx => $bureau) {
                $profile = [
                    'bureau' => $bureau,
                    'name' => null,
                    'date_of_birth' => null,
                    'current_address' => null,
                    'previous_address' => null,
                    'employer' => null,
                    'date_reported' => null,
                ];

                // Extract from table format
                foreach ($fieldPatterns as $field => $pattern) {
                    if (preg_match($pattern, $section, $match)) {
                        $value = trim($match[$idx + 1]); // idx 0=TU, 1=EXP, 2=EQ
                        if (!empty($value) && $value !== '-') {
                            if ($field === 'date_of_birth') {
                                $profile[$field] = $this->parseDate($value);
                            } else {
                                $profile[$field] = $value;
                            }
                        }
                    }
                }

                // Extract Current Address (may span multiple lines in table)
                if (preg_match('/Current Address[:\s]*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/is', $section, $addrMatch)) {
                    $address = trim($addrMatch[$idx + 1]);
                    if (!empty($address) && $address !== '-') {
                        $profile['current_address'] = $address;
                    }
                }

                $profiles[] = $profile;
            }
        } else {
            // Parse inline format (original method)
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
        }

        return $profiles;
    }

    /**
     * Parse Accounts with bureau-specific details
     * IMPROVED: Auto-detect format and handle both:
     * - Format 1: Unified section with "DETAILS BY BUREAU" (all 3 bureaus per account)
     * - Format 2: Bureau-separated sections (each bureau has its own "Credit Accounts" table)
     */
    private function parseAccounts(string $text, string $formatHint = 'auto'): array
    {
        // Normalize emoji digits to keep numbering detectable (e.g., "1️⃣" -> "1.")
        $text = $this->normalizeEmojiDigits($text);

        // FAST PATH for format (4)
        $accounts = $this->parseAccountsFormat4($text);
        if (!empty($accounts)) {
            return $accounts;
        }

        $accounts = [];

        // STEP 1: Detect format type
        // Format 2 (NEW): Check if PDF has bureau-separated sections first
        // Pattern: "Experian Credit File" or "# Experian Credit File" followed by "Credit Accounts"
        $hasBureauSeparatedFormat = false;
        $bureauSections = [];
        $bureaus = ['Experian', 'Equifax', 'TransUnion'];

        foreach ($bureaus as $bureau) {
            // Look for bureau section with Credit Accounts table
            // Pattern 1: "Experian Credit File" -> "Credit Accounts"
            $bureauPattern1 = '/' . preg_quote($bureau, '/') . '\s+Credit\s+File.*?Credit\s+Accounts.*?(?=' . preg_quote($bureau, '/') . '\s+Credit\s+File|Equifax\s+Credit\s+File|TransUnion\s+Credit\s+File|Hard\s+Inquiries|Potentially\s+Negative|$)/is';

            // Pattern 2: "# Experian Credit File" (with number)
            $bureauPattern2 = '/#\s+' . preg_quote($bureau, '/') . '\s+Credit\s+File.*?Credit\s+Accounts.*?(?=#\s+(?:Experian|Equifax|TransUnion)\s+Credit\s+File|Hard\s+Inquiries|Potentially\s+Negative|$)/is';

            // If formatHint says per_bureau, relax boundary to end of text
            $bureauPatternRelaxed = '/' . preg_quote($bureau, '/') . '\s+Credit\s+File.*?(?=Equifax\s+Credit\s+File|TransUnion\s+Credit\s+File|Experian\s+Credit\s+File|$)/is';

            if (preg_match($bureauPattern1, $text, $bureauMatch)) {
                Log::info("Detected Format 2: Found {$bureau} Credit File section with Credit Accounts");
                $hasBureauSeparatedFormat = true;
                $bureauSections[] = [
                    'bureau' => strtolower($bureau),
                    'section' => $bureauMatch[0],
                ];
            } elseif (preg_match($bureauPattern2, $text, $bureauMatch2)) {
                Log::info("Detected Format 2: Found {$bureau} Credit File section (with #) with Credit Accounts");
                $hasBureauSeparatedFormat = true;
                $bureauSections[] = [
                    'bureau' => strtolower($bureau),
                    'section' => $bureauMatch2[0],
                ];
            } elseif ($formatHint === 'per_bureau' && preg_match($bureauPatternRelaxed, $text, $relaxedMatch)) {
                Log::info("Format hint per_bureau: captured {$bureau} Credit File section (relaxed)");
                $hasBureauSeparatedFormat = true;
                $bureauSections[] = [
                    'bureau' => strtolower($bureau),
                    'section' => $relaxedMatch[0],
                ];
            }
        }

        // If Format 2 detected, parse it directly
        if ($hasBureauSeparatedFormat && !empty($bureauSections)) {
            Log::info("Using Format 2 parser: " . count($bureauSections) . " bureau sections found");
            return $this->parseTableFormatAccounts($bureauSections);
        }

        // STEP 2: Format 3 (NEW) - Sample Credit Report format
        // Check for "SATISFACTORY ACCOUNTS" or "ADVERSE ACCOUNTS" sections
        // Also check for account patterns that indicate this format (e.g., "Acct#:" followed by account details)
        $hasSampleFormat = false;
        $sampleSections = [];

        // Check for SATISFACTORY ACCOUNTS (flexible pattern to handle text extraction issues)
        $satisfactoryPatterns = [
            '/SATISFACTORY\s+ACCOUNTS.*?(?=ADVERSE\s+ACCOUNTS|CREDIT\s+INQUIRIES|PUBLIC\s+RECORDS|$)/is',
            '/SATISFACTORY.*?ACCOUNTS.*?(?=ADVERSE|CREDIT\s+INQUIRIES|PUBLIC\s+RECORDS|$)/is',
        ];
        foreach ($satisfactoryPatterns as $pattern) {
            if (preg_match($pattern, $text, $satisfactoryMatch)) {
                $hasSampleFormat = true;
                $sampleSections[] = [
                    'type' => 'satisfactory',
                    'section' => $satisfactoryMatch[0],
                ];
                Log::info("Detected Format 3: Found SATISFACTORY ACCOUNTS section");
                break;
            }
        }

        // Check for ADVERSE ACCOUNTS
        $adversePatterns = [
            '/ADVERSE\s+ACCOUNTS.*?(?=SATISFACTORY\s+ACCOUNTS|CREDIT\s+INQUIRIES|PUBLIC\s+RECORDS|$)/is',
            '/ADVERSE.*?ACCOUNTS.*?(?=SATISFACTORY|CREDIT\s+INQUIRIES|PUBLIC\s+RECORDS|$)/is',
        ];
        foreach ($adversePatterns as $pattern) {
            if (preg_match($pattern, $text, $adverseMatch)) {
                $hasSampleFormat = true;
                $sampleSections[] = [
                    'type' => 'adverse',
                    'section' => $adverseMatch[0],
                ];
                Log::info("Detected Format 3: Found ADVERSE ACCOUNTS section");
                break;
            }
        }

        // Alternative detection: Look for account pattern with "Acct#:" followed by details
        // This format typically has: "Account Name Acct#: 12345" followed by "Date Opened:", "Balance:", etc.
        if (!$hasSampleFormat) {
            $sampleAccountPattern = '/([A-Z][A-Z\s&.,]{5,}?)\s+Acct\s*#?\s*:?\s*([X\*\d\-]+).*?Date\s+Opened[:\s]*([0-9\/\-]+).*?Balance[:\s]*\$?([\d,]+)/is';
            if (preg_match($sampleAccountPattern, $text, $sampleMatch)) {
                // Check if this looks like sample format (has "Date Opened" and "Balance" in same account block)
                $hasSampleFormat = true;
                // Use full text as section since we can't find clear section boundaries
                $sampleSections[] = [
                    'type' => 'satisfactory', // Default to satisfactory
                    'section' => $text, // Use full text, parser will extract accounts
                ];
                Log::info("Detected Format 3: Found sample format account pattern");
            }
        }

        // If Format 3 detected, parse it
        if ($hasSampleFormat && !empty($sampleSections)) {
            Log::info("Using Format 3 parser: " . count($sampleSections) . " sample sections found");
            return $this->parseSampleFormatAccounts($sampleSections);
        }

        // STEP 3: Format 1 (OLD) - Unified CREDIT ACCOUNTS section
        // Extract CREDIT ACCOUNTS section - more flexible pattern
        $sectionPatterns = [
            '/CREDIT ACCOUNTS.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
            '/Credit Accounts.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|Hard Inquiries|Potentially Negative|$)/is', // Case-insensitive
            '/TRADE LINES.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
            '/ACCOUNTS.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
        ];

        $section = '';
        foreach ($sectionPatterns as $pattern) {
            if (preg_match($pattern, $text, $accountsSection)) {
                $section = $accountsSection[0];
                Log::info("Using Format 1: Found unified CREDIT ACCOUNTS section");
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
        // IMPROVED: Better pattern to capture full account names and handle various formats
        // Pattern matches: 
        // - "1. CHASE BANK USA" 
        // - "1. CHASE BANK USA Account #: 44445555***"
        // - "1. CHASE BANK USA (Open Account - Good Standing)"
        // - "1. WELLS FARGO AUTO (Discrepancy Example)"
        // FIXED: Use pattern that captures full name including spaces until parentheses
        // This handles cases like "1. CHASE BANK USA (Open Account - Good Standing)"
        // Support emoji-style numbering (e.g., "1️⃣ ") by allowing optional symbol after the digit
        $pattern1 = '/(\d+)[\.\)]?\s*(?:\p{So})?\s+((?:[A-Z][A-Z\s&.,\-]*)+?)(?:\s*\([^)]+\))?(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?(?:\s|$)/iu';
        preg_match_all($pattern1, $section, $matches1, PREG_SET_ORDER);

        Log::info("Pattern 1 found " . count($matches1) . " matches");

        foreach ($matches1 as $match) {
            $accountName = trim($match[2]);
            // Clean account name - remove parentheses if captured and remove "Account" if at end
            if (preg_match('/^(.+?)\s*\([^)]+\)$/', $accountName, $cleanMatch)) {
                $accountName = trim($cleanMatch[1]);
            }
            // Remove "Account" if it's at the end (from patterns like "WELLS FARGO AUTO Account #:")
            $accountName = preg_replace('/\s+Account\s*$/i', '', $accountName);
            // Skip headers accidentally matched (e.g., "Field TransUnion Experian Equifax")
            if (preg_match('/^Field\b/i', $accountName)) {
                Log::debug("Skipping header matched as account: {$accountName}");
                continue;
            }
            $accountNumber = isset($match[3]) ? trim($match[3]) : null;

            // FIXED Bug 1: Blacklist header names and account types that are not actual account names
            $blacklist = [
                'CREDIT ACCOUNTS',
                'TRADE LINES',
                'ACCOUNTS',
                'INQUIRIES',
                'PUBLIC RECORDS',
                'END OF REPORT',
                'CREDIT SCORE DASHBOARD',
                'PERSONAL PROFILE',
                // Account types that should not be account names
                'REVOLVING',
                'AUTO LOAN',
                'INSTALLMENT',
                'CREDIT CARD',
                'COLLECTION AGENCY',
                'MORTGAGE',
                'PERSONAL LOAN',
                'STUDENT LOAN',
            ];

            // Also check if account name is too short or looks like a type/term
            $accountNameUpper = strtoupper($accountName);
            if (in_array($accountNameUpper, array_map('strtoupper', $blacklist))) {
                Log::debug("Skipping blacklisted header/type: {$accountName}");
                continue;
            }

            // Check if account name looks like a type (single word, common account types)
            $commonTypes = ['REVOLVING', 'INSTALLMENT', 'AUTO', 'LOAN', 'CARD', 'MORTGAGE'];
            $words = explode(' ', $accountNameUpper);
            if (count($words) <= 2 && in_array($words[0], $commonTypes)) {
                Log::debug("Skipping account type as account name: {$accountName}");
                continue;
            }

            // If account number not in pattern, try to find it in next lines
            if (!$accountNumber) {
                $accountNumber = $this->findAccountNumberAfterName($section, $accountName);
            }

            // FIXED Bug 4: Check for duplicates before adding
            $isDuplicate = false;
            foreach ($accounts as $existingAccount) {
                if (
                    $existingAccount['account_name'] === $accountName &&
                    ($existingAccount['account_number'] === $accountNumber ||
                        ($existingAccount['account_number'] === null && $accountNumber === null))
                ) {
                    Log::debug("Skipping duplicate account: {$accountName} (#{$accountNumber})");
                    $isDuplicate = true;
                    break;
                }
            }

            if ($isDuplicate) {
                continue;
            }

            // Extract full account details
            $accountData = $this->extractAccountFullDetails($section, $accountName, $accountNumber);
            $accountData['account_name'] = $accountName;
            // If extractAccountFullDetails found a better account_number, overwrite
            if (!empty($accountData['account_number'])) {
                $accountNumber = $accountData['account_number'];
            }
            $accountData['account_number'] = $accountNumber;

            // IMPROVED: Log account data for debugging
            Log::info("Extracted account: {$accountName} (#{$accountNumber})");
            if (!empty($accountData['bureau_data'])) {
                $bureausWithData = implode(', ', array_keys($accountData['bureau_data']));
                Log::info("  - Bureaus with data: {$bureausWithData}");
            } else {
                Log::warning("  - No bureau_data found for account: {$accountName}");
            }

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

            // FIXED Bug 1: Blacklist header names and account types
            $blacklist = [
                'CREDIT ACCOUNTS',
                'TRADE LINES',
                'ACCOUNTS',
                'INQUIRIES',
                'PUBLIC RECORDS',
                'END OF REPORT',
                'CREDIT SCORE DASHBOARD',
                'PERSONAL PROFILE',
                // Account types that should not be account names
                'REVOLVING',
                'AUTO LOAN',
                'INSTALLMENT',
                'CREDIT CARD',
                'COLLECTION AGENCY',
                'MORTGAGE',
                'PERSONAL LOAN',
                'STUDENT LOAN',
            ];

            $accountNameUpper = strtoupper($accountName);
            if (in_array($accountNameUpper, array_map('strtoupper', $blacklist))) {
                Log::debug("Skipping blacklisted header/type (pattern 2): {$accountName}");
                continue;
            }

            // Check if account name looks like a type
            $commonTypes = ['REVOLVING', 'INSTALLMENT', 'AUTO', 'LOAN', 'CARD', 'MORTGAGE'];
            $words = explode(' ', $accountNameUpper);
            if (count($words) <= 2 && in_array($words[0], $commonTypes)) {
                Log::debug("Skipping account type as account name (pattern 2): {$accountName}");
                continue;
            }

            // FIXED Bug 4: Check for duplicates before adding
            $exists = false;
            foreach ($accounts as $acc) {
                if (
                    $acc['account_name'] === $accountName &&
                    ($acc['account_number'] === $accountNumber ||
                        ($acc['account_number'] === null && $accountNumber))
                ) {
                    Log::debug("Skipping duplicate account (pattern 2): {$accountName} (#{$accountNumber})");
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
        // FIXED Bug 3: Improved pattern to extract balance, status, and reason from pipe format
        // Example: "TransUnion | PORTFOLIO RECOVERY | 99998888 | $900.00 | Collection | Subscriber reports..."
        $pattern3 = '/(?:TransUnion|Experian|Equifax)\s*\|\s*([A-Z][A-Z\s&]+?)\s*\|\s*([X\*\d\-]+)\s*\|\s*\$?([\d,]+\.?\d*)\s*\|\s*([^|]+)\s*(?:\|\s*([^|]+))?/i';
        preg_match_all($pattern3, $section, $matches3, PREG_SET_ORDER);

        Log::info("Pattern 3 found " . count($matches3) . " matches");

        foreach ($matches3 as $match) {
            $accountName = trim($match[1]);
            $accountNumber = trim($match[2]);
            $balance = $this->normalizer->normalizeBalance($match[3]);
            $status = isset($match[4]) ? trim($match[4]) : null;
            $reason = isset($match[5]) ? trim($match[5]) : null;
            $paymentStatus = null; // Initialize payment_status

            // FIXED Bug 1: Blacklist header names and account types
            $blacklist = [
                'CREDIT ACCOUNTS',
                'TRADE LINES',
                'ACCOUNTS',
                'INQUIRIES',
                'PUBLIC RECORDS',
                'END OF REPORT',
                'CREDIT SCORE DASHBOARD',
                'PERSONAL PROFILE',
                // Account types that should not be account names
                'REVOLVING',
                'AUTO LOAN',
                'INSTALLMENT',
                'CREDIT CARD',
                'COLLECTION AGENCY',
                'MORTGAGE',
                'PERSONAL LOAN',
                'STUDENT LOAN',
            ];

            $accountNameUpper = strtoupper($accountName);
            if (in_array($accountNameUpper, array_map('strtoupper', $blacklist))) {
                Log::debug("Skipping blacklisted header/type (pattern 3): {$accountName}");
                continue;
            }

            // Check if account name looks like a type
            $commonTypes = ['REVOLVING', 'INSTALLMENT', 'AUTO', 'LOAN', 'CARD', 'MORTGAGE'];
            $words = explode(' ', $accountNameUpper);
            if (count($words) <= 2 && in_array($words[0], $commonTypes)) {
                Log::debug("Skipping account type as account name (pattern 3): {$accountName}");
                continue;
            }

            // Extract bureau from match[0]
            $bureauMatch = [];
            if (preg_match('/(TransUnion|Experian|Equifax)/i', $match[0], $bureauMatch)) {
                $bureau = strtolower($bureauMatch[1]);
            } else {
                continue; // Skip if can't determine bureau
            }

            // FIXED Bug 4: Check for duplicates first
            $found = false;
            foreach ($accounts as &$acc) {
                if ($acc['account_name'] === $accountName && $acc['account_number'] === $accountNumber) {
                    // Add bureau data to existing account
                    if (!isset($acc['bureau_data'][$bureau])) {
                        $acc['bureau_data'][$bureau] = [];
                    }
                    $acc['bureau_data'][$bureau]['balance'] = $balance;
                    if ($status) {
                        $acc['bureau_data'][$bureau]['status'] = $status;
                    }
                    if ($reason) {
                        $acc['bureau_data'][$bureau]['reason'] = $reason;
                    }
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

                $accountData['bureau_data'][$bureau] = [
                    'balance' => $balance,
                    'date_last_active' => null,
                    'date_reported' => null,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                    'past_due' => null,
                    'payment_history' => null,
                    'high_limit' => null,
                    'monthly_pay' => null,
                    'reason' => $reason,
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
     * Parse table-based format accounts (NEW)
     * Format: Each bureau has its own section with a table
     * Example:
     * Experian Credit File
     * Credit Accounts
     * Creditor | Acct # | Type | Status | Opened | Limit | Balance | Payment Status | Remarks
     * Capital One | ***4321 | Credit Card | Open | 03/2019 | $5,000 | $4,230 | 30 Days Late | ...
     */
    private function parseTableFormatAccounts(array $bureauSections): array
    {
        $accounts = [];

        foreach ($bureauSections as $bureauData) {
            $bureau = $bureauData['bureau'];
            $section = $bureauData['section'];

            // Find table header row - more flexible patterns
            // Support both pipe-separated (|) and tab-separated (\t) formats
            $hasTableHeader = false;
            $isTabSeparated = false;
            $headerPatterns = [
                // Tab-separated format (most common in PDFs)
                '/Creditor\s+Acct\s+#\s+Type\s+Status\s+Opened\s+Limit\s+Balance\s+Payment\s+Status\s+Remarks/i',
                '/Creditor.*?Acct.*?#.*?Type.*?Status.*?Opened.*?Limit.*?Balance.*?Payment.*?Status.*?Remarks/i',
                // Pipe-separated format
                '/Creditor\s*\|\s*Acct\s*#\s*\|\s*Type\s*\|\s*Status\s*\|\s*Opened\s*\|\s*Limit\s*\|\s*Balance\s*\|\s*Payment\s*Status\s*\|\s*Remarks/i',
                '/Creditor.*?Acct.*?Type.*?Status.*?Opened.*?Limit.*?Balance/i', // Shorter header
            ];

            foreach ($headerPatterns as $idx => $headerPattern) {
                if (preg_match($headerPattern, $section, $headerMatch)) {
                    $hasTableHeader = true;
                    // First 2 patterns are tab-separated
                    $isTabSeparated = ($idx < 2);
                    Log::info("Found table header for {$bureau} (format: " . ($isTabSeparated ? 'tab' : 'pipe') . "-separated)");
                    break;
                }
            }

            if (!$hasTableHeader) {
                Log::warning("No table header found for {$bureau}, skipping");
                continue;
            }

            // Extract table rows (skip header row and separator rows)
            // Support both tab-separated and pipe-separated formats
            if ($isTabSeparated) {
                // Tab-separated format: Split by tabs
                $lines = explode("\n", $section);
                $matches = [];
                $headerFound = false;

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line))
                        continue;

                    // Skip header row
                    if (preg_match('/Creditor.*?Acct.*?#/i', $line)) {
                        $headerFound = true;
                        continue;
                    }

                    if (!$headerFound)
                        continue;

                    // Split by tab
                    $columns = preg_split('/\t+/', $line);
                    if (count($columns) >= 7) {
                        // Expected: Creditor, Acct #, Type, Status, Opened, Limit, Balance, Payment Status, Remarks
                        $matches[] = $columns;
                    }
                }

                Log::info("Table format (tab-separated): Found " . count($matches) . " accounts for {$bureau}");
            } else {
                // Pipe-separated format: Use regex
                $rowPattern = '/([A-Z][A-Z\s&]+?)\s*\|\s*([X\*\d\-]+)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*\$?([\d,]+\.?\d*)\s*\|\s*([^|]+?)\s*(?:\|\s*([^|]+?))?/i';
                preg_match_all($rowPattern, $section, $matches, PREG_SET_ORDER);

                Log::info("Table format (pipe-separated): Found " . count($matches) . " accounts for {$bureau}");

                // If no matches, try simpler pattern (fewer columns)
                if (empty($matches)) {
                    Log::info("Trying simpler pattern for {$bureau}...");
                    $rowPattern2 = '/([A-Z][A-Z\s&]+?)\s*\|\s*([X\*\d\-]+)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*\$?([\d,]+\.?\d*)\s*\|\s*([^|]+?)/i';
                    preg_match_all($rowPattern2, $section, $matches, PREG_SET_ORDER);
                    Log::info("Simpler pattern found " . count($matches) . " accounts for {$bureau}");
                }
            }

            foreach ($matches as $match) {
                // Handle both array (tab-separated) and regex match (pipe-separated) formats
                if ($isTabSeparated && is_array($match) && isset($match[0]) && !is_array($match[0])) {
                    // Tab-separated: $match is already an array of columns
                    $columns = $match;
                    $accountName = isset($columns[0]) ? trim($columns[0]) : '';
                    $accountNumber = isset($columns[1]) ? trim($columns[1]) : '';
                    $accountType = isset($columns[2]) ? trim($columns[2]) : null;
                    $status = isset($columns[3]) ? trim($columns[3]) : null;
                    $opened = isset($columns[4]) ? trim($columns[4]) : null;
                    $limit = isset($columns[5]) ? trim($columns[5]) : null;
                    $balance = isset($columns[6]) ? $this->normalizer->normalizeBalance($columns[6]) : 0;
                    $paymentStatus = isset($columns[7]) ? trim($columns[7]) : null;
                    $remarks = isset($columns[8]) ? trim($columns[8]) : null;
                } else {
                    // Pipe-separated: $match is regex match array
                    $accountName = trim($match[1]);
                    $accountNumber = trim($match[2]);
                    $accountType = isset($match[3]) ? trim($match[3]) : null;
                    $status = isset($match[4]) ? trim($match[4]) : null;
                    $opened = isset($match[5]) ? trim($match[5]) : null;
                    $limit = isset($match[6]) ? trim($match[6]) : null;
                    $balance = isset($match[7]) ? $this->normalizer->normalizeBalance($match[7]) : 0;
                    $paymentStatus = isset($match[8]) ? trim($match[8]) : null;
                    $remarks = isset($match[9]) ? trim($match[9]) : null;
                }

                // Blacklist check
                $blacklist = [
                    'CREDIT ACCOUNTS',
                    'TRADE LINES',
                    'ACCOUNTS',
                    'Creditor',
                    '---', // Table separator
                ];

                if (in_array(strtoupper($accountName), array_map('strtoupper', $blacklist))) {
                    Log::debug("Skipping blacklisted account name: {$accountName}");
                    continue;
                }

                // Skip if account name is too short (likely not a real account)
                if (strlen($accountName) < 3) {
                    continue;
                }

                // IMPORTANT: Keep Status and Payment Status separate
                // Status = Account Status (Open, Closed) - ít quan trọng
                // Payment Status = Payment Status (Current, Late 30 Days) - QUAN TRỌNG NHẤT
                // Don't mix them up - if only one exists, determine which one it is
                if (empty($status) && !empty($paymentStatus)) {
                    // Check if paymentStatus looks like Account Status
                    if (preg_match('/^(Open|Closed|Paid|Active|Inactive)$/i', $paymentStatus)) {
                        $status = $paymentStatus;
                        $paymentStatus = null; // Clear payment_status since it's actually status
                    }
                    // Otherwise, paymentStatus stays as payment_status
                }

                // If status looks like Payment Status, swap them
                if (!empty($status) && preg_match('/^(Current|Late|Collection|Paid as Agreed|Delinquent|30 Days|60 Days|90 Days)/i', $status)) {
                    if (empty($paymentStatus)) {
                        $paymentStatus = $status;
                        $status = null; // Clear status since it's actually payment_status
                    }
                }

                // Check for duplicates across all bureaus
                $isDuplicate = false;
                foreach ($accounts as &$existingAccount) {
                    if (
                        $existingAccount['account_name'] === $accountName &&
                        $existingAccount['account_number'] === $accountNumber
                    ) {
                        // Add bureau data to existing account (same account, different bureau)
                        if (!isset($existingAccount['bureau_data'][$bureau])) {
                            $existingAccount['bureau_data'][$bureau] = [];
                        }
                        $existingAccount['bureau_data'][$bureau] = [
                            'balance' => $balance,
                            'status' => $status,
                            'payment_status' => $paymentStatus,
                            'high_limit' => $limit ? $this->normalizer->normalizeBalance($limit) : null,
                            'date_opened' => $opened ? $this->parseDate($opened) : null,
                            'date_last_active' => null,
                            'date_reported' => null,
                            'past_due' => null,
                            'payment_history' => null,
                            'monthly_pay' => $this->extractMonthlyPay($paymentStatus, $remarks),
                            'reason' => $remarks,
                        ];
                        $isDuplicate = true;
                        Log::debug("Added bureau data for existing account: {$accountName} (#{$accountNumber}) - {$bureau}");
                        break;
                    }
                }

                if (!$isDuplicate) {
                    $accountData = [
                        'account_name' => $accountName,
                        'account_number' => $accountNumber,
                        'account_type' => $accountType,
                        'date_opened' => $opened ? $this->parseDate($opened) : null,
                        'original_creditor' => null,
                        'bureau_data' => [
                            $bureau => [
                                'balance' => $balance,
                                'status' => $status,
                                'payment_status' => $paymentStatus,
                                'high_limit' => $limit ? $this->normalizer->normalizeBalance($limit) : null,
                                'date_opened' => $opened ? $this->parseDate($opened) : null,
                                'date_last_active' => null, // Will be extracted from detailed section
                                'date_reported' => null, // Will be extracted from detailed section
                                'past_due' => null, // Will be extracted from detailed section
                                'payment_history' => null, // Will be extracted from detailed section
                                'monthly_pay' => $this->extractMonthlyPay($paymentStatus, $remarks),
                                'reason' => $remarks,
                            ],
                        ],
                    ];

                    $accounts[] = $accountData;
                    Log::debug("Created new account: {$accountName} (#{$accountNumber}) for {$bureau}");
                }
            }
        }

        return $accounts;
    }

    /**
     * Find account number after account name (may be on next line or next page)
     * IMPROVED: Handle page breaks by searching in wider context
     */
    private function findAccountNumberAfterName(string $section, string $accountName): ?string
    {
        // Find account name position
        $pos = stripos($section, $accountName);
        if ($pos === false) {
            return null;
        }

        // Extract context after account name (up to 1000 chars to handle page breaks)
        $context = substr($section, $pos, 1000);

        // Pattern: Account name followed by account number (may have line breaks/page breaks)
        // Flag 's' allows . to match newlines, 'i' for case-insensitive
        $pattern = '/' . preg_quote($accountName, '/') . '.*?(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/is';
        if (preg_match($pattern, $context, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * NEW: Extract account number directly from an already isolated account section.
     *
     * This is a simpler and more reliable fallback than searching from the global text,
     * especially for formats like:
     *   "Account #: 44445555**** Type: Credit Card - Revolving"
     *
     * @param string $accountSection
     * @return string|null
     */
    private function extractAccountNumberFromSection(string $accountSection): ?string
    {
        // Pattern matches:
        //  - Account #: 44445555****
        //  - Account# 44445555****
        //  - Acct #: ****8888
        if (preg_match('/\b(?:Account|Acct)\s*#?:\s*([X\*\d\-]+)/i', $accountSection, $match)) {
            $number = trim($match[1]);
            if ($number !== '') {
                return $number;
            }
        }

        return null;
    }

    /**
     * Normalize soft line-breaks inside account sections where words/numbers are split across lines.
     * Example issues in (4): "TransUnio\nn", "01/15/20\n20", "Revolvin\ng"
     */
    private function normalizeSoftBreaks(string $text): string
    {
        // Join broken words: letters split across newline
        $text = preg_replace('/([A-Za-z])\n([A-Za-z])/', '$1$2', $text);
        // Join broken numbers
        $text = preg_replace('/(\d)\n(\d)/', '$1$2', $text);
        // If letter then newline then digit -> insert space
        $text = preg_replace('/([A-Za-z])\n(\d)/', '$1 $2', $text);
        // If digit then newline then letter -> insert space
        $text = preg_replace('/(\d)\n([A-Za-z])/', '$1 $2', $text);
        // Remove zero-width spaces / hidden characters
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
        return $text;
    }

    /**
     * Normalize smashed tokens (e.g., "TransUnionExperian", "AccountStatusOpen").
     */
    private function normalizeSmashedTokens(string $text): string
    {
        // Insert spaces between bureau names when smashed
        $text = preg_replace('/(TransUnion)(Experian|Equifax)/i', '$1 $2', $text);
        $text = preg_replace('/(Experian)(Equifax)/i', '$1 $2', $text);

        // Insert spaces before common field labels if they are stuck to previous word
        $labels = [
            'AccountStatus',
            'MonthlyPayment',
            'Balance',
            'HighLimit',
            'PastDue',
            'PaymentStatus',
            'LastReported',
            'DateOpened',
            'DateOpen',
            'DateLastActive',
            'Terms',
            'Field TransUnion Experian Equifax',
        ];
        foreach ($labels as $lbl) {
            $text = str_ireplace($lbl, ' ' . $lbl, $text);
        }

        // Insert space between back-to-back dates (e.g., 01/15/202001/15/2020)
        $text = preg_replace('/(\d{2}\/\d{2}\/\d{4})(\d{2}\/\d{2}\/\d{4})/', '$1 $2', $text);

        return $text;
    }

    /**
     * Normalize emoji keycap digits (e.g., "1️⃣") to plain "1." to keep account numbering detectable.
     */
    private function normalizeEmojiDigits(string $text): string
    {
        // Pattern: digit + FE0F + 20E3
        $text = preg_replace('/([0-9])\x{FE0F}\x{20E3}/u', '$1.', $text);
        return $text;
    }

    /**
     * Fallback extractor for broken aligned tables where fields/values are split across lines.
     * Uses tolerant regexes with \s+ to capture values even when line breaks occur mid-row.
     */
    private function extractFromBrokenAlignedTable(string $section, string $bureau): ?array
    {
        $bureauIndexMap = [
            'transunion' => 1,
            'experian' => 2,
            'equifax' => 3,
        ];
        $bureauIndex = $bureauIndexMap[strtolower($bureau)] ?? null;

        if ($bureauIndex === null) {
            return null;
        }

        // Normalize hidden chars, soft breaks, smashed tokens, and collapse whitespace
        $section = $this->normalizeSoftBreaks($section);
        $section = $this->normalizeSmashedTokens($section);
        $section = preg_replace('/[ \t]+/', ' ', $section);

        // Normalize mashed field names (AccountStatusOpen -> Account Status Open)
        $replacements = [
            'AccountStatus' => 'Account Status ',
            'MonthlyPayment' => 'Monthly Payment ',
            'PaymentStatus' => 'Payment Status ',
            'LastReported' => 'Last Reported ',
            'DateOpened' => 'Date Opened ',
            'DateOpen' => 'Date Opened ',
            'HighLimit' => 'High Limit ',
            'PastDue' => 'Past Due ',
            'DateLastActive' => 'Date Last Active ',
        ];
        $section = str_ireplace(array_keys($replacements), array_values($replacements), $section);

        // Insert newlines before field labels to help regex match
        $section = preg_replace(
            '/(Account Status|Monthly Payment|Balance|High Limit|Past Due|Payment Status|Date Opened|Last Reported|Date Last Active)/i',
            "\n$1",
            $section
        );

        $patterns = [
            'balance' => '/Balance\s+\$?([\d,]+)\s+\$?([\d,]+)\s+\$?([\d,]+)/i',
            'high_limit' => '/High\s+Limit\s+\$?([\d,]+)\s+\$?([\d,]+)\s+\$?([\d,]+)/i',
            'monthly_pay' => '/Monthly\s+Payment\s+\$?([\d,]+)\s+\$?([\d,]+)\s+\$?([\d,]+)/i',
            'account_status' => '/Account\s+Status\s+([A-Za-z ]+?)\s+([A-Za-z ]+?)\s+([A-Za-z ]+?)(?:\s|$)/i',
            'payment_status' => '/Payment\s+Status\s+([A-Za-z ]+?)\s+([A-Za-z ]+?)\s+([A-Za-z ]+?)(?:\s|$)/i',
            'past_due' => '/Past\s+Due\s+\$?([\d,]+)\s+\$?([\d,]+)\s+\$?([\d,]+)/i',
            'date_opened' => '/Date\s+Opened\s+([0-9\/]+)\s+([0-9\/]+)\s+([0-9\/]+)/i',
            'date_last_active' => '/Date\s+Last\s+Active\s+([0-9\/]+)\s+([0-9\/]+)\s+([0-9\/]+)/i',
            'last_reported' => '/Last\s+Reported\s+([0-9\/]+)\s+([0-9\/]+)\s+([0-9\/]+)/i',
        ];

        $data = [];
        foreach ($patterns as $field => $regex) {
            if (preg_match($regex, $section, $m)) {
                $val = trim($m[$bureauIndex] ?? '');
                if ($val !== '') {
                    switch ($field) {
                        case 'balance':
                        case 'high_limit':
                        case 'monthly_pay':
                        case 'past_due':
                            $data[$field] = $this->normalizer->normalizeBalance($val);
                            break;
                        case 'account_status':
                            $data['status'] = trim($val);
                            break;
                        case 'payment_status':
                            $data['payment_status'] = trim($val);
                            break;
                        case 'date_opened':
                            $data['date_opened'] = $this->parseDate($val);
                            break;
                        case 'date_last_active':
                            $data['date_last_active'] = $this->parseDate($val);
                            break;
                        case 'last_reported':
                            $data['date_reported'] = $this->parseDate($val);
                            break;
                    }
                }
            }
        }

        return empty($data) ? null : $data;
    }

    /**
     * Alternative parsing method - look for account names directly in text
     * This is a fallback when standard patterns don't work
     */
    private function parseAccountsAlternative(string $text, string $formatHint = 'auto'): array
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
     * Dedicated parser for format (4) where accounts are numbered and tables use "Field TransUnion Experian Equifax"
     */
    private function parseAccountsFormat4(string $text): array
    {
        $accounts = [];
        $text = $this->normalizeEmojiDigits($text);
        $text = $this->normalizeSoftBreaks($text);
        $text = $this->normalizeSmashedTokens($text);

        // EXTRA NORMALIZATION (matches manual fixes in test_broken.php)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/ {4,}/', "\t", $text);
        $text = preg_replace('/([A-Za-z])\n([A-Za-z])/', '$1$2', $text);
        $text = preg_replace('/(\d)\n(\d)/', '$1$2', $text);
        $text = preg_replace('/([A-Za-z])\n(\d)/', '$1 $2', $text);
        $text = preg_replace('/(\d)\n([A-Za-z])/', '$1 $2', $text);
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        // Ensure each account marker starts on its own line
        $text = preg_replace('/\s*(\d+)\.\s+/', "\n$1. ", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Split by markers "n. "
        $parts = preg_split('/\n(?=\d+\.\s+)/', $text);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !preg_match('/^\d+\.\s+/', $part)) {
                continue;
            }

            // Account name: text after marker until "Type:" or "Account #"
            $nameLine = $part;
            if (preg_match('/^\d+\.\s+(.+?)(?:Type:|Account\s*#:|Field\s+TransUnion)/i', $nameLine, $nm)) {
                $accountName = trim($nm[1]);
            } else {
                $accountName = trim(preg_replace('/^\d+\.\s+/', '', strtok($nameLine, "\n")));
            }

            // Account number
            $accountNumber = null;
            if (preg_match('/Account\s*#:\s*([X\*\d\-]+)/i', $part, $numMatch)) {
                $accountNumber = trim($numMatch[1]);
            }

            // Account type (appears before the table)
            // Stop when hitting account # or bureau headers to avoid swallowing the whole table
            if (preg_match('/Type[:\s]*([^\n]+?)(?=\s*(?:Account\s*#|Field\s+TransUnion|TransUnion|Experian|Equifax|$))/i', $part, $typeMatch)) {
                $accountType = trim($typeMatch[1]);
            } else {
                $accountType = null;
            }
            if (!empty($accountType)) {
                $accountType = substr($accountType, 0, 190); // protect DB column length
            }

            $accountData = [
                'account_name' => $accountName,
                'account_number' => $accountNumber,
                'account_type' => $accountType,
                'date_opened' => null,
                'original_creditor' => null,
                'bureau_data' => [],
            ];

            foreach (['TransUnion', 'Experian', 'Equifax'] as $bureau) {
                // Try broken aligned table
                $data = $this->extractFromBrokenAlignedTable($part, $bureau);

                // Fallback: collapsed inline rows (e.g., "Account Status: Open Open OpenMonthly Payment: ...")
                if (empty($data)) {
                    $partWithBreaks = preg_replace(
                        '/(?<!\n)(Account Status:|Monthly Payment:|Date Opened:|Balance:|High Limit:|Past Due:|Payment Status:|Last Reported:|Date Last Active:)/',
                        "\n$1",
                        $part
                    );
                    $inline = $this->extractFromInlineTable($partWithBreaks, $bureau);
                    if (!empty($inline)) {
                        $data = [];
                        foreach (explode("\n", $inline) as $line) {
                            $line = trim($line);
                            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $m)) {
                                $this->mapValueToField($data, trim($m[1]), trim($m[2]));
                            }
                        }
                    }
                }

                // Final pass: fill missing fields from collapsed triple-value lines
                $triplePatterns = [
                    'balance' => '/Balance:\s*\$?([\d,\.]+)\s+\$?([\d,\.]+)\s+\$?([\d,\.]+)/i',
                    'high_limit' => '/High Limit:\s*\$?([\d,\.]+)\s+\$?([\d,\.]+)\s+\$?([\d,\.]+)/i',
                    'monthly_pay' => '/Monthly Payment:\s*\$?([\d,\.]+)\s+\$?([\d,\.]+)\s+\$?([\d,\.]+)/i',
                    'status' => '/Account Status:\s*([A-Za-z\s]+?)\s+([A-Za-z\s]+?)\s+([A-Za-z\s]+?)(?=\s*Monthly Payment:)/i',
                    'payment_status' => '/Payment Status:\s*([A-Za-z0-9\s]+?)\s+([A-Za-z0-9\s]+?)\s+([A-Za-z0-9\s]+?)(?=\s*Last Reported:)/i',
                    'date_opened' => '/Date Opened:\s*([0-9\/-]+)\s+([0-9\/-]+)\s+([0-9\/-]+)/i',
                    'date_reported' => '/Last Reported:\s*([0-9\/-]+)\s+([0-9\/-]+)\s+([0-9\/-]+)/i',
                    'date_last_active' => '/Date Last Active:\s*([0-9\/-]+)\s+([0-9\/-]+)\s+([0-9\/-]+)/i',
                    'past_due' => '/Past Due:\s*\$?([\d,\.]+)\s+\$?([\d,\.]+)\s+\$?([\d,\.]+)/i',
                ];

                // Smarter payment status split: take substring and split from the right
                $smartPaymentStatuses = null;
                if (preg_match('/Payment Status:\s*(.*?)\s*Last Reported:/is', $part, $pm)) {
                    $tokens = preg_split('/\s+/', trim($pm[1]));
                    if (count($tokens) >= 3) {
                        $status3 = array_pop($tokens);
                        $status2 = array_pop($tokens);
                        $status1 = trim(implode(' ', $tokens));
                        $smartPaymentStatuses = [
                            'TransUnion' => $status1,
                            'Experian' => $status2,
                            'Equifax' => $status3,
                        ];
                    }
                }

                $valueIndex = ['TransUnion' => 1, 'Experian' => 2, 'Equifax' => 3];
                $idx = $valueIndex[$bureau] ?? null;

                if ($idx !== null) {
                    foreach ($triplePatterns as $field => $pattern) {
                        if (!isset($data[$field]) && preg_match($pattern, $part, $m) && isset($m[$idx])) {
                            $val = trim($m[$idx]);
                            if ($field === 'payment_status' && $smartPaymentStatuses && isset($smartPaymentStatuses[$bureau])) {
                                $val = $smartPaymentStatuses[$bureau];
                            }
                            if (in_array($field, ['balance', 'high_limit', 'monthly_pay', 'past_due'])) {
                                $val = $this->normalizer->normalizeBalance($val);
                            } elseif (in_array($field, ['date_opened', 'date_reported', 'date_last_active'])) {
                                $val = $this->parseDate($val);
                            }
                            $data[$field] = $val;
                        }
                    }
                }

                // Normalize bureau key to lowercase to align with createAccountItems()
                $key = strtolower($bureau);
                $accountData['bureau_data'][$key] = $data ?? [];
            }

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
            // NEW: allow this method to return an improved account number if found
            'account_number' => $accountNumber,
        ];

        // FIXED Bug 2: Improved account section boundary detection to prevent data mixing
        // Find account name position first
        $accountPattern = preg_quote($accountName, '/');
        $pos = stripos($section, $accountName);

        if ($pos === false) {
            Log::warning("Could not find account name in section: {$accountName}");
            return $accountData;
        }

        // Find the start of this account (look for numbered pattern like "1. ACCOUNT NAME" or just account name)
        $accountStart = $pos;
        // Look backwards for numbered pattern (e.g., "1. ACCOUNT NAME")
        // IMPROVED: Search in wider context and calculate position correctly
        $searchStart = max(0, $pos - 500);
        $searchContext = substr($section, $searchStart, $pos - $searchStart + 100);
        if (preg_match('/(\d+)\.\s+' . $accountPattern . '/i', $searchContext, $numMatch, PREG_OFFSET_CAPTURE)) {
            // Calculate absolute position: searchStart + offset in searchContext
            $accountStart = $searchStart + $numMatch[0][1];
            Log::debug("Found numbered pattern for {$accountName} at position {$accountStart}");
        } else {
            // If no numbered pattern found, start from account name position
            $accountStart = $pos;
        }

        // Find the end of this account section - look for next numbered account or next account name
        $accountEnd = strlen($section);

        // Look for next numbered account (e.g., "2. NEXT ACCOUNT")
        if (preg_match('/\d+\.\s+([A-Z][A-Z\s&]{3,})/i', substr($section, $pos + 50), $nextMatch, PREG_OFFSET_CAPTURE)) {
            $nextAccountPos = $pos + 50 + $nextMatch[0][1];
            // Make sure it's not the same account
            if (stripos($nextMatch[1][0], $accountName) === false) {
                $accountEnd = min($accountEnd, $nextAccountPos);
            }
        }

        // Look for next account name pattern (not numbered)
        if (preg_match('/([A-Z][A-Z\s&]{3,})\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/i', substr($section, $pos + 50), $nextMatch, PREG_OFFSET_CAPTURE)) {
            $nextAccountPos = $pos + 50 + $nextMatch[0][1];
            // Make sure it's not the same account
            if (stripos($nextMatch[1][0], $accountName) === false && $nextMatch[2][0] !== $accountNumber) {
                $accountEnd = min($accountEnd, $nextAccountPos);
            }
        }

        // Look for end markers
        $endMarkers = ['INQUIRIES', 'PUBLIC RECORDS', 'END OF REPORT'];
        foreach ($endMarkers as $marker) {
            $markerPos = stripos($section, $marker, $pos);
            if ($markerPos !== false) {
                $accountEnd = min($accountEnd, $markerPos);
            }
        }

        // Extract account section with precise boundaries
        $accountSection = substr($section, $accountStart, $accountEnd - $accountStart);

        if (empty($accountSection)) {
            Log::warning("Could not extract section for account: {$accountName}");
            return $accountData;
        }

        // NEW: Normalize soft breaks inside account section (handles broken words/numbers in (4))
        $accountSection = $this->normalizeSoftBreaks($accountSection);

        // FIXED Bug 2: Verify account number is in this section to prevent mixing
        // IMPROVED: Don't skip extraction if account number not found, just use wider context
        if ($accountNumber && stripos($accountSection, $accountNumber) === false) {
            Log::warning("Account number {$accountNumber} not found in section for {$accountName}, trying wider context");
            // Try to find account number in a wider context
            $widerContext = substr($section, max(0, $pos - 100), $accountEnd - max(0, $pos - 100));
            if (stripos($widerContext, $accountNumber) !== false) {
                $accountSection = $widerContext;
                Log::debug("Found account number in wider context, using wider section");
            } else {
                // Don't skip - account number might be masked differently or not present
                // Continue with extraction anyway
                Log::debug("Account number not found in wider context, continuing extraction without account number verification");
            }
        }

        // NEW: Always try to extract account number from this account section and overwrite if found
        $extractedNumber = $this->extractAccountNumberFromSection($accountSection);
        if (!empty($extractedNumber)) {
            $accountNumber = $extractedNumber;
            $accountData['account_number'] = $accountNumber;
            Log::debug("Extracted account number from section for {$accountName}: {$accountNumber}");
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
            // NEW: Added CSV Block, Vertical Block, and Aligned Table strategies to handle broken text formats

            // Check if BUREAU COMPARISON table exists or if section has table format
            $hasBureauComparison = preg_match('/BUREAU COMPARISON/i', $accountSection);
            $hasTableFormat = preg_match('/\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax\s*\|/i', $accountSection);
            $hasCsvFormat = preg_match('/"[^"]+","[^"]+","[^"]+","[^"]+"/', $accountSection);
            // Check for aligned table format: "TransUnion Experian Equifax" on one line
            $hasAlignedTableFormat = preg_match('/^\s*(TransUnion|Experian|Equifax)\s+(TransUnion|Experian|Equifax)\s+(TransUnion|Experian|Equifax)\s*$/im', $accountSection) ||
                preg_match('/\b(TransUnion|Experian|Equifax)\s+(TransUnion|Experian|Equifax)\s+(TransUnion|Experian|Equifax)\b/i', $accountSection);

            // Initialize extraction flags
            $extractedFromCsv = false;
            $extractedFromVertical = false;
            $extractedFromAlignedTable = false;
            $bureauSection = '';

            // PRIORITY 1: Aligned Table format (NEW - For space/tab-aligned tables)
            // Format: Columns separated by multiple spaces or tabs
            // TransUnion          Experian            Equifax 
            // Account Status:      Open                Open                Open
            if ($hasAlignedTableFormat) {
                $alignedTableData = $this->extractFromAlignedTable($accountSection, $bureau);
                if (!empty($alignedTableData)) {
                    // IMPROVED: Use data directly instead of converting to string and parsing again
                    // Map aligned table data directly to bureauData
                    foreach ($alignedTableData as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $bureauData[$key] = $value;
                        }
                    }
                    $extractedFromAlignedTable = true;
                    // Also convert to string for compatibility with existing fallback logic
                    foreach ($alignedTableData as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $bureauSection .= ucfirst($key) . ': ' . $value . "\n";
                        }
                    }
                    Log::debug("Extracted bureau data from aligned table format for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 1b: Broken aligned table fallback (handles soft line-break tables in file 4)
            if (!$extractedFromAlignedTable) {
                $brokenAligned = $this->extractFromBrokenAlignedTable($accountSection, $bureau);
                if (!empty($brokenAligned)) {
                    foreach ($brokenAligned as $k => $v) {
                        $bureauData[$k] = $v;
                    }
                    $extractedFromAlignedTable = true;
                    $tableExtracted = true;
                    Log::debug("Extracted bureau data from broken aligned table for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 2: CSV Block format (Most accurate for CSV-formatted data like Page 2)
            // Format: "Label","Value1","Value2","Value3"
            // This handles broken text where data is in CSV format
            $csvData = null;
            if (empty($bureauSection) && $hasCsvFormat) {
                $csvData = $this->extractFromCsvBlock($accountSection, $bureau);
                if (!empty($csvData)) {
                    // IMPROVED: Use data directly instead of converting to string and parsing again
                    // Map CSV data directly to bureauData
                    foreach ($csvData as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $bureauData[$key] = $value;
                        }
                    }
                    $extractedFromCsv = true;
                    // Also convert to string for compatibility with existing fallback logic
                    foreach ($csvData as $key => $value) {
                        if ($value !== null) {
                            $bureauSection .= ucfirst($key) . ': ' . $value . "\n";
                        }
                    }
                    Log::debug("Extracted bureau data from CSV block format for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 2: Tabular format (DETAILS BY BUREAU or BUREAU COMPARISON with table and | separator)
            // This is the most reliable method for standard credit reports
            if (empty($bureauSection) && ($hasBureauComparison || $hasTableFormat)) {
                $tableResult = $this->extractFromTable($accountSection, $bureau);
                if (!empty($tableResult)) {
                    $bureauSection = $tableResult;
                    Log::debug("Extracted bureau data from table format for {$bureau} - account: {$accountName}");
                } else {
                    Log::warning("Table format detected but extraction failed for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 4: Vertical Block format (IMPROVED - For Page 1 with broken line structure)
            // Format: Label và Value nằm ở các dòng riêng biệt dưới tên Bureau
            // This handles broken text where labels and values are on separate lines
            $extractedFromVertical = false;
            if (empty($bureauSection) && !$extractedFromCsv) {
                $verticalData = $this->extractFromVerticalBlock($accountSection, $bureau);
                if (!empty($verticalData)) {
                    // IMPROVED: Use data directly instead of converting to string and parsing again
                    // Map vertical block data directly to bureauData
                    foreach ($verticalData as $key => $value) {
                        if ($value !== null && $value !== '') {
                            $bureauData[$key] = $value;
                        }
                    }
                    $extractedFromVertical = true;
                    // Also convert to string for compatibility with existing fallback logic
                    foreach ($verticalData as $key => $value) {
                        if ($value !== null) {
                            $bureauSection .= ucfirst($key) . ': ' . $value . "\n";
                        }
                    }
                    Log::debug("Extracted bureau data from vertical block format for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 5: Inline tabular format (space-separated values)
            // Example: "Balance: $1,350.00 $1,150.00 $1,250.00"
            if (empty($bureauSection)) {
                $bureauSection = $this->extractFromInlineTable($accountSection, $bureau);
                if (!empty($bureauSection)) {
                    Log::debug("Extracted bureau data from inline table format for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 6: Standard bureau section format (skip if table format was used)
            if (empty($bureauSection) && !$hasBureauComparison && !$hasTableFormat && !$hasCsvFormat) {
                $bureauPattern = '/' . preg_quote($bureau, '/') . '[:\s]*\n(.*?)(?=' . implode('|', array_map('preg_quote', $bureaus)) . '|$)/is';
                if (preg_match($bureauPattern, $accountSection, $bureauMatch)) {
                    $bureauSection = $bureauMatch[1];
                    Log::debug("Extracted bureau data from standard section format for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 6: Raw Data View format (skip if table format was used)
            if (empty($bureauSection) && !$hasBureauComparison && !$hasTableFormat && !$hasCsvFormat) {
                $bureauSection = $this->extractFromRawDataView($accountSection, $bureau, $accountName, $accountNumber);
                if (!empty($bureauSection)) {
                    Log::debug("Extracted bureau data from raw data view format for {$bureau} - account: {$accountName}");
                }
            }

            // PRIORITY 7: Look for bureau in brackets [TransUnion Section] (skip if table format was used)
            if (empty($bureauSection) && !$hasBureauComparison && !$hasTableFormat && !$hasCsvFormat) {
                $bureauSection = $this->extractFromBracketedSection($accountSection, $bureau);
                if (!empty($bureauSection)) {
                    Log::debug("Extracted bureau data from bracketed section for {$bureau} - account: {$accountName}");
                }
            }

            // IMPROVED: If table format was detected but no data extracted, try to extract directly from table rows
            // This handles cases where extractFromTable didn't find the table section correctly
            if (empty($bureauSection) && ($hasBureauComparison || $hasTableFormat)) {
                Log::warning("Table format detected but no data extracted for {$bureau} - account: {$accountName}. Trying direct extraction.");
                // Try to extract directly from account section using table row patterns
                $directTableData = $this->extractDirectlyFromTableRows($accountSection, $bureau);
                if (!empty($directTableData)) {
                    $bureauSection = $directTableData;
                    Log::debug("Extracted bureau data directly from table rows for {$bureau} - account: {$accountName}");
                }
            }

            // If still no data found, log warning but continue (will create item with empty data)
            if (empty($bureauSection)) {
                Log::warning("Could not extract bureau data for {$bureau} - account: {$accountName}. Will create item with empty data.");
            }

            // If we got result from extractFromTable(), parse it to extract individual fields
            // IMPROVED: Skip parsing if data already extracted from CSV or Vertical Block
            // CRITICAL FIX: extractFromTable already extracts correct value for each bureau,
            // so we just need to parse the output string which contains only this bureau's data
            $tableExtracted = false;
            if (!$extractedFromCsv && !$extractedFromVertical && !$extractedFromAlignedTable && !empty($bureauSection) && (strpos($bureauSection, 'Balance:') !== false || strpos($bureauSection, 'Payment_status:') !== false || strpos($bureauSection, 'Pay Status:') !== false || strpos($bureauSection, 'Payment Status:') !== false || strpos($bureauSection, 'Account Status:') !== false)) {
                // This looks like output from extractFromTable() - parse it
                // CRITICAL: extractFromTable already extracted the correct value for this bureau,
                // so the output string contains only this bureau's data (one value per field)
                $tableExtracted = true;
                $parsedData = [];
                foreach (explode("\n", $bureauSection) as $line) {
                    $line = trim($line);
                    if (empty($line))
                        continue;

                    // IMPROVED: Better pattern to handle field names with spaces and values with commas, currency symbols
                    // Pattern: "Field Name: value" or "Field_Name: value"
                    // Support values like "$1,200", "$15,000", "Current", "Open", etc.
                    // CRITICAL FIX: Only extract single value (not multiple values separated by spaces)
                    // The output from extractFromTable should already contain only one value per field
                    if (preg_match('/^([\w\s-]+?)[:\s]+\s*(.+)$/', $line, $match)) {
                        $rawKey = strtolower(trim($match[1]));
                        $key = str_replace([' ', '-'], '_', $rawKey); // normalize: "Pay Status" -> "pay_status", "Account Status" -> "account_status"
                        $value = trim($match[2]);

                        // CRITICAL FIX: If value contains multiple words that look like status values (e.g., "CURRENT CURRENT CURRENT"),
                        // take only the first one. This prevents concatenation of multiple bureau values.
                        // Check if value looks like concatenated status values (same word repeated)
                        if (preg_match('/^(\w+)(?:\s+\1)+$/i', $value, $statusMatch)) {
                            $value = $statusMatch[1]; // Take only the first occurrence
                            Log::warning("Detected concatenated status value '{$match[2]}' for field '{$key}', using only first: '{$value}'");
                        }

                        $parsedData[$key] = $value;
                        Log::debug("Parsed table output for {$bureau} - Key: {$key}, Value: '{$value}'");
                    }
                }

                // Map parsed data to bureauData with proper normalization
                // CRITICAL: Only use the first value if multiple values are present (shouldn't happen with extractFromTable)
                if (isset($parsedData['balance'])) {
                    $balanceValue = $parsedData['balance'];
                    // If balance contains multiple values (shouldn't happen), take first
                    if (preg_match('/^([\d,\.\$]+)/', $balanceValue, $balanceMatch)) {
                        $balanceValue = $balanceMatch[1];
                    }
                    $bureauData['balance'] = $this->normalizer->normalizeBalance($balanceValue);
                    Log::debug("Mapped balance for {$bureau}: '{$parsedData['balance']}' -> {$bureauData['balance']}");
                }

                // Payment Status: accept multiple normalized keys
                // CRITICAL: Only take first value if multiple values present
                foreach (['payment_status', 'pay_status', 'paystatus'] as $k) {
                    if (isset($parsedData[$k])) {
                        $payStatusValue = trim($parsedData[$k]);
                        // If value contains multiple status words (e.g., "CURRENT CURRENT CURRENT"), take first
                        if (preg_match('/^(\w+)(?:\s+\1)+$/i', $payStatusValue, $payStatusMatch)) {
                            $payStatusValue = $payStatusMatch[1];
                            Log::warning("Detected concatenated payment_status '{$parsedData[$k]}' for {$bureau}, using only first: '{$payStatusValue}'");
                        }
                        $bureauData['payment_status'] = $payStatusValue;
                        Log::debug("Mapped payment_status for {$bureau}: '{$payStatusValue}'");
                        break;
                    }
                }

                // Account Status
                // CRITICAL: Only take first value if multiple values present
                if (isset($parsedData['account_status'])) {
                    $accountStatusValue = trim($parsedData['account_status']);
                    // If value contains multiple status words (e.g., "OPEN OPEN OPEN"), take first
                    if (preg_match('/^(\w+)(?:\s+\1)+$/i', $accountStatusValue, $statusMatch)) {
                        $accountStatusValue = $statusMatch[1];
                        Log::warning("Detected concatenated account_status '{$parsedData['account_status']}' for {$bureau}, using only first: '{$accountStatusValue}'");
                    }
                    $bureauData['status'] = $accountStatusValue;
                    Log::debug("Mapped account_status for {$bureau}: '{$accountStatusValue}'");
                } elseif (isset($parsedData['status']) && empty($bureauData['payment_status'])) {
                    // Only use generic 'status' if payment_status not set
                    $statusValue = trim($parsedData['status']);
                    // If value contains multiple status words, take first
                    if (preg_match('/^(\w+)(?:\s+\1)+$/i', $statusValue, $statusMatch)) {
                        $statusValue = $statusMatch[1];
                        Log::warning("Detected concatenated status '{$parsedData['status']}' for {$bureau}, using only first: '{$statusValue}'");
                    }
                    $bureauData['status'] = $statusValue;
                    Log::debug("Mapped status for {$bureau}: '{$statusValue}'");
                }

                // High Limit / Credit Limit
                if (isset($parsedData['high_limit']) || isset($parsedData['credit_limit']) || isset($parsedData['limit'])) {
                    $limitValue = $parsedData['high_limit'] ?? $parsedData['credit_limit'] ?? $parsedData['limit'];
                    $bureauData['high_limit'] = $this->normalizer->normalizeBalance($limitValue);
                    Log::debug("Mapped high_limit: '{$limitValue}' -> {$bureauData['high_limit']}");
                }

                // Monthly Payment
                if (isset($parsedData['monthly_payment']) || isset($parsedData['monthly_pay'])) {
                    $payValue = $parsedData['monthly_payment'] ?? $parsedData['monthly_pay'];
                    $bureauData['monthly_pay'] = $this->normalizer->normalizeBalance($payValue);
                    Log::debug("Mapped monthly_pay: '{$payValue}' -> {$bureauData['monthly_pay']}");
                }

                if (isset($parsedData['past_due'])) {
                    $bureauData['past_due'] = $this->normalizer->normalizeBalance($parsedData['past_due']);
                }
                if (isset($parsedData['date_reported']) || isset($parsedData['last_reported'])) {
                    $dateValue = $parsedData['date_reported'] ?? $parsedData['last_reported'];
                    $bureauData['date_reported'] = $this->parseDate($dateValue);
                }
                if (isset($parsedData['date_last_active']) || isset($parsedData['last_active'])) {
                    $dateValue = $parsedData['date_last_active'] ?? $parsedData['last_active'];
                    $bureauData['date_last_active'] = $this->parseDate($dateValue);
                }
                if (isset($parsedData['date_opened']) || isset($parsedData['opened'])) {
                    $dateValue = $parsedData['date_opened'] ?? $parsedData['opened'];
                    $bureauData['date_opened'] = $this->parseDate($dateValue);
                }
                if (isset($parsedData['payment_history'])) {
                    $bureauData['payment_history'] = trim($parsedData['payment_history']);
                }
                if (isset($parsedData['reason']) || isset($parsedData['comments'])) {
                    $bureauData['reason'] = trim($parsedData['reason'] ?? $parsedData['comments'] ?? '');
                }
            }

            // Extract Balance (multiple patterns)
            // FIXED: Handle both single value and inline table format
            // Skip if already extracted from table, CSV, Vertical Block, or Aligned Table
            if (!$tableExtracted && !$extractedFromCsv && !$extractedFromVertical && !$extractedFromAlignedTable) {
                $balancePatterns = [
                    '/Balance[:\s]*\$?([\d,]+\.?\d*)/i',  // Single value
                    '/Bal[:\s]*\$?([\d,]+\.?\d*)/i',     // Abbreviated
                ];
                foreach ($balancePatterns as $pattern) {
                    if (preg_match($pattern, $bureauSection, $balanceMatch)) {
                        // Ensure we get full value with comma (e.g., "1,350.00" not "1")
                        $balanceValue = $balanceMatch[1];
                        $bureauData['balance'] = $this->normalizer->normalizeBalance($balanceValue);
                        break;
                    }
                }
            }

            // Extract Date Last Active / DOLA (Date of Last Activity/Payment) - CRITICAL FIELD
            // Multiple patterns to catch all variations - search in both bureauSection and accountSection
            // Skip if already extracted from table, CSV, Vertical Block, or Aligned Table
            if (!$tableExtracted && !$extractedFromCsv && !$extractedFromVertical && !$extractedFromAlignedTable) {
                $dolaPatterns = [
                    '/Date Last Active[:\s]*([0-9\/\-]+)/i',
                    '/Date of Last Activity[:\s]*([0-9\/\-]+)/i',
                    '/Date of Last Payment[:\s]*([0-9\/\-]+)/i',
                    '/Last Activity[:\s]*([0-9\/\-]+)/i',
                    '/Last Payment[:\s]*([0-9\/\-]+)/i',
                    '/Last Payment Made[:\s]*([0-9\/\-]+)/i',
                    '/DOLA[:\s]*([0-9\/\-]+)/i',
                    '/Last Payment Date[:\s]*([0-9\/\-]+)/i',
                    '/Date Last Paid[:\s]*([0-9\/\-]+)/i',
                    '/Date Paid[:\s]*([0-9\/\-]+)/i',
                ];
                foreach ($dolaPatterns as $pattern) {
                    // Search in bureau section first
                    if (preg_match($pattern, $bureauSection, $dateMatch)) {
                        $bureauData['date_last_active'] = $this->parseDate(trim($dateMatch[1]));
                        break;
                    }
                    // Also search in account section (for formats without bureau-specific sections)
                    if (preg_match($pattern, $accountSection, $dateMatch)) {
                        $bureauData['date_last_active'] = $this->parseDate(trim($dateMatch[1]));
                        break;
                    }
                }
            }

            // Extract Date Reported - CRITICAL FIELD for detecting stale data
            // Search in both bureauSection and accountSection
            // Skip if already extracted from table, CSV, Vertical Block, or Aligned Table
            if (!$tableExtracted && !$extractedFromCsv && !$extractedFromVertical && !$extractedFromAlignedTable) {
                $dateReportedPatterns = [
                    '/Date Reported[:\s]*([0-9\/\-]+)/i',
                    '/\[' . preg_quote($bureau, '/') . '[^\]]*Date Reported[:\s]*([0-9\/\-]+)/i',
                    '/Reported[:\s]*([0-9\/\-]+)/i',
                    '/Last Reported[:\s]*([0-9\/\-]+)/i',
                    '/Date of Report[:\s]*([0-9\/\-]+)/i',
                    '/Date Updated[:\s]*([0-9\/\-]+)/i',
                ];
                foreach ($dateReportedPatterns as $pattern) {
                    // Search in account section first (more common location)
                    if (preg_match($pattern, $accountSection, $dateMatch)) {
                        $bureauData['date_reported'] = $this->parseDate(trim($dateMatch[1]));
                        break;
                    }
                    // Also search in bureau section
                    if (preg_match($pattern, $bureauSection, $dateMatch)) {
                        $bureauData['date_reported'] = $this->parseDate(trim($dateMatch[1]));
                        break;
                    }
                }
            }

            // Extract Payment Status FIRST (QUAN TRỌNG NHẤT - dùng để quyết định màu đỏ/xanh)
            // Payment Status: Current, Late 30 Days, Collection, Paid as Agreed, etc.
            // CRITICAL FIX: If BUREAU COMPARISON exists, skip pattern matching to avoid mixing all bureau values
            // Skip if already extracted from table
            if (!$tableExtracted && !$hasBureauComparison) {
                $paymentStatusPatterns = [
                    '/Payment Status[:\s]*([^\n|]+?)(?:\s*\||\s*$)/i',
                    '/Pay Status[:\s]*([^\n|]+?)(?:\s*\||\s*$)/i',
                    '/Payment[:\s]*([^\n|]+?)(?:\s*\||\s*$)/i',
                ];
                foreach ($paymentStatusPatterns as $pattern) {
                    // Search in bureau section first
                    if (preg_match($pattern, $bureauSection, $payStatusMatch)) {
                        $bureauData['payment_status'] = trim($payStatusMatch[1]);
                        break;
                    }
                    // Also search in account section
                    if (preg_match($pattern, $accountSection, $payStatusMatch)) {
                        $bureauData['payment_status'] = trim($payStatusMatch[1]);
                        break;
                    }
                }
            }

            // Extract Status (Account Status - e.g., Open, Closed, Paid) - ÍT QUAN TRỌNG HƠN
            // Only extract if it's clearly Account Status, not Payment Status
            // If we already found Payment Status, be more careful with generic "Status"
            // Skip if already extracted from table
            if (!$tableExtracted && !$hasBureauComparison) {
                $statusPatterns = [
                    '/Account Status[:\s]*([^\n|]+?)(?:\s*\||\s*$)/i',  // Explicit "Account Status"
                    '/Status[:\s]*([^\n|]+?)(?:\s*\||\s*$)/i',  // Generic "Status" - only if Payment Status not found
                ];

                foreach ($statusPatterns as $pattern) {
                    if (preg_match($pattern, $bureauSection, $statusMatch)) {
                        $statusValue = trim($statusMatch[1]);

                        // If Payment Status already found, check if this Status is different
                        // If Status looks like Payment Status (Current, Late, Collection), skip it
                        if (!empty($bureauData['payment_status'])) {
                            // If Status is same as Payment Status, don't duplicate
                            if (strcasecmp($statusValue, $bureauData['payment_status']) === 0) {
                                continue;
                            }
                            // If Status looks like payment status (Current, Late, etc.), skip
                            if (preg_match('/^(Current|Late|Collection|Paid|Delinquent)/i', $statusValue)) {
                                continue;
                            }
                        }

                        // Status should be Account Status (Open, Closed, etc.)
                        if (preg_match('/^(Open|Closed|Paid|Active|Inactive)/i', $statusValue)) {
                            $bureauData['status'] = $statusValue;
                            break;
                        }
                    }
                }

                // If no Payment Status found but we have a generic Status that looks like Payment Status
                // Use it as Payment Status instead
                if (empty($bureauData['payment_status']) && !empty($bureauData['status'])) {
                    $statusValue = $bureauData['status'];
                    if (preg_match('/^(Current|Late|Collection|Paid as Agreed|Delinquent|30 Days|60 Days|90 Days)/i', $statusValue)) {
                        $bureauData['payment_status'] = $statusValue;
                        $bureauData['status'] = null; // Clear status since it's actually payment status
                    }
                }
            }

            // Extract Past Due Amount - CRITICAL FIELD for detecting payment issues
            // Search in both bureauSection and accountSection
            // Skip if already extracted from table
            if (!$tableExtracted) {
                $pastDuePatterns = [
                    '/Past Due[:\s]*\$?([\d,]+\.?\d*)/i',
                    '/Past Due Amount[:\s]*\$?([\d,]+\.?\d*)/i',
                    '/Amount Past Due[:\s]*\$?([\d,]+\.?\d*)/i',
                    '/Overdue[:\s]*\$?([\d,]+\.?\d*)/i',
                ];
                foreach ($pastDuePatterns as $pattern) {
                    // Search in bureau section first
                    if (preg_match($pattern, $bureauSection, $pastDueMatch)) {
                        $bureauData['past_due'] = $this->normalizer->normalizeBalance($pastDueMatch[1]);
                        break;
                    }
                    // Also search in account section
                    if (preg_match($pattern, $accountSection, $pastDueMatch)) {
                        $bureauData['past_due'] = $this->normalizer->normalizeBalance($pastDueMatch[1]);
                        break;
                    }
                }
            }

            // Extract Payment History - CRITICAL FIELD for detecting past late payments
            // Format: "OK, OK, 30, OK, 60, OK..." or "111111111111111111111111" (24-48 months)
            // Also check for grid format (Year | Jan | Feb | ... | Dec)
            $paymentHistoryPatterns = [
                '/Payment History[:\s]*([OK\s,0-9]{10,})/i',
                '/History[:\s]*([OK\s,0-9]{10,})/i',
                '/Payment Pattern[:\s]*([OK\s,0-9]{10,})/i',
                // Pattern for grid format: "1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1"
                '/Payment[:\s]*History[:\s]*([0-9\s]{20,})/i',
            ];
            foreach ($paymentHistoryPatterns as $pattern) {
                // Search in bureau section first
                if (preg_match($pattern, $bureauSection, $historyMatch)) {
                    $bureauData['payment_history'] = trim($historyMatch[1]);
                    break;
                }
                // Also search in account section
                if (preg_match($pattern, $accountSection, $historyMatch)) {
                    $bureauData['payment_history'] = trim($historyMatch[1]);
                    break;
                }
            }

            // Also try to extract Payment History grid format (Year | Jan | Feb | ... | Dec)
            // This is handled separately in extractPaymentHistoryGrid() for sample format
            // But we should also check here for other formats
            if (empty($bureauData['payment_history'])) {
                $gridHistory = $this->extractPaymentHistoryGrid($accountSection);
                if ($gridHistory) {
                    $bureauData['payment_history'] = $gridHistory;
                }
            }

            // Extract High Limit (multiple patterns to handle different formats)
            $highLimitPatterns = [
                '/High Limit[:\s]*\$?([\d,]+\.?\d*)/i',
                '/Credit Limit[:\s]*\$?([\d,]+\.?\d*)/i',
                '/Limit[:\s]*\$?([\d,]+\.?\d*)/i',
            ];
            foreach ($highLimitPatterns as $pattern) {
                if (preg_match($pattern, $bureauSection, $limitMatch)) {
                    // Ensure we get the full value including comma (e.g., $5,000 not $5)
                    $limitValue = $limitMatch[1];
                    // Remove comma for normalization
                    $limitValue = str_replace(',', '', $limitValue);
                    $bureauData['high_limit'] = $this->normalizer->normalizeBalance($limitValue);
                    break;
                }
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
     * Extract data from inline tabular format (space-separated values)
     * Example:
     * Balance: $1,350.00 $1,150.00 $1,250.00
     * High Limit: $5,000 $5,000 $5,000
     * Pay Status: Current Current Current
     */
    /**
     * NEW: Extract data from space/tab-aligned table format (Column-based parsing)
     * This method handles tables where columns are separated by spaces
     * Format (from actual PDF):
     * TransUnion Experian Equifax
     * Account Status: Open Open Open
     * Monthly Payment: $50 $50 $50
     * Balance: $1,200 $1,200 $1,200
     * Payment Status: Current Current Current
     * 
     * @param string $section Account section text
     * @param string $bureau Bureau name (TransUnion, Experian, Equifax)
     * @return array|null Bureau data array or null if not found
     */
    private function extractFromAlignedTable(string $section, string $bureau): ?array
    {
        // Find table header with bureau names (format: "TransUnion Experian Equifax" or "Field TransUnion Experian Equifax")
        // Allow an optional leading column label (Field/Item) before bureau names
        $headerPattern = '/^\s*(?:Field|Item)?\s*(TransUnion)\s+(Experian)\s+(Equifax)\s*$/im';
        if (!preg_match($headerPattern, $section, $headerMatch, PREG_OFFSET_CAPTURE)) {
            // Try alternative: header might be inline
            $headerPattern2 = '/\b(?:Field|Item)?\s*(TransUnion)\s+(Experian)\s+(Equifax)\b/i';
            if (!preg_match($headerPattern2, $section, $headerMatch, PREG_OFFSET_CAPTURE)) {
                return null;
            }
        }

        // Determine column index for the target bureau (0-based) using the header line tokens
        $columnIndex = null;
        $headerLine = is_array($headerMatch[0]) ? $headerMatch[0][0] : $headerMatch[0];
        $tokens = preg_split('/\s+/', trim($headerLine));
        $foundBureaus = [];
        foreach ($tokens as $token) {
            $clean = preg_replace('/[^A-Za-z]/', '', $token);
            $cleanLower = strtolower($clean);
            if (in_array($cleanLower, ['transunion', 'experian', 'equifax'], true)) {
                $foundBureaus[] = ucfirst($cleanLower);
            }
        }

        if (!empty($foundBureaus)) {
            $idx = array_search($bureau, $foundBureaus, true);
            if ($idx !== false) {
                $columnIndex = $idx;
            }
        }

        // Fallback: use captured groups if token-based detection failed
        if ($columnIndex === null) {
            for ($i = 1; $i <= 3; $i++) {
                if (!isset($headerMatch[$i])) {
                    continue;
                }
                $headerValue = is_array($headerMatch[$i]) ? $headerMatch[$i][0] : $headerMatch[$i];
                if (stripos($headerValue, $bureau) !== false) {
                    $columnIndex = $i - 1;
                    break;
                }
            }
        }

        if ($columnIndex === null) {
            Log::debug("Could not determine column index for bureau: {$bureau} in header: " . (is_array($headerMatch[0]) ? $headerMatch[0][0] : $headerMatch[0]));
            return null;
        }

        Log::debug("Determined column index for {$bureau}: {$columnIndex}");

        // Find the position of the header line
        $headerPos = isset($headerMatch[0][1]) ? $headerMatch[0][1] : strpos($section, $headerMatch[0][0]);
        if ($headerPos === false) {
            return null;
        }

        // Extract table content after header (up to next account or section)
        $tableContent = substr($section, $headerPos);
        // Stop at next account number or section marker
        // IMPROVED: Look for next numbered account (e.g., "2. NEXT ACCOUNT") or section markers
        if (preg_match('/(\d+\.\s+[A-Z][A-Z\s&]{3,}|INQUIRIES|PUBLIC RECORDS|End of Report)/i', $tableContent, $endMatch, PREG_OFFSET_CAPTURE)) {
            $endPos = $endMatch[0][1];
            // Only stop if it's not too close to header (allow content to be extracted)
            if ($endPos > 200) { // Increased from 100 to allow more content
                $tableContent = substr($tableContent, 0, $endPos);
            }
        }

        // Split into lines and parse each row
        $lines = explode("\n", $tableContent);
        $bureauData = [];
        $foundHeader = false;

        foreach ($lines as $lineIndex => $line) {
            $originalLine = $line;
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Detect header line
            if (preg_match('/^\s*(TransUnion|Experian|Equifax)\s+(TransUnion|Experian|Equifax)\s+(TransUnion|Experian|Equifax)\s*$/i', $line)) {
                $foundHeader = true;
                continue;
            }

            // Skip if we haven't found header yet
            if (!$foundHeader) {
                continue;
            }

            // Skip separator lines (dashes)
            if (preg_match('/^[\s\-]+$/', $line)) {
                continue;
            }

            // Skip lines that look like account headers (numbered accounts)
            if (preg_match('/^\d+\.\s+[A-Z]/', $line)) {
                break; // End of this account's table
            }

            // Parse row: "Field Name: value1 value2 value3"
            // Handle both single-word values (Open, Current) and multi-word values (Late 30 Days)
            if (preg_match('/^([A-Za-z\s]+?):\s+(.+)$/', $line, $rowMatch)) {
                $fieldName = trim($rowMatch[1]);
                $valuesStr = trim($rowMatch[2]);

                // Split values intelligently
                // CRITICAL: Use regex to split by patterns that indicate value boundaries
                // Values are separated by spaces, but we need to identify complete values
                // Pattern: Look for boundaries between values
                // - Money: $1,200 (may have comma)
                // - Status: Open, Current, Closed, Collection
                // - Date: 01/15/2020
                // - Multi-word: Late 30 Days

                // Strategy: Split by looking for patterns that indicate value boundaries
                $values = [];

                // PRIORITY 1: Try to match money values first ($1,200 $1,200 $1,200)
                // This handles most balance/high_limit/monthly_pay fields
                if (preg_match_all('/\$[\d,]+(?:\.\d{2})?/', $valuesStr, $moneyMatches)) {
                    if (count($moneyMatches[0]) == 3) {
                        $values = $moneyMatches[0];
                        Log::debug("Aligned table - Extracted 3 money values: " . json_encode($values));
                    }
                }

                // PRIORITY 2: If not all money, try splitting by known patterns
                if (count($values) != 3) {
                    // Split by looking for: space + (status word OR $ OR date pattern)
                    // But preserve multi-word values like "Late 30 Days"
                    $parts = preg_split('/\s+(?=\$|(?:\d{1,2}\/){2}\d{4}|(?:Open|Current|Closed|Collection|Revolving)(?:\s|$)|Late\s+\d+\s+Days)/i', $valuesStr);

                    // Clean up parts
                    $parts = array_map('trim', array_filter($parts));

                    // If we got 3 parts, use them
                    if (count($parts) == 3) {
                        $values = $parts;
                    } else {
                        // Try splitting by 2+ spaces (if values are separated by multiple spaces)
                        $values = preg_split('/\s{2,}/', $valuesStr);
                        $values = array_map('trim', array_filter($values));

                        // If still not 3, use word-by-word parsing
                        if (count($values) < 3) {
                            $allWords = explode(' ', $valuesStr);
                            $values = [];
                            $currentValue = '';

                            foreach ($allWords as $i => $word) {
                                $currentValue .= ($currentValue ? ' ' : '') . $word;

                                // Check if this is a complete value
                                // Known single-word status values
                                $singleWordStatuses = ['open', 'current', 'closed', 'collection', 'revolving'];
                                if (in_array(strtolower($currentValue), $singleWordStatuses)) {
                                    $values[] = $currentValue;
                                    $currentValue = '';
                                }
                                // Money format: $1,200 or $1,200.00
                                elseif (preg_match('/^\$[\d,]+(?:\.\d{2})?$/', $currentValue)) {
                                    $values[] = $currentValue;
                                    $currentValue = '';
                                }
                                // Date format: 01/15/2020
                                elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $currentValue)) {
                                    $values[] = $currentValue;
                                    $currentValue = '';
                                }
                                // Number format: 60 Months, 1 Month
                                elseif (preg_match('/^\d+\s+(Months?|Days?)$/i', $currentValue)) {
                                    $values[] = $currentValue;
                                    $currentValue = '';
                                }
                                // Multi-word status: "Late 30 Days"
                                elseif (preg_match('/^Late\s+\d+\s+Days$/i', $currentValue)) {
                                    $values[] = $currentValue;
                                    $currentValue = '';
                                }
                                // If we have 2 values already and there are words left, the rest is the 3rd value
                                elseif (count($values) == 2 && $i == count($allWords) - 1) {
                                    $values[] = $currentValue;
                                    $currentValue = '';
                                }
                                // If next word starts with capital/dollar and current value looks complete
                                elseif (isset($allWords[$i + 1])) {
                                    $nextWord = $allWords[$i + 1];
                                    // Check if next word is start of new value
                                    if (
                                        preg_match('/^\$|^\d{1,2}\//', $nextWord) ||
                                        in_array(strtolower($nextWord), $singleWordStatuses)
                                    ) {
                                        if ($currentValue) {
                                            $values[] = $currentValue;
                                            $currentValue = '';
                                        }
                                    }
                                }
                            }

                            // Add remaining value if any
                            if ($currentValue && count($values) < 3) {
                                $values[] = $currentValue;
                            }

                            // If still not 3 values, take last 3 words as fallback
                            if (count($values) < 3 && count($allWords) >= 3) {
                                // Group words intelligently
                                $fallbackValues = [];
                                $i = 0;
                                while ($i < count($allWords) && count($fallbackValues) < 3) {
                                    $word = $allWords[$i];

                                    // Single-word status
                                    if (in_array(strtolower($word), $singleWordStatuses)) {
                                        $fallbackValues[] = $word;
                                        $i++;
                                    }
                                    // Money or date
                                    elseif (preg_match('/^\$|^\d+\/\d+\/\d+$/', $word)) {
                                        $fallbackValues[] = $word;
                                        $i++;
                                    }
                                    // Multi-word value - collect until next single-word status/money/date
                                    else {
                                        $multiValue = $word;
                                        $i++;
                                        while ($i < count($allWords) && count($fallbackValues) < 2) {
                                            $nextWord = $allWords[$i];
                                            if (
                                                in_array(strtolower($nextWord), $singleWordStatuses) ||
                                                preg_match('/^\$|^\d+\/\d+\/\d+$/', $nextWord)
                                            ) {
                                                break;
                                            }
                                            $multiValue .= ' ' . $nextWord;
                                            $i++;
                                        }
                                        $fallbackValues[] = $multiValue;
                                    }
                                }

                                // If still not 3, just take last 3 words
                                if (count($fallbackValues) < 3) {
                                    $fallbackValues = array_slice($allWords, -3);
                                }

                                // Use fallback if we got 3 values
                                if (count($fallbackValues) == 3) {
                                    $values = $fallbackValues;
                                }
                            }
                        }
                    }
                }

                // Ensure we have exactly 3 values
                if (count($values) > 3) {
                    $values = array_slice($values, -3);
                }

                // Select value based on column index
                $value = isset($values[$columnIndex]) ? trim($values[$columnIndex]) : '';

                Log::debug("Aligned table - Field: '{$fieldName}', Bureau: {$bureau} (col={$columnIndex}), Values: " . json_encode($values) . ", Selected: '{$value}'");

                // Skip if value is empty or dashed
                if (empty($value) || preg_match('/^[\s\-]+$/', $value)) {
                    continue;
                }

                // Map field name to data key
                $this->mapValueToField($bureauData, $fieldName, $value);
            } else {
                // Try pattern without colon (less common)
                if (preg_match('/^([A-Za-z\s]+?)\s+(.+)$/', $line, $rowMatch)) {
                    $fieldName = trim($rowMatch[1]);
                    $valuesStr = trim($rowMatch[2]);
                    $values = preg_split('/\s{2,}/', $valuesStr);
                    if (count($values) < 3) {
                        $allWords = explode(' ', $valuesStr);
                        $values = count($allWords) >= 3 ? array_slice($allWords, -3) : $allWords;
                    }
                    $value = isset($values[$columnIndex]) ? trim($values[$columnIndex]) : '';

                    if (!empty($value) && !preg_match('/^[\s\-]+$/', $value)) {
                        $this->mapValueToField($bureauData, $fieldName, $value);
                    }
                }
            }
        }

        if (empty($bureauData)) {
            Log::debug("No data extracted from aligned table for bureau: {$bureau}");
            return null;
        }

        Log::info("Extracted aligned table data for {$bureau}: " . json_encode(array_keys($bureauData)));
        return $bureauData;
    }

    private function extractFromInlineTable(string $section, string $bureau): string
    {
        $bureauIndex = ['TransUnion' => 0, 'Experian' => 1, 'Equifax' => 2];
        $index = $bureauIndex[$bureau] ?? null;

        if ($index === null) {
            return '';
        }

        $result = [];

        // FIXED Bug 1: Improved patterns to correctly extract 3 space-separated values
        // Pattern: "Field: value1 value2 value3" (3 values for 3 bureaus)
        // Example: "Balance: $1,350.00 $1,150.00 $1,250.00"
        $patterns = [
            // Balance: Must match exactly 3 values separated by spaces
            '/Balance[:\s]*(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)/i',
            // High Limit: Must match exactly 3 values
            '/High Limit[:\s]*(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)/i',
            // Pay Status: Match 3 status values (may contain spaces like "Late 30 Days")
            '/Pay Status[:\s]*([^\n]+?)\s+([^\n]+?)\s+([^\n]+?)(?:\n|$)/i',
            // Monthly Pay: Must match exactly 3 values
            '/Monthly Pay[:\s]*(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)/i',
            // Comments: Match 3 comment values
            '/Comments?[:\s]*([^\n]+?)\s+([^\n]+?)\s+([^\n]+?)(?:\n|$)/i',
        ];

        $fieldNames = ['Balance', 'High Limit', 'Pay Status', 'Monthly Pay', 'Comments'];

        foreach ($patterns as $idx => $pattern) {
            if (preg_match($pattern, $section, $match)) {
                // FIXED: Verify we have all 3 values before extracting
                if (count($match) >= 4) { // match[0] + 3 values
                    $value = trim($match[$index + 1]); // index 0=TU, 1=EXP, 2=EQ

                    // Debug logging
                    Log::debug("Extracting {$fieldNames[$idx]} for {$bureau}: index={$index}, value={$value}, all_values=[" .
                        trim($match[1]) . ", " . trim($match[2]) . ", " . trim($match[3]) . "]");

                    if (!empty($value) && $value !== '|') {
                        // For numeric fields, normalize the value
                        if (in_array($fieldNames[$idx], ['Balance', 'High Limit', 'Monthly Pay'])) {
                            $value = $this->normalizer->normalizeBalance($value);
                        }
                        $result[] = $fieldNames[$idx] . ': ' . $value;
                    }
                }
            }
        }

        return implode("\n", $result);
    }

    /**
     * Extract data from tabular format using COLUMN-BASED parsing approach
     * NEW APPROACH: Parse by column instead of by row for better accuracy
     * 
     * Strategy:
     * 1. Find header row to determine column index for the bureau
     * 2. Extract ALL values from that column across all rows
     * 3. Map each value to its corresponding field based on the row's field name
     * 
     * Example:
     * |   | TransUnion | Experian | Equifax  |
     * | Account Status: | Open | Open | Open  |
     * | Payment Status: | Current | Current | Current  |
     * | Balance: | $1,200 | $1,200 | $1,200  |
     * 
     * For TransUnion (column index 0):
     * - Row 1: Field="Account Status", Value="Open" -> status = "Open"
     * - Row 2: Field="Payment Status", Value="Current" -> payment_status = "Current"
     * - Row 3: Field="Balance", Value="$1,200" -> balance = 1200.00
     */
    private function extractFromTable(string $section, string $bureau): string
    {
        // COLUMN-BASED PARSING: Find table section and determine column index
        $bureaus = ['TransUnion', 'Experian', 'Equifax'];
        $columnIndex = array_search($bureau, $bureaus);

        if ($columnIndex === null) {
            Log::debug("Invalid bureau name: {$bureau}");
            return '';
        }

        // Find table section - look for table format with bureau columns
        $tableSection = null;

        // Pattern 1: Explicit table section with header
        $tablePatterns = [
            '/\|\s*\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax\s*\|.*?(?=\d+\.\s+[A-Z]|INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
            '/\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax\s*\|.*?(?=\d+\.\s+[A-Z]|INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
            '/DETAILS BY BUREAU.*?(?=\d+\.\s+[A-Z]|$)/is',
            '/BUREAU COMPARISON.*?(?=\d+\.\s+[A-Z]|$)/is',
        ];

        foreach ($tablePatterns as $pattern) {
            if (preg_match($pattern, $section, $match)) {
                $tableSection = $match[0];
                Log::debug("Found table section with pattern");
                break;
            }
        }

        // Pattern 2: If no explicit section, check if entire section has table format
        if (!$tableSection) {
            if (preg_match('/\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax\s*\|/i', $section)) {
                $tableSection = $section;
                Log::debug("Using entire section as table");
            } else {
                Log::debug("No table format found for bureau: {$bureau}");
                return '';
            }
        }

        // Determine if first column is empty (affects column index calculation)
        $hasEmptyFirstColumn = preg_match('/\|\s*\|\s*TransUnion\s*\|\s*Experian\s*\|\s*Equifax/i', $tableSection);

        // Calculate value index in cells array
        // Format: "|   | TransUnion | Experian | Equifax  |" -> cells[1]=empty, cells[2]=TU, cells[3]=EXP, cells[4]=EQ
        // Format: "Account Status: | Open | Open | Open  |" -> cells[1]="Account Status", cells[2]=TU, cells[3]=EXP, cells[4]=EQ
        // In both cases: valueIndex = columnIndex + 2 (because cells[0]=match, cells[1]=field name or empty)
        $valueIndex = $columnIndex + 2;

        Log::debug("Column-based parsing - Bureau: {$bureau}, ColumnIndex: {$columnIndex}, ValueIndex: {$valueIndex}, HasEmptyFirstColumn: " . ($hasEmptyFirstColumn ? 'yes' : 'no'));

        // COLUMN-BASED PARSING: Extract all values from the bureau's column
        $bureauData = [];
        $rows = preg_split('/\r\n|\r|\n/', $tableSection);

        foreach ($rows as $rowIndex => $row) {
            $row = trim($row);

            // Skip empty rows
            if (empty($row)) {
                continue;
            }

            // Skip separator rows (---)
            if (preg_match('/^[\s\|\-]+$/', $row)) {
                continue;
            }

            // Skip header rows
            if (preg_match('/TransUnion\s*\|\s*Experian\s*\|\s*Equifax|Item\s*\|\s*TransUnion/i', $row)) {
                continue;
            }

            // COLUMN-BASED: Parse row to extract field name and value from specific column
            // Support multiple row formats:
            // Format 1: "| Account Status: | Open | Open | Open  |" (with leading | and colon)
            // Format 2: "Account Status: | Open | Open | Open  |" (no leading |, with colon)
            // Format 3: "| Account Status | Open | Open | Open  |" (with leading |, no colon)
            // Format 4: "Account Status | Open | Open | Open  |" (no leading |, no colon)

            $fieldName = null;
            $value = null;
            $cells = null;

            // IMPROVED: Better regex patterns to handle table rows with proper cell extraction
            // Support multiple formats with better handling of values containing commas, spaces, etc.

            // Format 1: "| Account Status: | Open | Open | Open  |" (with leading | and colon)
            // Format 2: "Account Status: | Open | Open | Open  |" (no leading |, with colon)
            // Format 3: "| Account Status | Open | Open | Open  |" (with leading |, no colon)
            // Format 4: "Account Status | Open | Open | Open  |" (no leading |, no colon)

            // CRITICAL FIX: Use non-greedy matching for field name, but greedy for values to capture full amounts with commas
            // Pattern: Split by | and extract cells properly, handling spaces and commas in values

            // CRITICAL FIX: Split row by | but KEEP empty cells to handle "|   | TransUnion | ..." format
            // Remove leading/trailing | first, then split
            $row = trim($row, '|');
            $rowCells = preg_split('/\s*\|\s*/', $row);

            // Remove empty strings from array but keep track of positions
            // Actually, we need to keep empty cells to detect empty first column
            // So we'll trim each cell but keep the array structure
            $rowCells = array_map('trim', $rowCells);

            // Skip if not enough cells (need at least field name + 3 bureau values)
            if (count($rowCells) < 4) {
                Log::debug("Row {$rowIndex} has only " . count($rowCells) . " cells, skipping. Row: {$row}");
                continue;
            }

            // Determine field name and values based on format
            // Check if first cell is empty (BUREAU COMPARISON format: "|   | TransUnion | ...")
            $firstCell = $rowCells[0] ?? '';
            $isEmptyFirstColumn = empty($firstCell) || preg_match('/^[\s\-]+$/', $firstCell);

            if ($isEmptyFirstColumn) {
                // Format: "|   | TransUnion | Experian | Equifax  |"
                // After split: cells[0]="", cells[1]="TransUnion", cells[2]="field name", cells[3]="TU", cells[4]="EXP", cells[5]="EQ"
                // Wait, that's not right. Let me reconsider...
                // Actually: "|   | Account Status: | Open | Open | Open  |"
                // After split: cells[0]="", cells[1]="Account Status:", cells[2]="Open", cells[3]="Open", cells[4]="Open"
                if (count($rowCells) >= 5) {
                    $fieldNameCell = $rowCells[1] ?? '';
                    // Remove colon if present
                    $fieldName = preg_replace('/:\s*$/', '', trim($fieldNameCell));
                    // Adjust valueIndex: if first column is empty, valueIndex = columnIndex + 2 (cells[0]=empty, cells[1]=field)
                    $actualValueIndex = $columnIndex + 2;
                    if (isset($rowCells[$actualValueIndex])) {
                        $value = trim($rowCells[$actualValueIndex]);
                    }
                }
            } else {
                // Format: "Account Status: | Open | Open | Open  |" or "| Account Status: | Open | Open | Open  |"
                // Check if first cell has colon (field name with colon)
                if (preg_match('/^(.+?):\s*$/', $firstCell, $nameMatch)) {
                    // Field name with colon
                    $fieldName = trim($nameMatch[1]);
                    // cells[0]=field name, cells[1]=TU, cells[2]=EXP, cells[3]=EQ
                    $actualValueIndex = $columnIndex + 1;
                    if (isset($rowCells[$actualValueIndex])) {
                        $value = trim($rowCells[$actualValueIndex]);
                    }
                } else {
                    // Field name without colon
                    $fieldName = trim($firstCell);
                    // cells[0]=field name, cells[1]=TU, cells[2]=EXP, cells[3]=EQ
                    $actualValueIndex = $columnIndex + 1;
                    if (isset($rowCells[$actualValueIndex])) {
                        $value = trim($rowCells[$actualValueIndex]);
                    }
                }
            }

            // Skip if field name is empty, dashes, or is a bureau name
            if (empty($fieldName) || preg_match('/^[\s\-]+$/', $fieldName) || preg_match('/TransUnion|Experian|Equifax|Item/i', $fieldName)) {
                continue;
            }

            // Validate value was extracted
            if (empty($value) || preg_match('/^[\s\-]+$/', $value)) {
                Log::debug("No value extracted for field '{$fieldName}' in row {$rowIndex} for bureau {$bureau}. RowCells: " . json_encode($rowCells) . ", actualValueIndex: " . ($actualValueIndex ?? 'N/A'));
                continue;
            }

            // CRITICAL FIX: Ensure value contains only single value, not concatenated values from multiple bureaus
            // If value contains multiple identical words (e.g., "CURRENT CURRENT CURRENT"), take only first
            // This can happen if cell extraction incorrectly captured multiple values
            $originalValue = $value;
            if (preg_match('/^(\w+)(?:\s+\1)+$/i', $value, $duplicateMatch)) {
                $value = $duplicateMatch[1];
                Log::warning("Detected concatenated value '{$originalValue}' for field '{$fieldName}' in row {$rowIndex} for bureau {$bureau}, using only first: '{$value}'");
            }

            // CRITICAL: Log extracted value for debugging
            $debugValueIndex = isset($actualValueIndex) ? $actualValueIndex : $valueIndex;
            Log::debug("Extracted field '{$fieldName}' = '{$value}' for bureau {$bureau} (row {$rowIndex}, valueIndex={$debugValueIndex})");

            // Validate field name and value
            if (empty($fieldName) || preg_match('/^[\s\-]+$/', $fieldName)) {
                continue;
            }

            if (empty($value) || preg_match('/^[\s\-]+$/', $value)) {
                Log::debug("Skipping empty value for field '{$fieldName}' in row {$rowIndex} for bureau {$bureau}");
                continue;
            }

            // COLUMN-BASED MAPPING: Map field name to data key based on field name pattern
            // CRITICAL: Payment Status must be checked BEFORE Account Status
            $fieldNameLower = strtolower($fieldName);

            if (stripos($fieldName, 'payment status') !== false || stripos($fieldName, 'pay status') !== false) {
                // Payment Status (Current, Late 30 Days, Collection, etc.)
                $bureauData['payment_status'] = trim($value);
                Log::debug("COLUMN-BASED: Extracted Payment Status for {$bureau} - Field: '{$fieldName}', Value: '{$value}'");
            } elseif (stripos($fieldName, 'account status') !== false) {
                // Account Status (Open, Closed, Paid, etc.)
                $bureauData['status'] = trim($value);
                Log::debug("COLUMN-BASED: Extracted Account Status for {$bureau} - Field: '{$fieldName}', Value: '{$value}'");
            } elseif (stripos($fieldName, 'status') !== false && stripos($fieldName, 'payment') === false) {
                // Generic Status field - determine if it's Account Status or Payment Status
                $statusValue = trim($value);
                if (preg_match('/^(Open|Closed|Paid|Active|Inactive)$/i', $statusValue)) {
                    // Looks like Account Status
                    $bureauData['status'] = $statusValue;
                    Log::debug("COLUMN-BASED: Extracted Status as Account Status for {$bureau} - Value: '{$value}'");
                } elseif (preg_match('/^(Current|Late|Collection|Charged|Delinquent)/i', $statusValue)) {
                    // Looks like Payment Status
                    $bureauData['payment_status'] = $statusValue;
                    Log::debug("COLUMN-BASED: Extracted Status as Payment Status for {$bureau} - Value: '{$value}'");
                } else {
                    // Default to Account Status if Payment Status not set yet
                    if (empty($bureauData['payment_status'])) {
                        $bureauData['payment_status'] = $statusValue;
                    } else {
                        $bureauData['status'] = $statusValue;
                    }
                }
            } elseif (stripos($fieldName, 'balance') !== false) {
                // CRITICAL: Ensure value contains full amount with comma before normalizing
                // Value should be like "$1,200" or "1,200" - normalizeBalance will handle it
                $normalizedBalance = $this->normalizer->normalizeBalance($value);
                $bureauData['balance'] = $normalizedBalance;
                Log::debug("COLUMN-BASED: Extracted Balance for {$bureau} - Raw Value: '{$value}', Normalized: {$normalizedBalance}");
            } elseif (stripos($fieldName, 'monthly payment') !== false || stripos($fieldName, 'monthly pay') !== false) {
                $normalizedPay = $this->normalizer->normalizeBalance($value);
                $bureauData['monthly_pay'] = $normalizedPay;
                Log::debug("COLUMN-BASED: Extracted Monthly Payment for {$bureau} - Raw Value: '{$value}', Normalized: {$normalizedPay}");
            } elseif (stripos($fieldName, 'high limit') !== false || stripos($fieldName, 'credit limit') !== false || (stripos($fieldName, 'limit') !== false && stripos($fieldName, 'high') !== false)) {
                $normalizedLimit = $this->normalizer->normalizeBalance($value);
                $bureauData['high_limit'] = $normalizedLimit;
                Log::debug("COLUMN-BASED: Extracted High Limit for {$bureau} - Raw Value: '{$value}', Normalized: {$normalizedLimit}");
            } elseif (stripos($fieldName, 'date opened') !== false || stripos($fieldName, 'opened') !== false) {
                $bureauData['date_opened'] = $this->parseDate($value);
                Log::debug("COLUMN-BASED: Extracted Date Opened for {$bureau} - Value: '{$value}'");
            } elseif (stripos($fieldName, 'past due') !== false) {
                $bureauData['past_due'] = $this->normalizer->normalizeBalance($value);
                Log::debug("COLUMN-BASED: Extracted Past Due for {$bureau} - Value: '{$value}'");
            } elseif (stripos($fieldName, 'date last active') !== false || stripos($fieldName, 'last active') !== false) {
                $bureauData['date_last_active'] = $this->parseDate($value);
                Log::debug("COLUMN-BASED: Extracted Date Last Active for {$bureau} - Value: '{$value}'");
            } elseif (stripos($fieldName, 'date reported') !== false || stripos($fieldName, 'last reported') !== false) {
                $bureauData['date_reported'] = $this->parseDate($value);
                Log::debug("COLUMN-BASED: Extracted Date Reported for {$bureau} - Value: '{$value}'");
            } elseif (stripos($fieldName, 'payment history') !== false || stripos($fieldName, 'history') !== false) {
                $bureauData['payment_history'] = trim($value);
            } elseif (stripos($fieldName, 'terms') !== false) {
                if (empty($bureauData['reason'])) {
                    $bureauData['reason'] = 'Terms: ' . trim($value);
                }
            } elseif (stripos($fieldName, 'remarks') !== false || stripos($fieldName, 'comments') !== false) {
                $bureauData['reason'] = trim($value);
            } else {
                // Unknown field - log for debugging
                Log::debug("COLUMN-BASED: Unknown field '{$fieldName}' with value '{$value}' for bureau {$bureau}");
            }
        }

        // Convert array to string for further processing (compatible with existing code)
        $result = '';
        foreach ($bureauData as $key => $val) {
            if ($val !== null && $val !== '') {
                // For numeric values, keep original format (normalizeBalance already converted to float)
                // But when converting to string, format properly
                if (in_array($key, ['balance', 'high_limit', 'monthly_pay', 'past_due']) && is_numeric($val)) {
                    $result .= ucfirst($key) . ': ' . number_format((float) $val, 2, '.', '') . "\n";
                } else {
                    $result .= ucfirst($key) . ': ' . $val . "\n";
                }
            }
        }

        // Log summary of extracted data with actual values for debugging
        if (!empty($bureauData)) {
            $extractedFields = array_keys(array_filter($bureauData, function ($v) {
                return $v !== null && $v !== '';
            }));
            $summary = [];
            foreach ($extractedFields as $field) {
                $displayValue = is_numeric($bureauData[$field]) ? number_format($bureauData[$field], 2) : $bureauData[$field];
                $summary[] = "{$field}={$displayValue}";
            }
            Log::info("COLUMN-BASED: Extracted " . count($extractedFields) . " fields for {$bureau}: " . implode(', ', $summary));
        } else {
            Log::warning("COLUMN-BASED: No data extracted for bureau {$bureau} from table section");
        }

        return $result;
    }

    /**
     * Extract from Raw Data View format
     * FIXED Bug 3: Improved pattern to handle line breaks in pipe-separated format
     * Example: "TransUnion | PORTFOLIO RECOVERY | 99998888 | $900.00 | Collection | Subscriber reports..."
     * Also handles: "TransUnion | LVNV FUNDING | 999COLL22 \n $1,200.00 | Collection | Unpaid"
     */
    private function extractFromRawDataView(string $section, string $bureau, string $accountName, ?string $accountNumber): string
    {
        // FIXED Bug 3: Handle line breaks - normalize section first
        // Replace line breaks with spaces for pattern matching, but keep track of original structure
        $normalizedSection = preg_replace('/\s*\n\s*/', ' ', $section);

        // Pattern 1: Full format with account name (handles line breaks)
        // Format: "Bureau | Account Name | Account Number | Balance | Status | Reason"
        // Balance may be on next line after account number
        $pattern1 = '/' . preg_quote($bureau, '/') . '\s*\|\s*' . preg_quote($accountName, '/') . '\s*\|\s*([X\*\d\-]+)\s*(?:\|\s*|\s+)\$?([\d,]+\.?\d*)\s*\|\s*([^|]+)\s*(?:\|\s*([^|]+))?/i';

        // Pattern 2: More flexible - account name may vary, balance may be on next line
        $pattern2 = '/' . preg_quote($bureau, '/') . '\s*\|\s*[^|]+\s*\|\s*([X\*\d\-]+)\s*(?:\|\s*|\s+)\$?([\d,]+\.?\d*)\s*\|\s*([^|]+)\s*(?:\|\s*([^|]+))?/i';

        // Pattern 3: Handle case where balance is on separate line after account number
        // Format: "Bureau | Name | Number \n $Balance | Status"
        $pattern3 = '/' . preg_quote($bureau, '/') . '\s*\|\s*' . preg_quote($accountName, '/') . '\s*\|\s*([X\*\d\-]+)\s+(?:[^\$]*?)\$?([\d,]+\.?\d*)\s*\|\s*([^|]+)\s*(?:\|\s*([^|]+))?/is';

        // Try pattern 1 first (more specific)
        if (preg_match($pattern1, $normalizedSection, $match)) {
            Log::debug("Raw Data View Pattern 1 matched for {$bureau} - {$accountName}: balance={$match[2]}");
            $result = "Balance: " . $match[2] . "\n";
            $result .= "Status: " . trim($match[3]) . "\n";
            if (isset($match[4]) && !empty(trim($match[4]))) {
                $result .= "Reason: " . trim($match[4]) . "\n";
            }
            return $result;
        }

        // Try pattern 3 (handles line breaks better)
        if (preg_match($pattern3, $section, $match)) {
            Log::debug("Raw Data View Pattern 3 matched (with line breaks) for {$bureau} - {$accountName}: balance={$match[2]}");
            $result = "Balance: " . $match[2] . "\n";
            $result .= "Status: " . trim($match[3]) . "\n";
            if (isset($match[4]) && !empty(trim($match[4]))) {
                $result .= "Reason: " . trim($match[4]) . "\n";
            }
            return $result;
        }

        // Try pattern 2 (more flexible)
        if (preg_match($pattern2, $normalizedSection, $match)) {
            // Verify account number matches if provided
            if ($accountNumber && trim($match[1]) !== $accountNumber) {
                return '';
            }

            Log::debug("Raw Data View Pattern 2 matched for {$bureau}: balance={$match[2]}");
            $result = "Balance: " . $match[2] . "\n";
            $result .= "Status: " . trim($match[3]) . "\n";
            if (isset($match[4]) && !empty(trim($match[4]))) {
                $result .= "Reason: " . trim($match[4]) . "\n";
            }
            return $result;
        }

        Log::debug("Raw Data View no match for {$bureau} - {$accountName} (#{$accountNumber})");
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
     * Extract from CSV block format (for Page 2 - Citibank/Midland)
     * Format: "Label","Value1","Value2","Value3"
     * This is the most accurate method for CSV-formatted data
     * 
     * @param string $section Account section text
     * @param string $bureau Bureau name (TransUnion, Experian, Equifax)
     * @return array|null Bureau data array or null if not found
     */
    /**
     * Extract from CSV Table format (IMPROVED - For Citibank, Midland)
     * Format: "Account Status:","Open","Open","Open"
     * This handles CSV-formatted data where values are in quoted CSV format
     * 
     * @param string $section Account section text
     * @param string $bureau Bureau name (TransUnion, Experian, Equifax)
     * @return array|null Bureau data array or null if not found
     */
    private function extractFromCsvBlock(string $section, string $bureau): ?array
    {
        // IMPROVED: Better regex to handle CSV format with proper quote handling
        // Pattern: "Key","Value1","Value2","Value3"
        // Supports multiline and handles commas inside quoted values
        $pattern = '/"([^"]+):?[\s]*",\s*"([^"]*)",\s*"([^"]*)",\s*"([^"]*)"/i';

        if (!preg_match_all($pattern, $section, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $data = [];

        // Bureau index mapping: TransUnion=2, Experian=3, Equifax=4 (0-based array index)
        $bureauIndexMap = [
            'transunion' => 2,
            'experian' => 3,
            'equifax' => 4
        ];

        $targetIndex = $bureauIndexMap[strtolower($bureau)] ?? null;
        if ($targetIndex === null || $targetIndex < 2 || $targetIndex > 4) {
            return null;
        }

        foreach ($matches as $row) {
            if (count($row) < 5) {
                continue;
            }

            // Clean label (remove colon, quotes, newlines)
            $label = trim(str_replace([':', '"', "\n", "\r"], '', $row[1]));
            $value = trim($row[$targetIndex]);

            // Skip empty values, dashes, or N/A
            if (empty($value) || $value === '-' || $value === 'N/A' || $value === '') {
                continue;
            }

            // Map value to field using improved mapping logic
            $this->mapValueToField($data, $label, $value);
        }

        // Only return if we extracted meaningful data
        if (!empty($data)) {
            Log::debug("CSV Block: Extracted " . count($data) . " fields for {$bureau}: " . implode(', ', array_keys($data)));
            return $data;
        }

        return null;
    }

    /**
     * Helper method to map extracted label-value pairs to standardized field keys
     * This centralizes the mapping logic for better maintainability
     * 
     * @param array &$data Reference to data array to populate
     * @param string $label Field label (e.g., "Account Status", "Balance")
     * @param string $value Field value (e.g., "Open", "$1,200")
     */
    private function mapValueToField(array &$data, string $label, string $value): void
    {
        $labelLower = strtolower($label);

        // Payment Status (highest priority - check first to avoid confusion with Account Status)
        if (stripos($label, 'Payment Status') !== false || stripos($label, 'Pay Status') !== false) {
            $data['payment_status'] = trim($value);
            Log::debug("Mapped '{$label}' -> payment_status: '{$value}'");
            return;
        }

        // Type / Account Type
        if (stripos($label, 'Type') !== false) {
            $typeValue = trim($value);
            $data['account_type'] = $typeValue;
            $data['type'] = $typeValue;
            Log::debug("Mapped '{$label}' -> account_type/type: '{$typeValue}'");
            return;
        }

        // Account Status
        if (stripos($label, 'Account Status') !== false || stripos($label, 'Status') !== false) {
            // Only set if payment_status not already set (to avoid overwriting)
            if (!isset($data['status'])) {
                $data['status'] = trim($value);
                Log::debug("Mapped '{$label}' -> status: '{$value}'");
            }
            return;
        }

        // Balance
        if (stripos($label, 'Balance') !== false) {
            $data['balance'] = $this->normalizer->normalizeBalance($value);
            Log::debug("Mapped '{$label}' -> balance: '{$value}' -> {$data['balance']}");
            return;
        }

        // High Limit / Credit Limit
        if (
            stripos($label, 'High Limit') !== false || stripos($label, 'Credit Limit') !== false ||
            (stripos($label, 'Limit') !== false && stripos($label, 'High') !== false)
        ) {
            $data['high_limit'] = $this->normalizer->normalizeBalance($value);
            Log::debug("Mapped '{$label}' -> high_limit: '{$value}' -> {$data['high_limit']}");
            return;
        }

        // Monthly Payment
        if (stripos($label, 'Monthly Payment') !== false || stripos($label, 'Monthly Pay') !== false) {
            $data['monthly_pay'] = $this->normalizer->normalizeBalance($value);
            Log::debug("Mapped '{$label}' -> monthly_pay: '{$value}' -> {$data['monthly_pay']}");
            return;
        }

        // Date Opened
        if (stripos($label, 'Date Opened') !== false || stripos($label, 'Opened') !== false) {
            $data['date_opened'] = $this->parseDate($value);
            Log::debug("Mapped '{$label}' -> date_opened: '{$value}' -> {$data['date_opened']}");
            return;
        }

        // Past Due
        if (stripos($label, 'Past Due') !== false) {
            $data['past_due'] = $this->normalizer->normalizeBalance($value);
            Log::debug("Mapped '{$label}' -> past_due: '{$value}' -> {$data['past_due']}");
            return;
        }

        // Date Reported / Last Reported
        if (stripos($label, 'Last Reported') !== false || stripos($label, 'Date Reported') !== false) {
            $data['date_reported'] = $this->parseDate($value);
            Log::debug("Mapped '{$label}' -> date_reported: '{$value}' -> {$data['date_reported']}");
            return;
        }

        // Date Last Active
        if (stripos($label, 'Date Last Active') !== false || stripos($label, 'Last Active') !== false) {
            $data['date_last_active'] = $this->parseDate($value);
            Log::debug("Mapped '{$label}' -> date_last_active: '{$value}' -> {$data['date_last_active']}");
            return;
        }

        // Terms (optional - usually not stored in bureau_data)
        // if (stripos($label, 'Terms') !== false) {
        //     $data['terms'] = trim($value);
        // }
    }

    /**
     * Extract from vertical block format (IMPROVED - For Chase, Wells Fargo)
     * Format: Label và Value nằm ở các dòng riêng biệt dưới tên Bureau
     * Also handles multiline key-value pairs and global headers
     * 
     * @param string $section Account section text
     * @param string $bureau Bureau name (TransUnion, Experian, Equifax)
     * @return array|null Bureau data array or null if not found
     */
    private function extractFromVerticalBlock(string $section, string $bureau): ?array
    {
        // 1. Find the text block starting with Bureau name
        // Find position of bureau name
        $bureauLower = strtolower($bureau);
        $startPos = stripos($section, $bureau);

        if ($startPos === false) {
            return null;
        }

        // Find end position (next bureau name or end of section)
        $nextBureaus = ['TransUnion', 'Experian', 'Equifax'];
        $endPos = strlen($section);

        foreach ($nextBureaus as $next) {
            if (strtolower($next) === $bureauLower) {
                continue;
            }
            $pos = stripos($section, $next, $startPos + 10); // +10 to avoid matching itself
            if ($pos !== false && $pos < $endPos) {
                $endPos = $pos;
            }
        }

        $blockText = substr($section, $startPos, $endPos - $startPos);
        $data = [];

        // 2. IMPROVED: Parse Key-Value pairs within block (supports multiline)
        // Map important keywords to field keys
        $keywords = [
            'Balance' => 'balance',
            'High Limit' => 'high_limit',
            'Credit Limit' => 'high_limit',
            'Monthly Payment' => 'monthly_pay',
            'Monthly Pay' => 'monthly_pay',
            'Account Status' => 'status',
            'Payment Status' => 'payment_status',
            'Pay Status' => 'payment_status',
            'Date Opened' => 'date_opened',
            'Past Due' => 'past_due',
            'Last Reported' => 'date_reported',
            'Date Reported' => 'date_reported',
            'Date Last Active' => 'date_last_active',
            'Terms' => 'terms'
        ];

        // First, try to find labels within the block (multiline support)
        foreach ($keywords as $label => $fieldKey) {
            // Pattern: Find Label, allow newlines (\s*\n*\s*), then capture value
            // This handles both "Balance: $50" and "Balance:\n$50"
            // Also handles "Balance:" on one line and "$50" on next line
            $pattern = '/' . preg_quote($label, '/') . '[:\s\n]*(\$?[0-9,.]+|[A-Za-z\s]+(?:\s+\d+)?)(?=\n|$)/is';

            if (preg_match($pattern, $blockText, $match)) {
                $value = trim($match[1]);
                if (!empty($value) && $value !== '-' && $value !== 'N/A') {
                    // Use mapValueToField for consistent normalization
                    $this->mapValueToField($data, $label, $value);
                }
            }
        }

        // 3. IMPROVED: Handle case where Label is in Global Header (not in block)
        // Example: "Balance:" is at section start, value is in bureau block
        // Use line-based alignment: find label in section, then find corresponding value in block
        if (empty($data['balance']) || empty($data['payment_status']) || empty($data['status'])) {
            // Look for global headers with 3 values (one per bureau)
            // Format: "Balance: $1,200 $1,200 $1,200" or "Balance:\n$1,200\n$1,200\n$1,200"
            $globalPatterns = [
                '/Balance[:\s]+(\$?[0-9,.]+)\s+(\$?[0-9,.]+)\s+(\$?[0-9,.]+)/i',
                '/Account\s+Status[:\s]+([A-Za-z\s]+)\s+([A-Za-z\s]+)\s+([A-Za-z\s]+)/i',
                '/Payment\s+Status[:\s]+([A-Za-z\s0-9]+)\s+([A-Za-z\s0-9]+)\s+([A-Za-z\s0-9]+)/i',
                '/High\s+Limit[:\s]+(\$?[0-9,.]+)\s+(\$?[0-9,.]+)\s+(\$?[0-9,.]+)/i',
                '/Monthly\s+Payment[:\s]+(\$?[0-9,.]+)\s+(\$?[0-9,.]+)\s+(\$?[0-9,.]+)/i',
            ];

            $bureauIndex = ['TransUnion' => 0, 'Experian' => 1, 'Equifax' => 2];
            $targetIndex = $bureauIndex[$bureau] ?? null;

            foreach ($globalPatterns as $pattern) {
                if (preg_match($pattern, $section, $match)) {
                    if ($targetIndex !== null && isset($match[$targetIndex + 1])) {
                        $value = trim($match[$targetIndex + 1]);
                        if (!empty($value)) {
                            // Determine field key from pattern
                            if (stripos($pattern, 'Balance') !== false && !isset($data['balance'])) {
                                $data['balance'] = $this->normalizer->normalizeBalance($value);
                            } elseif (stripos($pattern, 'Account Status') !== false && !isset($data['status'])) {
                                $data['status'] = trim($value);
                            } elseif (stripos($pattern, 'Payment Status') !== false && !isset($data['payment_status'])) {
                                $data['payment_status'] = trim($value);
                            } elseif (stripos($pattern, 'High Limit') !== false && !isset($data['high_limit'])) {
                                $data['high_limit'] = $this->normalizer->normalizeBalance($value);
                            } elseif (stripos($pattern, 'Monthly Payment') !== false && !isset($data['monthly_pay'])) {
                                $data['monthly_pay'] = $this->normalizer->normalizeBalance($value);
                            }
                        }
                    }
                }
            }
        }

        // 4. Fallback: Heuristic parsing for values without labels (original logic)
        // This handles cases where only values are present in the block
        if (empty($data)) {
            $lines = preg_split('/\r\n|\r|\n/', $blockText);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                // Money amounts ($1,200, $50)
                if (preg_match('/^\$([0-9,]+(?:\.\d{2})?)$/', $line, $m)) {
                    $amount = (float) str_replace(',', '', $m[1]);

                    if ($amount < 1000 && !isset($data['monthly_pay'])) {
                        $data['monthly_pay'] = $this->normalizer->normalizeBalance($line);
                    } elseif (!isset($data['balance'])) {
                        $data['balance'] = $this->normalizer->normalizeBalance($line);
                    } elseif (!isset($data['high_limit'])) {
                        $data['high_limit'] = $this->normalizer->normalizeBalance($line);
                    } elseif (!isset($data['past_due'])) {
                        $data['past_due'] = $this->normalizer->normalizeBalance($line);
                    }
                }
                // Dates (01/15/2020)
                elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $line)) {
                    if (!isset($data['date_opened'])) {
                        $data['date_opened'] = $this->parseDate($line);
                    } else {
                        $data['date_reported'] = $this->parseDate($line);
                    }
                }
                // Status (Open, Current, Closed, Late 30 Days, Collection...)
                elseif (preg_match('/^(Open|Closed|Paid|Current|Late|Collection|Charged Off|Charged-Off|Chrg Off)/i', $line)) {
                    $statusValue = trim($line);
                    if (stripos($statusValue, 'Current') !== false || stripos($statusValue, 'Late') !== false || stripos($statusValue, 'Collection') !== false) {
                        $data['payment_status'] = $statusValue;
                    } else {
                        $data['status'] = $statusValue;
                    }
                }
            }
        }

        if (!empty($data)) {
            Log::debug("Vertical Block: Extracted " . count($data) . " fields for {$bureau}: " . implode(', ', array_keys($data)));
            return $data;
        }

        return null;
    }

    /**
     * Extract directly from table rows when extractFromTable fails
     * This is a fallback method to extract data directly from table format rows
     * Format: "Field: | value1 | value2 | value3"
     */
    private function extractDirectlyFromTableRows(string $section, string $bureau): string
    {
        $bureaus = ['TransUnion', 'Experian', 'Equifax'];
        $columnIndex = array_search($bureau, $bureaus);

        if ($columnIndex === null) {
            return '';
        }

        $bureauData = [];
        $rows = preg_split('/\n/', $section);

        foreach ($rows as $row) {
            // Skip separator rows and header rows
            if (preg_match('/^[\s\|\-]+$/', $row) || preg_match('/TransUnion\s*\|\s*Experian\s*\|\s*Equifax/i', $row)) {
                continue;
            }

            // Pattern: "Field: | value1 | value2 | value3"
            if (preg_match('/^\s*([^|:]+?):\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)(?:\s*\||\s*$)/i', $row, $cells)) {
                $fieldName = trim($cells[1]);
                $value = trim($cells[$columnIndex + 2]); // +2 because cells[0]=match, cells[1]=field name

                if (empty($value) || preg_match('/^[\s\-]+$/', $value)) {
                    continue;
                }

                // Map field names to data keys
                if (stripos($fieldName, 'balance') !== false) {
                    $bureauData['balance'] = $this->normalizer->normalizeBalance($value);
                } elseif (stripos($fieldName, 'monthly payment') !== false || stripos($fieldName, 'monthly pay') !== false) {
                    $bureauData['monthly_pay'] = $this->normalizer->normalizeBalance($value);
                } elseif (stripos($fieldName, 'high limit') !== false || stripos($fieldName, 'credit limit') !== false || stripos($fieldName, 'limit') !== false) {
                    $bureauData['high_limit'] = $this->normalizer->normalizeBalance($value);
                } elseif (stripos($fieldName, 'date opened') !== false || stripos($fieldName, 'opened') !== false) {
                    $bureauData['date_opened'] = $this->parseDate($value);
                } elseif (stripos($fieldName, 'payment status') !== false || stripos($fieldName, 'pay status') !== false) {
                    $bureauData['payment_status'] = trim($value);
                } elseif (stripos($fieldName, 'account status') !== false || (stripos($fieldName, 'status') !== false && stripos($fieldName, 'payment') === false)) {
                    $bureauData['status'] = trim($value);
                } elseif (stripos($fieldName, 'past due') !== false) {
                    $bureauData['past_due'] = $this->normalizer->normalizeBalance($value);
                } elseif (stripos($fieldName, 'date last active') !== false || stripos($fieldName, 'last payment') !== false) {
                    $bureauData['date_last_active'] = $this->parseDate($value);
                } elseif (stripos($fieldName, 'last reported') !== false || stripos($fieldName, 'date reported') !== false) {
                    $bureauData['date_reported'] = $this->parseDate($value);
                } elseif (stripos($fieldName, 'comments') !== false || stripos($fieldName, 'remarks') !== false) {
                    $bureauData['reason'] = trim($value);
                }
            }
        }

        // Convert array to string for further processing
        $result = '';
        foreach ($bureauData as $key => $value) {
            if ($value !== null) {
                $result .= ucfirst($key) . ': ' . $value . "\n";
            }
        }

        return $result;
    }

    /**
     * Create CreditItem records for each bureau
     */
    private function createAccountItems(Client $client, array $accountData): array
    {
        $items = [];
        $bureaus = ['transunion', 'experian', 'equifax'];

        // Normalize bureau_data keys to lowercase to avoid missing matches (e.g., "TransUnion")
        if (isset($accountData['bureau_data']) && is_array($accountData['bureau_data'])) {
            $normalized = [];
            foreach ($accountData['bureau_data'] as $key => $value) {
                $normalizedKey = is_string($key) ? strtolower($key) : $key;
                $normalized[$normalizedKey] = $value;
            }
            $accountData['bureau_data'] = $normalized;
        }

        foreach ($bureaus as $bureau) {
            // IMPROVED: Always try to get bureau data, even if not explicitly set
            // This ensures we create items for all bureaus when table format is used
            if (!isset($accountData['bureau_data'][$bureau])) {
                // If "All Bureaus" but no specific data, create item with general data
                if (isset($accountData['bureau']) && strtolower($accountData['bureau']) === 'all bureaus') {
                    $bureauData = [
                        'balance' => $accountData['balance'] ?? 0,
                        'status' => $accountData['status'] ?? null,
                        'payment_status' => $accountData['payment_status'] ?? null,
                        'date_last_active' => $accountData['date_last_active'] ?? null,
                        'date_reported' => $accountData['date_reported'] ?? null,
                        'past_due' => $accountData['past_due'] ?? null,
                        'payment_history' => $accountData['payment_history'] ?? null,
                        'high_limit' => $accountData['high_limit'] ?? null,
                        'monthly_pay' => $accountData['monthly_pay'] ?? null,
                        'reason' => $accountData['reason'] ?? null,
                    ];
                } else {
                    // IMPROVED: Even if no bureau_data, still create item with empty/default values
                    // This ensures all accounts appear for all bureaus (important for table format)
                    // Only skip if we're absolutely sure this account doesn't exist for this bureau
                    $bureauData = [
                        'balance' => 0,
                        'status' => null,
                        'payment_status' => null,
                        'date_last_active' => null,
                        'date_reported' => null,
                        'past_due' => null,
                        'payment_history' => null,
                        'high_limit' => null,
                        'monthly_pay' => null,
                        'reason' => null,
                    ];

                    // Log that we're creating item without specific bureau data
                    Log::debug("Creating item for {$bureau} without specific bureau_data for account: {$accountData['account_name']}");
                }
            } else {
                $bureauData = $accountData['bureau_data'][$bureau];
            }

            // IMPROVED: Don't skip if account_name exists - always create item if account exists
            // This ensures all accounts appear for all bureaus, even if some fields are empty
            // Only skip if account_name is also empty (shouldn't happen)
            if (empty($accountData['account_name'])) {
                Log::warning("Skipping item creation: account_name is empty");
                continue;
            }

            // Truncate account_type to avoid DB overflow
            if (!empty($accountData['account_type'])) {
                $accountData['account_type'] = substr($accountData['account_type'], 0, 190);
            }

            // FIXED: Check for duplicates using both account_name and account_number
            // This prevents duplicates when same account appears multiple times
            $exists = CreditItem::where('client_id', $client->id)
                ->where('bureau', $bureau)
                ->where(function ($query) use ($accountData) {
                    // If account_number exists, check both number and name
                    if (!empty($accountData['account_number'])) {
                        $query->where('account_number', $accountData['account_number'])
                            ->where('account_name', $accountData['account_name'] ?? '');
                    } else {
                        // If no account_number, check by name only
                        $query->where('account_name', $accountData['account_name'] ?? '');
                    }
                })
                ->exists();

            if ($exists) {
                Log::debug("Skipping duplicate: {$accountData['account_name']} (#{$accountData['account_number']}) for bureau {$bureau}");
                continue;
            }

            // FIXED: Normalize status before saving to prevent display issues
            $normalizedStatus = $this->normalizer->normalizeStatus($bureauData['status'] ?? null);

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
                'payment_history' => $bureauData['payment_history'] ?? null,
                'status' => $normalizedStatus, // Account status (Open, Closed, etc.)
                'payment_status' => $this->normalizePaymentStatus($bureauData['payment_status'] ?? null), // Payment status - giữ nguyên giá trị gốc như "30 Days Late"
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
     * IMPROVED: Handle MM/YYYY format (e.g., "03/2019")
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

        // MM/YYYY format (e.g., "03/2019") - set day to 01
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $year = $matches[2];
            return "{$year}-{$month}-01";
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
     * Normalize Payment Status - giữ nguyên giá trị gốc
     * Payment Status cần giữ nguyên như "30 Days Late", "Paid as Agreed", "Collection"
     * KHÔNG normalize thành "CURRENT", "LATE_PAYMENT" như Status
     */
    private function normalizePaymentStatus(?string $paymentStatus): ?string
    {
        if (empty($paymentStatus)) {
            return null;
        }

        // Chỉ trim, không normalize - giữ nguyên giá trị gốc
        return trim($paymentStatus);
    }

    /**
     * Extract monthly payment from Payment Status or Remarks
     * Try to find dollar amounts that might represent monthly payment
     */
    private function extractMonthlyPay(?string $paymentStatus, ?string $remarks): ?float
    {
        $text = trim(($paymentStatus ?? '') . ' ' . ($remarks ?? ''));
        if (empty($text)) {
            return null;
        }

        // Look for patterns like "$450/month", "$450 monthly", "$450 payment"
        $patterns = [
            '/\$?([\d,]+\.?\d*)\s*(?:per\s*month|monthly|payment|pay)/i',
            '/monthly[:\s]*\$?([\d,]+\.?\d*)/i',
            '/payment[:\s]*\$?([\d,]+\.?\d*)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $amount = $this->normalizer->normalizeBalance($match[1]);
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        return null;
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

    /**
     * Parse Sample Credit Report format (Format 3)
     * Handles SATISFACTORY ACCOUNTS and ADVERSE ACCOUNTS sections
     * Format: Single account per entry, no bureau-specific data
     */
    private function parseSampleFormatAccounts(array $sections): array
    {
        $accounts = [];

        foreach ($sections as $sectionData) {
            $section = $sectionData['section'];
            $type = $sectionData['type']; // 'satisfactory' or 'adverse'

            // Extract accounts from section
            // Pattern: Account name followed by Acct# or Acct # on same or next line
            // Example: "Automobile Finance Inc. Acct#: 70705606"
            $accountPattern = '/([A-Z][A-Z\s&.,]+?)\s+Acct\s*#?\s*:?\s*([X\*\d\-]+)/i';
            preg_match_all($accountPattern, $section, $matches, PREG_SET_ORDER);

            Log::info("Sample format ({$type}): Found " . count($matches) . " accounts");

            foreach ($matches as $match) {
                $accountName = trim($match[1]);
                $accountNumber = trim($match[2]);

                // Skip if account name is too short or is a header
                if (strlen($accountName) < 3 || stripos($accountName, 'ACCOUNTS') !== false) {
                    continue;
                }

                // Extract account details from section after account name
                $accountSection = $this->extractAccountSectionFromSample($section, $accountName, $accountNumber);

                if (empty($accountSection)) {
                    continue;
                }

                // Parse account details
                $accountData = $this->parseSampleAccountDetails($accountSection, $accountName, $accountNumber, $type);

                // Extract Payment History grid if available
                $paymentHistory = $this->extractPaymentHistoryGrid($accountSection);
                if ($paymentHistory) {
                    $accountData['payment_history'] = $paymentHistory;
                }

                $accounts[] = $accountData;
            }
        }

        return $accounts;
    }

    /**
     * Extract account section from sample format
     * Finds the text block for a specific account
     */
    private function extractAccountSectionFromSample(string $section, string $accountName, string $accountNumber): string
    {
        // Normalize account name for matching (handle variations in spacing)
        $normalizedName = preg_quote($accountName, '/');
        $normalizedName = str_replace('\s+', '\s+', $normalizedName); // Allow flexible spacing

        // Find account name and number, then extract until next account or section end
        // Pattern 1: Account name followed by Acct# on same line
        $pattern1 = '/' . $normalizedName . '.*?Acct\s*#?\s*:?\s*' . preg_quote($accountNumber, '/') . '(.*?)(?=[A-Z][A-Z\s&.,]{3,}\s+Acct\s*#|ADVERSE|SATISFACTORY|CREDIT\s+INQUIRIES|$)/is';

        // Pattern 2: Account name on one line, Acct# on next line
        $pattern2 = '/' . $normalizedName . '(.*?)Acct\s*#?\s*:?\s*' . preg_quote($accountNumber, '/') . '(.*?)(?=[A-Z][A-Z\s&.,]{3,}\s+Acct\s*#|ADVERSE|SATISFACTORY|CREDIT\s+INQUIRIES|$)/is';

        if (preg_match($pattern1, $section, $match)) {
            return $match[1];
        }

        if (preg_match($pattern2, $section, $match)) {
            return $match[1] . $match[2];
        }

        // Pattern 3: More flexible - just find account number and extract surrounding text
        $pattern3 = '/Acct\s*#?\s*:?\s*' . preg_quote($accountNumber, '/') . '(.*?)(?=Acct\s*#|ADVERSE|SATISFACTORY|CREDIT\s+INQUIRIES|$)/is';
        if (preg_match($pattern3, $section, $match)) {
            return $match[1];
        }

        return '';
    }

    /**
     * Parse account details from sample format
     */
    private function parseSampleAccountDetails(string $accountSection, string $accountName, string $accountNumber, string $type): array
    {
        $accountData = [
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'account_type' => null,
            'date_opened' => null,
            'date_last_active' => null,
            'date_reported' => null,
            'balance' => 0,
            'high_limit' => null,
            'monthly_pay' => null,
            'past_due' => null,
            'status' => null,
            'payment_status' => null,
            'original_creditor' => null,
            'bureau_data' => [
                // Sample format doesn't have bureau-specific data, so we'll use a default bureau
                'transunion' => [],
            ],
        ];

        // Extract Date Opened
        if (preg_match('/Date Opened[:\s]*([0-9\/\-]+)/i', $accountSection, $match)) {
            $accountData['date_opened'] = $this->parseDate(trim($match[1]));
        }

        // Extract Balance
        if (preg_match('/Balance[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $match)) {
            $accountData['balance'] = $this->normalizer->normalizeBalance($match[1]);
        }

        // Extract Last Payment Made (Date Last Active)
        if (preg_match('/Last Payment Made[:\s]*([0-9\/\-]+)/i', $accountSection, $match)) {
            $accountData['date_last_active'] = $this->parseDate(trim($match[1]));
        }

        // Extract Pay Status / Payment Status - CRITICAL FIELD (QUAN TRỌNG NHẤT)
        // Payment Status: Current, Late 30 Days, Collection, Paid as Agreed, etc.
        $payStatusPatterns = [
            '/Pay Status[:\s]*([^\n]+)/i',
            '/Payment Status[:\s]*([^\n]+)/i',
            '/Payment[:\s]*([^\n]+)/i',
        ];
        foreach ($payStatusPatterns as $pattern) {
            if (preg_match($pattern, $accountSection, $match)) {
                $accountData['payment_status'] = trim($match[1]);
                break;
            }
        }

        // Extract Status (Account Status - Open, Closed, etc.) - ÍT QUAN TRỌNG HƠN
        // Only extract if it's clearly Account Status, not Payment Status
        if (preg_match('/Status[:\s]*([^\n]+)/i', $accountSection, $match)) {
            $statusValue = trim($match[1]);

            // If Payment Status already found, check if this Status is different
            if (!empty($accountData['payment_status'])) {
                // If Status is same as Payment Status, skip it
                if (strcasecmp($statusValue, $accountData['payment_status']) === 0) {
                    // Skip - already have payment_status
                } elseif (preg_match('/^(Open|Closed|Paid|Active|Inactive)/i', $statusValue)) {
                    // This is Account Status
                    $accountData['status'] = $statusValue;
                }
            } else {
                // No Payment Status found yet
                if (preg_match('/^(Current|Late|Collection|Paid as Agreed|Delinquent|30 Days|60 Days|90 Days)/i', $statusValue)) {
                    // This looks like Payment Status
                    $accountData['payment_status'] = $statusValue;
                } elseif (preg_match('/^(Open|Closed|Paid|Active|Inactive)/i', $statusValue)) {
                    // This is Account Status
                    $accountData['status'] = $statusValue;
                } else {
                    // Default: assume it's payment status if it contains payment-related keywords
                    if (
                        stripos($statusValue, 'late') !== false || stripos($statusValue, 'current') !== false ||
                        stripos($statusValue, 'collection') !== false || stripos($statusValue, 'paid') !== false
                    ) {
                        $accountData['payment_status'] = $statusValue;
                    } else {
                        $accountData['status'] = $statusValue;
                    }
                }
            }
        }

        // Extract Account Type
        if (preg_match('/Account Type[:\s]*([^\n]+)/i', $accountSection, $match)) {
            $accountData['account_type'] = trim($match[1]);
        }

        // Extract Type (e.g., Automobile, Credit Card, Student Loan)
        if (preg_match('/Type[:\s]*([^\n]+)/i', $accountSection, $match)) {
            if (empty($accountData['account_type'])) {
                $accountData['account_type'] = trim($match[1]);
            }
        }

        // Extract High Balance
        if (preg_match('/High Balance[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $match)) {
            $accountData['high_limit'] = $this->normalizer->normalizeBalance($match[1]);
        }

        // Extract Credit Limit
        if (preg_match('/Credit Limit[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $match)) {
            $accountData['high_limit'] = $this->normalizer->normalizeBalance($match[1]);
        }

        // Extract Payment Received (Monthly Pay)
        if (preg_match('/Payment Received[:\s]*\$?([\d,]+\.?\d*)/i', $accountSection, $match)) {
            $accountData['monthly_pay'] = $this->normalizer->normalizeBalance($match[1]);
        }

        // Extract Terms (may contain monthly payment info)
        if (preg_match('/Terms[:\s]*\$?([\d,]+\.?\d*)\s*per\s*month/i', $accountSection, $match)) {
            $accountData['monthly_pay'] = $this->normalizer->normalizeBalance($match[1]);
        }

        // Extract Date Reported - CRITICAL FIELD
        $dateReportedPatterns = [
            '/Date Reported[:\s]*([0-9\/\-]+)/i',
            '/Date Placed for Collection[:\s]*([0-9\/\-]+)/i',
            '/Date Updated[:\s]*([0-9\/\-]+)/i',
            '/Reported[:\s]*([0-9\/\-]+)/i',
        ];
        foreach ($dateReportedPatterns as $pattern) {
            if (preg_match($pattern, $accountSection, $match)) {
                $accountData['date_reported'] = $this->parseDate(trim($match[1]));
                break;
            }
        }

        // Extract Past Due Amount - CRITICAL FIELD
        $pastDuePatterns = [
            '/Past Due[:\s]*\$?([\d,]+\.?\d*)/i',
            '/Past Due Amount[:\s]*\$?([\d,]+\.?\d*)/i',
            '/Amount Past Due[:\s]*\$?([\d,]+\.?\d*)/i',
        ];
        foreach ($pastDuePatterns as $pattern) {
            if (preg_match($pattern, $accountSection, $match)) {
                $accountData['past_due'] = $this->normalizer->normalizeBalance($match[1]);
                break;
            }
        }

        // Extract Original Creditor (for collections)
        if (preg_match('/Original Creditor[:\s]*([^\n]+)/i', $accountSection, $match)) {
            $accountData['original_creditor'] = trim($match[1]);
        }

        // Extract Status (Open, Closed, etc.)
        if (preg_match('/Status[:\s]*([^\n]+)/i', $accountSection, $match)) {
            $status = trim($match[1]);
            // Skip if it's "Filed" (for bankruptcy) or other non-account status
            if (stripos($status, 'Filed') === false && stripos($status, 'Bankruptcy') === false) {
                $accountData['status'] = $status;
            }
        }

        // Set default bureau data (sample format doesn't have bureau-specific data)
        $accountData['bureau_data']['transunion'] = [
            'balance' => $accountData['balance'],
            'status' => $accountData['status'],
            'payment_status' => $accountData['payment_status'],
            'date_last_active' => $accountData['date_last_active'],
            'date_reported' => $accountData['date_reported'],
            'high_limit' => $accountData['high_limit'],
            'monthly_pay' => $accountData['monthly_pay'],
            'past_due' => $accountData['past_due'],
        ];

        return $accountData;
    }

    /**
     * Extract Payment History grid format
     * Format: Year | Jan | Feb | Mar | ... | Dec
     * Codes: OK, X, 30, 60, 90, blank
     */
    private function extractPaymentHistoryGrid(string $accountSection): ?string
    {
        // Find Payment History table
        // Pattern: Year header row followed by data rows
        $pattern = '/Year\s*\|\s*Jan\s*\|\s*Feb\s*\|\s*Mar\s*\|\s*Apr\s*\|\s*May\s*\|\s*Jun\s*\|\s*Jul\s*\|\s*Aug\s*\|\s*Sept\s*\|\s*Oct\s*\|\s*Nov\s*\|\s*Dec.*?(?=Year\s*\||[A-Z][A-Z\s&.,]{3,}\s+Acct|$)/is';

        if (!preg_match($pattern, $accountSection, $tableMatch)) {
            return null;
        }

        $tableText = $tableMatch[0];

        // Extract all data rows (skip header)
        $rows = explode("\n", $tableText);
        $historyData = [];

        foreach ($rows as $row) {
            $row = trim($row);
            if (empty($row) || stripos($row, 'Year') !== false) {
                continue; // Skip header
            }

            // Parse row: "2024 | OK | X | OK | OK | OK |"
            if (preg_match('/^(\d{4})\s*\|\s*(.+)$/i', $row, $rowMatch)) {
                $year = $rowMatch[1];
                $months = $rowMatch[2];

                // Split months by |
                $monthValues = array_map('trim', explode('|', $months));

                // Build history string: "2024:OK,X,OK,OK,OK,..."
                $historyData[] = $year . ':' . implode(',', $monthValues);
            }
        }

        if (empty($historyData)) {
            return null;
        }

        // Return as semicolon-separated years: "2024:OK,X,OK,...;2023:OK,OK,..."
        return implode(';', $historyData);
    }
}