<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Services\CreditReportParserService;
use Filament\Forms;
use Filament\Notifications\Notification;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            
            Actions\Action::make('import_report')
                ->label('Import Credit Report')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->form([
                    Forms\Components\Select::make('client_id')
                        ->label('Select Client')
                        ->options(\App\Models\Client::all()->pluck('full_name', 'id')->toArray())
                        ->searchable()
                        ->required()
                        ->native(false),

                    Forms\Components\Textarea::make('html_source')
                        ->label('Paste IdentityIQ HTML Source Code')
                        ->required()
                        ->rows(10)
                        ->placeholder('Paste the entire HTML source code from IdentityIQ here...')
                        ->helperText('Right-click on the IdentityIQ page, select "View Page Source", copy all the HTML content and paste it here.'),
                ])
                ->action(function (array $data, CreditReportParserService $parserService) {
                    try {
                        $client = \App\Models\Client::findOrFail($data['client_id']);
                        
                        $importedCount = $parserService->parseAndSave($client, $data['html_source']);

                        Notification::make()
                            ->success()
                            ->title('Import Successful')
                            ->body("Successfully imported {$importedCount} credit item(s) for {$client->full_name}.")
                            ->send();

                        // Redirect to client edit page to view imported items
                        return redirect()->route('filament.admin.resources.clients.edit', ['record' => $client->id]);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Import Failed')
                            ->body('Failed to import credit report: ' . $e->getMessage())
                            ->send();

                        \Illuminate\Support\Facades\Log::error('Credit report import failed', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                })
                ->modalWidth('3xl')
                ->modalHeading('Import Credit Report from IdentityIQ')
                ->modalDescription('Paste the HTML source code from IdentityIQ to automatically import negative credit items.')
                ->modalSubmitActionLabel('Import'),
        ];
    }
}
