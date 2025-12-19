<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Models\CreditItem;
use App\Models\LetterTemplate;
use App\Services\LetterGeneratorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CreditItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'creditItems';

    protected static ?string $title = 'Credit Items';

    protected static ?string $recordTitleAttribute = 'account_name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('bureau')
                    ->options(CreditItem::getBureauOptions())
                    ->required(),

                Forms\Components\TextInput::make('account_name')
                    ->required()
                    ->maxLength(255)
                    ->label('Account Name'),

                Forms\Components\TextInput::make('account_number')
                    ->maxLength(255)
                    ->label('Account Number'),

                Forms\Components\TextInput::make('balance')
                    ->numeric()
                    ->prefix('$')
                    ->default(0)
                    ->label('Balance'),

                Forms\Components\Textarea::make('reason')
                    ->maxLength(65535)
                    ->columnSpanFull()
                    ->label('Reason'),

                Forms\Components\TextInput::make('status')
                    ->maxLength(255)
                    ->label('Status')
                    ->placeholder('e.g., Late Payment, Collection'),

                Forms\Components\Select::make('dispute_status')
                    ->options(CreditItem::getDisputeStatusOptions())
                    ->required()
                    ->default(CreditItem::STATUS_PENDING)
                    ->label('Dispute Status'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account_name')
            ->columns([
                Tables\Columns\TextColumn::make('bureau')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'transunion' => 'info',
                        'experian' => 'warning',
                        'equifax' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => CreditItem::getBureauOptions()[$state] ?? ucfirst($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('account_name')
                    ->searchable()
                    ->sortable()
                    ->label('Account Name')
                    ->wrap(),

                Tables\Columns\TextColumn::make('account_number')
                    ->searchable()
                    ->label('Account #')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('balance')
                    ->money('USD')
                    ->sortable()
                    ->label('Balance'),

                Tables\Columns\TextColumn::make('status')
                    ->searchable()
                    ->label('Status')
                    ->toggleable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('dispute_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'sent' => 'info',
                        'deleted' => 'success',
                        'verified' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => CreditItem::getDisputeStatusOptions()[$state] ?? ucfirst($state))
                    ->sortable()
                    ->label('Dispute Status'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bureau')
                    ->options(CreditItem::getBureauOptions())
                    ->label('Bureau'),

                Tables\Filters\SelectFilter::make('dispute_status')
                    ->options(CreditItem::getDisputeStatusOptions())
                    ->label('Dispute Status'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),

                Tables\Actions\Action::make('generate_letter')
                    ->label('Generate Letter')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->form([
                        Forms\Components\Radio::make('letter_template')
                            ->label('Select Letter Template')
                            ->options(LetterTemplate::active()->pluck('name', 'id')->toArray())
                            ->required()
                            ->helperText('Choose a template for the dispute letter'),
                    ])
                    ->action(function (CreditItem $record, array $data, LetterGeneratorService $letterService) {
                        try {
                            $template = LetterTemplate::findOrFail($data['letter_template']);
                            $client = $this->getOwnerRecord();
                            
                            // Generate PDF
                            $pdf = $letterService->generate($client, $template, collect([$record]));
                            
                            // Generate filename
                            $fileName = 'dispute_letter_' . $client->id . '_' . date('Ymd_His') . '.pdf';
                            
                            // Save to storage/app/public/letters
                            $fullPath = storage_path('app/public/letters/' . $fileName);
                            
                            // Create directory if not exists
                            if (!file_exists(dirname($fullPath))) {
                                mkdir(dirname($fullPath), 0755, true);
                            }
                            
                            // Save PDF
                            $pdf->save($fullPath);
                            
                            // Get public URL
                            $url = asset('storage/letters/' . $fileName);

                            Notification::make()
                                ->success()
                                ->title('Letter Generated')
                                ->body('Click the button below to download.')
                                ->actions([
                                    \Filament\Notifications\Actions\Action::make('download')
                                        ->label('Download PDF')
                                        ->url($url)
                                        ->openUrlInNewTab(),
                                ])
                                ->persistent()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Generation Failed')
                                ->body('Failed to generate letter: ' . $e->getMessage())
                                ->send();
                        }
                    })
                    ->modalWidth('md'),

                Tables\Actions\Action::make('mark_sent')
                    ->label('Mark as Sent')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn (CreditItem $record): bool => $record->dispute_status === CreditItem::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (CreditItem $record) {
                        $record->update(['dispute_status' => CreditItem::STATUS_SENT]);
                        
                        Notification::make()
                            ->success()
                            ->title('Status Updated')
                            ->body('Item marked as sent.')
                            ->send();
                    }),

                Tables\Actions\Action::make('mark_deleted')
                    ->label('Mark as Deleted')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CreditItem $record): bool => $record->dispute_status === CreditItem::STATUS_SENT)
                    ->requiresConfirmation()
                    ->action(function (CreditItem $record) {
                        $record->update(['dispute_status' => CreditItem::STATUS_DELETED]);
                        
                        Notification::make()
                            ->success()
                            ->title('Status Updated')
                            ->body('Item marked as deleted.')
                            ->send();
                    }),

                Tables\Actions\Action::make('mark_verified')
                    ->label('Mark as Verified')
                    ->icon('heroicon-o-shield-check')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CreditItem $record) {
                        $record->update(['dispute_status' => CreditItem::STATUS_VERIFIED]);
                        
                        Notification::make()
                            ->success()
                            ->title('Status Updated')
                            ->body('Item marked as verified.')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('generate_dispute_letter')
                        ->label('Generate Dispute Letter')
                        ->icon('heroicon-o-document-text')
                        ->color('primary')
                        ->form([
                            Forms\Components\Radio::make('template_choice')
                                ->label('Select Letter Template')
                                ->options(LetterTemplate::active()->pluck('name', 'id')->toArray())
                                ->required()
                                ->helperText('Choose a template for the dispute letter'),

                            Forms\Components\Checkbox::make('separate_by_bureau')
                                ->label('Generate Separate Letters by Bureau')
                                ->helperText('Create one letter per bureau instead of combining all items')
                                ->default(false),
                        ])
                        ->action(function ($records, array $data, LetterGeneratorService $letterService) {
                            try {
                                $template = LetterTemplate::findOrFail($data['template_choice']);
                                $client = $this->getOwnerRecord();
                                $selectedItems = collect($records);

                                if ($data['separate_by_bureau'] ?? false) {
                                    // Generate separate PDFs for each bureau
                                    $pdfs = $letterService->generateByBureau($client, $template, $selectedItems);
                                    
                                    // For now, just download the first one
                                    // In production, you might want to create a ZIP file
                                    $firstBureau = array_key_first($pdfs);
                                    $pdf = $pdfs[$firstBureau];
                                    
                                    Notification::make()
                                        ->success()
                                        ->title('Letters Generated')
                                        ->body("Generated " . count($pdfs) . " letter(s) - downloading first one.")
                                        ->send();

                                    return $pdf->download();
                                } else {
                                    // Generate single PDF with all items
                                    $pdf = $letterService->generate($client, $template, $selectedItems);

                                    Notification::make()
                                        ->success()
                                        ->title('Letter Generated')
                                        ->body('Dispute letter generated successfully.')
                                        ->send();

                                    return $pdf->download();
                                }
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Generation Failed')
                                    ->body('Failed to generate letter: ' . $e->getMessage())
                                    ->send();

                                \Illuminate\Support\Facades\Log::error('Letter generation failed', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                ]);

                                return null;
                            }
                        })
                        ->deselectRecordsAfterCompletion()
                        ->modalWidth('md'),

                    Tables\Actions\BulkAction::make('mark_as_sent')
                        ->label('Mark as Sent')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->dispute_status === CreditItem::STATUS_PENDING) {
                                    $record->update(['dispute_status' => CreditItem::STATUS_SENT]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Status Updated')
                                ->body("Marked {$count} item(s) as sent.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('mark_as_deleted')
                        ->label('Mark as Deleted')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $record->update(['dispute_status' => CreditItem::STATUS_DELETED]);
                                $count++;
                            }

                            Notification::make()
                                ->success()
                                ->title('Status Updated')
                                ->body("Marked {$count} item(s) as deleted.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
