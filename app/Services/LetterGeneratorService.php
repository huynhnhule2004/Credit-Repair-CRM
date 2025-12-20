<?php

namespace App\Services;

use App\Models\Client;
use App\Models\LetterTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

class LetterGeneratorService
{
    /**
     * Generate a dispute letter PDF for a client.
     *
     * @param Client $client The client for whom the letter is being generated
     * @param LetterTemplate $template The letter template to use
     * @param Collection $selectedItems Collection of CreditItem models to dispute
     * @param string|null $customContent Optional custom content to use instead of template
     * @return PdfBuilder The PDF builder instance ready to download
     * @throws \Exception If generation fails
     */
    public function generate(Client $client, LetterTemplate $template, Collection $selectedItems, ?string $customContent = null): PdfBuilder
    {
        try {
            // Use custom content if provided, otherwise use template content
            $baseContent = $customContent ?? $template->content;

            // Replace placeholders in template content
            $content = $this->replaceTemplatePlaceholders($baseContent, $client, $selectedItems);

            // Generate PDF using Spatie Laravel PDF
            $pdf = Pdf::view('pdf.dispute-letter', [
                'content' => $content,
                'client' => $client,
                'items' => $selectedItems,
            ])
                ->format('a4')
                ->margins(20, 20, 20, 20)
                ->name($this->generateFileName($client, $template));

            Log::info("Generated dispute letter for client {$client->id} using template {$template->id}");

            return $pdf;
        } catch (\Exception $e) {
            Log::error("Failed to generate letter: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Prepare letter content with placeholders replaced (for preview/editing).
     *
     * @param Client $client
     * @param LetterTemplate $template
     * @param Collection $selectedItems
     * @return string The processed content ready for editing
     */
    public function prepareContent(Client $client, LetterTemplate $template, Collection $selectedItems): string
    {
        return $this->replaceTemplatePlaceholders($template->content, $client, $selectedItems);
    }

    /**
     * Replace all placeholders in template content with actual data.
     *
     * @param string $content The template content with placeholders
     * @param Client $client The client data
     * @param Collection $selectedItems The credit items to include
     * @return string The processed content
     */
    private function replaceTemplatePlaceholders(string $content, Client $client, Collection $selectedItems): string
    {
        // Client-related replacements
        $replacements = [
            '{{client_name}}' => $client->full_name,
            '{{client_first_name}}' => $client->first_name,
            '{{client_last_name}}' => $client->last_name,
            '{{client_address}}' => $client->address,
            '{{client_city}}' => $client->city,
            '{{client_state}}' => $client->state,
            '{{client_zip}}' => $client->zip,
            '{{client_phone}}' => $client->phone,
            '{{client_email}}' => $client->email ?? '',
            '{{client_ssn}}' => $client->ssn,
            '{{client_dob}}' => $client->dob
                ? Carbon::parse($client->dob)->format('m/d/Y')
                : '',
            '{{current_date}}' => now()->format('F d, Y'),
        ];

        // Replace disputed items
        $replacements['{{dispute_items}}'] = $this->formatDisputedItems($selectedItems);

        // Get bureau name if items are from a single bureau
        $bureaus = $selectedItems->pluck('bureau')->unique();
        if ($bureaus->count() === 1) {
            $replacements['{{bureau_name}}'] = ucfirst($bureaus->first());
        } else {
            $replacements['{{bureau_name}}'] = 'Credit Bureau';
        }

        // Perform all replacements
        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
    }

    /**
     * Format disputed items as HTML list.
     *
     * @param Collection $items Collection of CreditItem models
     * @return string HTML formatted list of items
     */
    private function formatDisputedItems(Collection $items): string
    {
        if ($items->isEmpty()) {
            return '<p>No items selected for dispute.</p>';
        }

        $html = '<ul style="list-style-type: disc; margin-left: 20px;">';

        foreach ($items as $item) {
            $html .= '<li>';
            $html .= '<strong>' . htmlspecialchars($item->account_name) . '</strong>';

            if (!empty($item->account_number)) {
                $html .= ' - Account #: ' . htmlspecialchars($item->account_number);
            }

            if ($item->balance > 0) {
                $html .= ' - Balance: $' . number_format($item->balance, 2);
            }

            if (!empty($item->status)) {
                $html .= ' - Status: ' . htmlspecialchars($item->status);
            }

            $html .= ' (' . $item->bureau_name . ')';
            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * Format disputed items as plain text.
     *
     * @param Collection $items Collection of CreditItem models
     * @return string Plain text formatted list of items
     */
    private function formatDisputedItemsPlainText(Collection $items): string
    {
        if ($items->isEmpty()) {
            return 'No items selected for dispute.';
        }

        $text = '';
        $counter = 1;

        foreach ($items as $item) {
            $text .= "{$counter}. {$item->account_name}";

            if (!empty($item->account_number)) {
                $text .= " - Account #: {$item->account_number}";
            }

            if ($item->balance > 0) {
                $text .= " - Balance: $" . number_format($item->balance, 2);
            }

            if (!empty($item->status)) {
                $text .= " - Status: {$item->status}";
            }

            $text .= " ({$item->bureau_name})\n";
            $counter++;
        }

        return trim($text);
    }

    /**
     * Generate a unique filename for the PDF.
     *
     * @param Client $client
     * @param LetterTemplate $template
     * @return string
     */
    private function generateFileName(Client $client, LetterTemplate $template): string
    {
        $clientName = str_replace(' ', '_', $client->full_name);
        $templateName = str_replace(' ', '_', $template->name);
        $date = now()->format('Y-m-d');

        return "dispute_letter_{$clientName}_{$templateName}_{$date}.pdf";
    }

    /**
     * Generate dispute letter for specific bureau.
     *
     * @param Client $client
     * @param LetterTemplate $template
     * @param string $bureau
     * @return PdfBuilder
     */
    public function generateForBureau(Client $client, LetterTemplate $template, string $bureau): PdfBuilder
    {
        $items = $client->creditItems()
            ->where('bureau', $bureau)
            ->where('dispute_status', 'pending')
            ->get();

        return $this->generate($client, $template, $items);
    }

    /**
     * Generate multiple letters (one per bureau) at once.
     *
     * @param Client $client
     * @param LetterTemplate $template
     * @param Collection $selectedItems
     * @param string|null $customContent Optional custom content to use instead of template
     * @return array<string, PdfBuilder> Array of PDFs keyed by bureau name
     */
    public function generateByBureau(Client $client, LetterTemplate $template, Collection $selectedItems, ?string $customContent = null): array
    {
        $pdfs = [];

        // Group items by bureau
        $itemsByBureau = $selectedItems->groupBy('bureau');

        foreach ($itemsByBureau as $bureau => $items) {
            $pdfs[$bureau] = $this->generate($client, $template, $items, $customContent);
        }

        return $pdfs;
    }
}