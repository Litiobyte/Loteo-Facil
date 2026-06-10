<?php

namespace App\Filament\Admin\Resources\AccountingPeriods;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\CreateAccountingPeriod;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\EditAccountingPeriod;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Admin\Resources\AccountingPeriods\Schemas\AccountingPeriodForm;
use App\Filament\Admin\Resources\AccountingPeriods\Tables\AccountingPeriodsTable;
use App\Models\AccountingPeriod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AccountingPeriodResource extends Resource
{
    protected static ?string $model = AccountingPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Cierres contables';

    protected static ?string $modelLabel = 'Cierre contable';

    protected static ?string $pluralModelLabel = 'Cierres contables';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return AccountingPeriodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingPeriodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess()
            && $record instanceof AccountingPeriod
            && $record->status === AccountingPeriodStatus::Open;
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingPeriods::route('/'),
            'create' => CreateAccountingPeriod::route('/create'),
            'edit' => EditAccountingPeriod::route('/{record}/edit'),
        ];
    }
}
