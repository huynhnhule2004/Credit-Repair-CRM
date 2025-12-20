<?php

namespace App\Services\PdfParsing;

use App\Models\Client;

interface PdfParserStrategyInterface
{
    /**
     * Parse PDF text and extract credit items
     *
     * @param Client $client
     * @param string $text Extracted text from PDF
     * @return array Array of extracted items [['bureau' => ..., 'account_name' => ..., ...], ...]
     */
    public function parse(Client $client, string $text): array;

    /**
     * Get strategy name for logging
     *
     * @return string
     */
    public function getName(): string;
}

