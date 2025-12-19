<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CreditItem;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditReportParserService
{
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
     * This keeps the existing HTML-based import while allowing uploads of
     * PDF reports that follow a simple, structured text format.
     *
     * Expected line format (you can adjust this to match real IdentityIQ PDFs):
     * Bureau | Account Name | Account Number | Balance | Status | Reason (optional)
     *
     * Example:
     * TransUnion | ABC BANK | 1234567890 | $1,250.00 | Charge Off | Inaccurate late payment
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

            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();

            $lines = preg_split('/\R+/', $text);

            $importedCount = 0;

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                // Split by pipe and normalise segments
                $parts = array_map('trim', explode('|', $line));

                // Expect at least: bureau | account name | account number | balance | status
                if (count($parts) < 5) {
                    continue;
                }

                [$bureauRaw, $accountName, $accountNumber, $balanceRaw, $status] = $parts;
                $reason = $parts[5] ?? null;

                $bureau = strtolower($bureauRaw);

                if (!in_array($bureau, ['transunion', 'experian', 'equifax'], true)) {
                    continue;
                }

                // Normalise balance: remove everything except digits and dot
                $balanceText = preg_replace('/[^0-9.]/', '', $balanceRaw);
                $balance = floatval($balanceText);

                // Avoid duplicates
                $exists = CreditItem::where('client_id', $client->id)
                    ->where('bureau', $bureau)
                    ->where('account_number', $accountNumber)
                    ->exists();

                if ($exists) {
                    continue;
                }

                CreditItem::create([
                    'client_id' => $client->id,
                    'bureau' => $bureau,
                    'account_name' => $accountName,
                    'account_number' => $accountNumber,
                    'balance' => $balance,
                    'reason' => $reason,
                    'status' => $status,
                    'dispute_status' => CreditItem::STATUS_PENDING,
                ]);

                $importedCount++;
            }

            DB::commit();

            Log::info("Successfully imported {$importedCount} credit items from PDF for client {$client->id}");

            return $importedCount;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to parse credit report PDF: {$e->getMessage()}");
            throw $e;
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