<?php

namespace Database\Seeders;

use App\Models\LetterTemplate;
use Illuminate\Database\Seeder;

class LetterTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Standard Dispute Letter Template
        LetterTemplate::create([
            'name' => 'Standard Credit Dispute Letter',
            'type' => 'dispute',
            'is_active' => true,
            'content' => $this->getStandardDisputeTemplate(),
        ]);

        // Goodwill Letter Template
        LetterTemplate::create([
            'name' => 'Goodwill Adjustment Request',
            'type' => 'goodwill',
            'is_active' => true,
            'content' => $this->getGoodwillTemplate(),
        ]);

        // Debt Validation Letter Template
        LetterTemplate::create([
            'name' => 'Debt Validation Request',
            'type' => 'debt-validation',
            'is_active' => true,
            'content' => $this->getDebtValidationTemplate(),
        ]);
    }

    private function getStandardDisputeTemplate(): string
    {
        return <<<'HTML'
<p>{{bureau_name}}<br>
Consumer Relations Department<br>
[Bureau Address]</p>

<p><strong>RE: Dispute of Credit Report Errors</strong></p>

<p>Dear {{bureau_name}} Representative,</p>

<p>I am writing to formally dispute the following information on my credit report. According to the Fair Credit Reporting Act (FCRA), I have the right to request that you verify the accuracy of the items listed below.</p>

<p>The following items contain inaccurate or incomplete information:</p>

{{dispute_items}}

<p>I am requesting that you conduct a thorough investigation into these matters. If you cannot verify these items as accurate and complete, I respectfully request that they be removed from my credit report immediately, as required by federal law.</p>

<p>Under the FCRA, you have 30 days to complete your investigation. Please send me written verification of the results of your investigation.</p>

<p>Thank you for your prompt attention to this matter.</p>

<p><strong>Personal Information for Verification:</strong><br>
Full Name: {{client_name}}<br>
Date of Birth: {{client_dob}}<br>
SSN: XXX-XX-{{client_ssn}}<br>
Current Address: {{client_address}}, {{client_city}}, {{client_state}} {{client_zip}}<br>
Phone: {{client_phone}}</p>
HTML;
    }

    private function getGoodwillTemplate(): string
    {
        return <<<'HTML'
<p>{{account_name}}<br>
Customer Service Department<br>
[Company Address]</p>

<p><strong>RE: Goodwill Adjustment Request</strong></p>

<p>Dear {{account_name}} Team,</p>

<p>I am writing to request a goodwill adjustment to my credit report regarding the following account(s):</p>

{{dispute_items}}

<p>I have been a loyal customer and have maintained a positive relationship with your company. The late payment(s) reflected on my credit report were due to [briefly explain circumstances - e.g., "unexpected medical emergency," "temporary job loss," etc.].</p>

<p>I have since taken steps to ensure this will not happen again by [explain actions taken]. My current payment history demonstrates my commitment to financial responsibility.</p>

<p>I kindly request that you consider removing this negative mark from my credit report as a gesture of goodwill. This adjustment would greatly help me in [explain how it would help - e.g., "securing a mortgage," "improving my credit score," etc.].</p>

<p>I value our business relationship and hope you will consider my request favorably.</p>

<p>Thank you for your time and consideration.</p>
HTML;
    }

    private function getDebtValidationTemplate(): string
    {
        return <<<'HTML'
<p>[Collection Agency Name]<br>
[Collection Agency Address]</p>

<p><strong>RE: Debt Validation Request</strong></p>

<p>Dear Sir or Madam,</p>

<p>This letter is being sent to you in response to a notice I received from your company regarding the following debt(s):</p>

{{dispute_items}}

<p>Be advised that this is not a refusal to pay, but a notice sent pursuant to the Fair Debt Collection Practices Act (FDCPA) 15 USC 1692g Sec. 809 (b) that your claim is disputed and validation is requested.</p>

<p>I am requesting the following information:</p>

<ul>
<li>Proof that you are licensed to collect debts in my state</li>
<li>Proof of your authority to collect this alleged debt</li>
<li>Complete payment history from the original creditor</li>
<li>Copy of the original signed contract or application</li>
<li>Verification that you have the right to collect this debt</li>
</ul>

<p>Please be advised that I am requesting validation of this debt. Until validation is provided, I expect that all collection activities will cease, as required by the FDCPA.</p>

<p>I expect to receive a response within 30 days of your receipt of this letter.</p>

<p>Please note that I am exercising my rights under federal law to request validation of this debt. All communication regarding this matter should be in writing only.</p>
HTML;
    }
}
