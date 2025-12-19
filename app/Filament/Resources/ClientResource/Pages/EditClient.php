<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Services\CreditReportParserService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),

            Actions\Action::make('import_report')
                ->label('Import Credit Report')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->form([
                    Forms\Components\FileUpload::make('report_pdf')
                        ->label('Upload IdentityIQ PDF Report (optional)')
                        ->helperText('Upload a PDF credit report exported from IdentityIQ. If provided, the PDF will be parsed instead of the HTML.')
                        ->acceptedFileTypes(['application/pdf'])
                        ->directory('credit-reports')
                        ->disk('local')
                        ->preserveFilenames(),

                    Forms\Components\Textarea::make('report_html')
                        ->label('Paste IdentityIQ HTML Source Code (optional)')
                        ->rows(10)
                        ->placeholder('Paste the entire HTML source code from IdentityIQ here...')
                        ->helperText('Alternative to PDF: right-click on the IdentityIQ page, select "View Page Source", copy all the HTML content and paste it here.'),
                ])
                ->action(function (array $data, CreditReportParserService $parserService) {
                    try {
                        $client = $this->record;

                        $importedCount = 0;

                        // Prefer PDF if provided
                        if (!empty($data['report_pdf'])) {
                            $pdfPath = storage_path('app/' . $data['report_pdf']);
                            $importedCount = $parserService->parsePdfAndSave($client, $pdfPath);
                        } elseif (!empty($data['report_html'])) {
                            $importedCount = $parserService->parseAndSave($client, $data['report_html']);
                        }

                        Notification::make()
                            ->success()
                            ->title('Import Successful')
                            ->body("Successfully imported {$importedCount} credit item(s).")
                            ->send();

                        // Refresh the page to show new items in relation manager
                        redirect()->route('filament.admin.resources.clients.edit', ['record' => $client->id]);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Import Failed')
                            ->body('Failed to import credit report: ' . $e->getMessage())
                            ->send();

                        \Illuminate\Support\Facades\Log::error('Credit report import failed', [
                            'client_id' => $this->record->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                })
                ->modalWidth('3xl')
                ->modalHeading('Import Credit Report from IdentityIQ')
                ->modalDescription("Import credit report data for {$this->record->full_name}")
                ->modalSubmitActionLabel('Import'),
        ];
    }
}
