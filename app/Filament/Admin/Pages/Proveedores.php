<?php

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class Proveedores extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Gastos';

    protected static ?string $navigationLabel = 'Proveedores';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.admin.pages.proveedores';
}
