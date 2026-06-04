<?php

namespace App\Filament\Pages;

use App\Enums\TokenAbility;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mobile API token management for the single operator account.
 *
 * Lists the operator's Sanctum personal access tokens and lets the operator mint
 * a new token (with the full Watchtower ability set) or revoke an existing one.
 * The plaintext token is shown exactly once — immediately after creation — and is
 * never stored or rendered again.
 */
class Tokens extends Page implements HasActions, HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;

    protected string $view = 'filament.pages.tokens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $title = 'API Tokens';

    protected static ?int $navigationSort = 80;

    /**
     * The most recently minted plaintext token, surfaced once in the UI then cleared.
     */
    public ?string $plainTextToken = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->latest())
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('abilities')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state),
                TextColumn::make('last_used_at')
                    ->dateTime()
                    ->placeholder('never'),
                TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (PersonalAccessToken $record): void {
                        $record->delete();

                        Notification::make()
                            ->title('Token revoked')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createToken')
                ->label('Create token')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('name')
                        ->label('Token name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('My iPhone'),
                ])
                ->action(function (array $data): void {
                    $operator = User::query()->oldest('id')->firstOrFail();

                    $newToken = $operator->createToken($data['name'], TokenAbility::mobile());

                    // Surface the plaintext token exactly once: a persistent, copyable
                    // notification plus a page callout. It is never persisted server-side.
                    $this->plainTextToken = $newToken->plainTextToken;

                    Notification::make()
                        ->title('Token created')
                        ->body('Copy it now — it will not be shown again: '.$newToken->plainTextToken)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function dismissPlainTextToken(): void
    {
        $this->plainTextToken = null;
    }
}
