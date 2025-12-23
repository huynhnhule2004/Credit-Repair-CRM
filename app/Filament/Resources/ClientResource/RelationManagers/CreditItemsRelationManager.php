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
                Forms\Components\Section::make('Account Information')
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
                            ->label('Account Number')
                            ->placeholder('e.g., 44445555****'),

                        Forms\Components\TextInput::make('account_type')
                            ->maxLength(255)
                            ->label('Account Type')
                            ->placeholder('e.g., Credit Card, Loan, Mortgage')
                            ->helperText('Type of credit account'),

                        Forms\Components\DatePicker::make('date_opened')
                            ->label('Date Opened')
                            ->displayFormat('M d, Y')
                            ->maxDate(now()),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Financial Information')
                    ->schema([
                        Forms\Components\TextInput::make('balance')
                            ->numeric()
                            ->prefix('$')
                            ->default(0)
                            ->label('Current Balance'),

                        Forms\Components\TextInput::make('high_limit')
                            ->numeric()
                            ->prefix('$')
                            ->label('Credit Limit / Loan Amount')
                            ->helperText('High credit limit or original loan amount'),

                        Forms\Components\TextInput::make('monthly_pay')
                            ->numeric()
                            ->prefix('$')
                            ->label('Monthly Payment')
                            ->helperText('Monthly payment amount'),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Status & Notes')
                    ->schema([
                        Forms\Components\TextInput::make('status')
                            ->maxLength(255)
                            ->label('Status (Account Status)')
                            ->placeholder('e.g., Open, Closed, Paid')
                            ->helperText('Account status - ít ảnh hưởng logic'),

                        Forms\Components\TextInput::make('payment_status')
                            ->maxLength(255)
                            ->label('Payment Status')
                            ->placeholder('e.g., Current, Late 30 Days, Collection')
                            ->helperText('QUAN TRỌNG - Dùng để quyết định màu đỏ/xanh'),

                        Forms\Components\Select::make('dispute_status')
                            ->options(CreditItem::getDisputeStatusOptions())
                            ->required()
                            ->default(CreditItem::STATUS_PENDING)
                            ->label('Dispute Status'),

                        Forms\Components\Textarea::make('reason')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->label('Comments / Reason')
                            ->rows(3),
                    ])
                    ->columns(2),
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

                Tables\Columns\TextColumn::make('account_type')
                    ->searchable()
                    ->label('Type')
                    ->toggleable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('balance')
                    ->money('USD')
                    ->sortable()
                    ->label('Balance'),

                Tables\Columns\TextColumn::make('high_limit')
                    ->money('USD')
                    ->sortable()
                    ->label('Credit Limit')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('monthly_pay')
                    ->money('USD')
                    ->sortable()
                    ->label('Monthly Pay')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('date_opened')
                    ->date()
                    ->sortable()
                    ->label('Opened')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->searchable()
                    ->label('Status (Account Status)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->searchable()
                    ->label('Payment Status')
                    ->badge()
                    ->color(fn (?string $state): string => match (true) {
                        empty($state) => 'gray',
                        stripos($state ?? '', 'current') !== false || stripos($state ?? '', 'paid as agreed') !== false => 'success',
                        stripos($state ?? '', 'late') !== false || stripos($state ?? '', 'delinquent') !== false => 'danger',
                        stripos($state ?? '', 'collection') !== false => 'warning',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state ? strtoupper($state) : 'N/A')
                    ->sortable()
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
                        ->form(function ($livewire) {
                            $client = $livewire->getOwnerRecord();
                            $selectedItems = collect($livewire->mountedTableBulkActionData['records'] ?? []);
                            
                            return [
                                Forms\Components\Radio::make('template_choice')
                                    ->label('Select Letter Template')
                                    ->options(LetterTemplate::active()->pluck('name', 'id')->toArray())
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set) use ($client, $selectedItems) {
                                        if ($state) {
                                            $template = LetterTemplate::find($state);
                                            if ($template) {
                                                $letterService = app(\App\Services\LetterGeneratorService::class);
                                                $preparedContent = $letterService->prepareContent($client, $template, $selectedItems);
                                                $set('letter_content', $preparedContent);
                                            }
                                        }
                                    })
                                    ->helperText('Choose a template for the dispute letter'),

                                Forms\Components\RichEditor::make('letter_content')
                                    ->label('Letter Content (Preview & Edit)')
                                    ->required()
                                    ->helperText('Preview the letter content with placeholders replaced. You can edit this content before generating the PDF.')
                                    ->toolbarButtons([
                                        'bold',
                                        'italic',
                                        'underline',
                                        'strike',
                                        'bulletList',
                                        'orderedList',
                                        'link',
                                        'undo',
                                        'redo',
                                    ])
                                    ->columnSpanFull()
                                    ->grow(),

                                Forms\Components\Checkbox::make('separate_by_bureau')
                                    ->label('Generate Separate Letters by Bureau')
                                    ->helperText('Create one letter per bureau instead of combining all items')
                                    ->default(false),
                            ];
                        })
                        ->action(function ($records, array $data, LetterGeneratorService $letterService) {
                            try {
                                $template = LetterTemplate::findOrFail($data['template_choice']);
                                $client = $this->getOwnerRecord();
                                $selectedItems = collect($records);
                                
                                // Use custom content if provided, otherwise use template
                                $customContent = $data['letter_content'] ?? null;

                                if ($data['separate_by_bureau'] ?? false) {
                                    // Generate separate PDFs for each bureau
                                    $itemsByBureau = $selectedItems->groupBy('bureau');
                                    $pdfs = [];
                                    $downloadUrls = [];
                                    
                                    foreach ($itemsByBureau as $bureau => $items) {
                                        $pdfs[$bureau] = $letterService->generate($client, $template, $items, $customContent);
                                        
                                        $fileName = 'dispute_letter_' . $client->id . '_' . $bureau . '_' . date('Ymd_His') . '.pdf';
                                        $fullPath = storage_path('app/public/letters/' . $fileName);

                                        if (!file_exists(dirname($fullPath))) {
                                            mkdir(dirname($fullPath), 0755, true);
                                        }

                                        $pdfs[$bureau]->save($fullPath);
                                        $downloadUrls[$bureau] = asset('storage/letters/' . $fileName);
                                    }

                                    $firstBureau = array_key_first($downloadUrls);
                                    $firstUrl = $downloadUrls[$firstBureau];

                                    Notification::make()
                                        ->success()
                                        ->title('Letters Generated')
                                        ->body('Generated ' . count($pdfs) . ' letter(s). Click below to download.')
                                        ->actions([
                                            \Filament\Notifications\Actions\Action::make('download')
                                                ->label('Download PDF (' . ucfirst($firstBureau) . ')')
                                                ->url($firstUrl)
                                                ->openUrlInNewTab(),
                                        ])
                                        ->persistent()
                                        ->send();
                                } else {
                                    // Generate single PDF with all items, save it and show a download link
                                    $pdf = $letterService->generate($client, $template, $selectedItems, $customContent);

                                    $fileName = 'dispute_letter_' . $client->id . '_' . date('Ymd_His') . '.pdf';
                                    $fullPath = storage_path('app/public/letters/' . $fileName);

                                    if (!file_exists(dirname($fullPath))) {
                                        mkdir(dirname($fullPath), 0755, true);
                                    }

                                    $pdf->save($fullPath);

                                    $url = asset('storage/letters/' . $fileName);

                                    Notification::make()
                                        ->success()
                                        ->title('Letter Generated')
                                        ->body('Dispute letter generated successfully. Click below to download.')
                                        ->actions([
                                            \Filament\Notifications\Actions\Action::make('download')
                                                ->label('Download PDF')
                                                ->url($url)
                                                ->openUrlInNewTab(),
                                        ])
                                        ->persistent()
                                        ->send();
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
                        ->modalWidth('4xl'),

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
