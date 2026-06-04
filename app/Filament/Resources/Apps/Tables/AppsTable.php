<?php

namespace App\Filament\Resources\Apps\Tables;

use App\Enums\TokenAbility;
use App\Models\MonitoredApp;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AppsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                IconColumn::make('health.healthy')
                    ->label('Healthy')
                    ->boolean()
                    ->placeholder('—'),
                TextColumn::make('health.received_at')
                    ->label('Last seen')
                    ->since()
                    ->placeholder('never'),
            ])
            ->recordActions([
                Action::make('mintToken')
                    ->label('Mint ingest token')
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->modalDescription('This revokes any existing ingest token for this app and issues a new one. Copy it now — it is shown once.')
                    ->action(function (MonitoredApp $record): void {
                        // Rotate: drop existing tokens, mint a fresh ingest-only one.
                        $record->tokens()->delete();
                        $plain = $record->createToken($record->slug.'-ingest', [TokenAbility::Ingest->value])->plainTextToken;

                        Notification::make()
                            ->title('Ingest token minted')
                            ->body('Copy it now — it will not be shown again: '.$plain)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                DeleteAction::make(),
            ]);
    }
}
