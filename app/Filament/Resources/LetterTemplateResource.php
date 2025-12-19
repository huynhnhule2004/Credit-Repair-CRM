<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LetterTemplateResource\Pages;
use App\Models\LetterTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class LetterTemplateResource extends Resource
{
    protected static ?string $model = LetterTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Letter Templates';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Template Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Template Name')
                            ->placeholder('e.g., Standard Dispute Letter'),

                        Forms\Components\TextInput::make('type')
                            ->maxLength(255)
                            ->label('Template Type')
                            ->placeholder('e.g., dispute, goodwill, debt-validation')
                            ->helperText('Optional category for organizing templates'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Only active templates appear in letter generation'),
                    ]),

                Forms\Components\Section::make('Letter Content')
                    ->schema([
                        Forms\Components\Placeholder::make('placeholders_info')
                            ->label('Available Placeholders')
                            ->content(function () {
                                $placeholders = LetterTemplate::getAvailablePlaceholders();
                                $html = '<div style="background: #f3f4f6; padding: 12px; border-radius: 6px; font-size: 13px;">';
                                $html .= '<strong>You can use these placeholders in your template:</strong><br><br>';
                                
                                foreach ($placeholders as $placeholder => $description) {
                                    $html .= '<code style="background: #e5e7eb; padding: 2px 6px; border-radius: 3px; margin-right: 8px;">' 
                                           . htmlspecialchars($placeholder) 
                                           . '</code> - ' 
                                           . htmlspecialchars($description) 
                                           . '<br>';
                                }
                                
                                $html .= '</div>';
                                return new HtmlString($html);
                            }),

                        Forms\Components\RichEditor::make('content')
                            ->required()
                            ->label('Letter Content')
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'strike',
                                'link',
                                'heading',
                                'bulletList',
                                'orderedList',
                                'blockquote',
                                'codeBlock',
                                'undo',
                                'redo',
                            ])
                            ->placeholder('Write your letter template here. Use placeholders like {{client_name}} which will be replaced with actual data.')
                            ->helperText('HTML is supported. Placeholders will be automatically replaced when generating letters.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Template Name')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('type')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->label('Type')
                    ->toggleable(),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Created')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Last Updated')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All templates')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Template Type')
                    ->options(function () {
                        return LetterTemplate::query()
                            ->distinct()
                            ->pluck('type', 'type')
                            ->filter()
                            ->toArray();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                
                Tables\Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (LetterTemplate $record) {
                        $newTemplate = $record->replicate();
                        $newTemplate->name = $record->name . ' (Copy)';
                        $newTemplate->is_active = false;
                        $newTemplate->save();

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Template Duplicated')
                            ->body('A copy has been created and marked as inactive.')
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $record->update(['is_active' => true]);
                                $count++;
                            }

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Templates Activated')
                                ->body("Activated {$count} template(s).")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Deactivate Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                $record->update(['is_active' => false]);
                                $count++;
                            }

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Templates Deactivated')
                                ->body("Deactivated {$count} template(s).")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLetterTemplates::route('/'),
            'create' => Pages\CreateLetterTemplate::route('/create'),
            'edit' => Pages\EditLetterTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                \Illuminate\Database\Eloquent\SoftDeletingScope::class,
            ]);
    }
}
