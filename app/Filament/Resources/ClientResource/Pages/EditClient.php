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
                    Forms\Components\Textarea::make('report_html')
                        ->label('Paste IdentityIQ HTML Source Code')
                        ->required()
                        ->rows(10)
                        ->placeholder('Paste the entire HTML source code from IdentityIQ here...')
                        ->helperText('Right-click on the IdentityIQ page, select "View Page Source", copy all the HTML content and paste it here.'),
                ])
                ->action(function (array $data, CreditReportParserService $parserService) {
                    try {
                        $client = $this->record;
                        
                        $importedCount = $parserService->parseAndSave($client, $data['report_html']);

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
