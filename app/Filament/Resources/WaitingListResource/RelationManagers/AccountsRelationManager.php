<?php

namespace App\Filament\Resources\WaitingListResource\RelationManagers;

use App\Models\Training\WaitingList;
use App\Models\Training\WaitingList\WaitingListAccount;
use AxonC\FilamentCopyablePlaceholder\Forms\Components\CopyablePlaceholder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * @property WaitingList $ownerRecord
 */
class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'waitingListAccounts';

    protected $listeners = ['refreshWaitingList' => '$refresh'];

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Fieldset::make('base_information')
                    ->label('Base Information')
                    ->schema([
                        CopyablePlaceholder::make('id')
                            ->label('CID')
                            ->content(fn (WaitingListAccount $record) => $record->account_id)
                            ->iconOnly(),

                        CopyablePlaceholder::make('name')
                            ->label('Name')
                            ->content(fn (WaitingListAccount $record) => $record->account->name)
                            ->iconOnly(),

                        Forms\Components\Placeholder::make('position')
                            ->label('Position')
                            ->content(function (WaitingListAccount $record) {
                                return sprintf(
                                    '%s of %d',
                                    $this->ownerRecord->positionOf($record) ?? '-',
                                    $this->ownerRecord->waitingListAccounts->count()
                                );
                            }),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->placeholder('Add notes here'),

                    ]),

                Forms\Components\Fieldset::make('cts_theory_exam')
                    ->label('CTS Theory Exam')
                    ->schema(function (WaitingListAccount $record) {
                        return [
                            Forms\Components\Toggle::make('cts_theory_exam')
                                ->label('Passed')
                                ->afterStateHydrated(fn ($component, $state) => $component->state((bool) $record->theory_exam_passed))
                                ->disabled(),
                        ];
                    })
                    ->visible(fn ($record) => $record->waitingList->feature_toggles['check_cts_theory_exam'] ?? true),

                Forms\Components\Fieldset::make('manual_flags')
                    ->label('Manual Flags')
                    ->schema(function (WaitingListAccount $record) {
                        return $record->flags->filter(fn ($flag) => $flag->position_group_id == null)->map(function ($flag) {
                            return Forms\Components\Toggle::make('flags.'.$flag->id)
                                ->label($flag->name)
                                ->afterStateHydrated(fn ($component, $state) => $component->state((bool) $flag->pivot->value));
                        })->all();
                    })
                    ->visible(fn (WaitingListAccount $record) => $record->flags->isNotEmpty()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['account', 'account.roster', 'waitingList', 'flags']))
            ->columns([
                Tables\Columns\TextColumn::make('position')->getStateUsing(fn (WaitingListAccount $record) => $this->ownerRecord->positionOf($record) ?? '-')->label('Position'),
                Tables\Columns\TextColumn::make('account_id')->label('CID')->searchable(),
                Tables\Columns\TextColumn::make('account.name')->label('Name')->searchable(['name_first', 'name_last']),
                Tables\Columns\IconColumn::make('on_roster')->boolean()->label('On Roster')->getStateUsing(fn (WaitingListAccount $record) => $record->account->onRoster())->visible(fn () => $this->ownerRecord->feature_toggles['display_on_roster'] ?? true),
                Tables\Columns\TextColumn::make('created_at')->label('Added On')->dateTime('d/m/Y H:i:s'),
                Tables\Columns\IconColumn::make('cts_theory_exam')->boolean()->label('CTS Theory Exam')->getStateUsing(fn (WaitingListAccount $record) => $record->theory_exam_passed)->visible(fn () => $this->ownerRecord->feature_toggles['check_cts_theory_exam'] ?? true),
                ...$this->getFlagColumns(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->using(function (WaitingListAccount $record, $data, $livewire) {
                        $record->update([
                            'notes' => $data['notes'],
                        ]);

                        $flagsById = collect(Arr::get($data, 'flags', []));
                        // only update manual flags
                        $flagsToUpdate = $record->flags->filter(fn ($flag) => $flag->position_group_id == null);
                        $flagsToUpdate->each(fn ($flag) => $flagsById->get($flag->id) ? $flag->pivot->mark() : $flag->pivot->unMark());

                        $record->flags()->sync(
                            $flagsById->mapWithKeys(fn ($value, $key) => [$key => ['marked_at' => $value ? now() : null]])->all(),
                        );

                        $livewire->dispatch('refreshWaitingList');

                        return $record;
                    })
                    ->visible(fn ($record) => $this->can('updateAccounts', $record->waitingList)),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\DetachAction::make('detachWithReason')
                    ->label('Remove')
                    ->form([
                        Forms\Components\Select::make('reason_type')
                            ->label('Reason for removal')
                            ->options(self::removalReasonOptions())
                            ->required()
                            ->reactive(),

                        Forms\Components\Textarea::make('custom_reason')
                            ->label('Custom reason')
                            ->rows(3)
                            ->required()
                            ->visible(fn (callable $get) => $get('reason_type') === 'other'),
                    ])
                    ->action(function (WaitingListAccount $record, array $data, $livewire) {
                        $removalType = $data['reason_type'];

                        $removal = new WaitingList\Removal(WaitingList\RemovalReason::from($removalType), auth()->user()->id, $data['custom_reason'] ?? '');

                        $livewire->ownerRecord->removeFromWaitingList($record->account, $removal);
                        $livewire->dispatch('refreshWaitingList');
                    })
                    ->successNotificationTitle('User removed from waiting list')
                    ->modalHeading('Remove from Waiting List')
                    ->modalDescription('Please provide a reason for removing this user.')
                    ->modalSubmitActionLabel('Remove')
                    ->modalCancelActionLabel('Cancel')
                    ->visible(fn ($record) => $this->can('removeAccounts', $record->waitingList)),
            ])
            ->defaultSort('created_at', 'asc')
            ->persistSearchInSession()
            ->paginated(['25', '50', '100'])
            ->defaultPaginationPageOption(25);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('view', $ownerRecord);
    }

    protected function canView(Model $record): bool
    {
        return true;
    }

    protected function canEdit(Model $record): bool
    {
        return $this->can('updateAccounts', $this->getOwnerRecord());
    }

    protected function canDetach(Model $record): bool
    {
        return $this->can('removeAccount', $this->getOwnerRecord());
    }

    public static function removalReasonOptions(): array
    {
        return WaitingList\RemovalReason::formOptions();
    }

    // Display All Manual Flags where display option is enabled
    protected function getFlagColumns(): array
    {
        return $this->ownerRecord->flags()
            ->where('display_in_table', true)
            ->get()
            ->map(function ($flag) {
                return Tables\Columns\IconColumn::make("flag_{$flag->id}")
                    ->label($flag->name)
                    ->boolean()
                    ->getStateUsing(function (WaitingListAccount $record) use ($flag) {
                        $flagRecord = $record->flags->firstWhere('id', $flag->id);

                        return $flagRecord?->pivot?->marked_at !== null;
                    });
            })->all();
    }
}
