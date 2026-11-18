<?php

namespace App\Support;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

class AdminTableColumns
{
    /**
     * @param  array<Column>  $columns
     * @return array<Column>
     */
    public static function contentSized(array $columns): array
    {
        foreach ($columns as $column) {
            $column
                ->width('fit-content')
                ->wrapHeader()
                ->extraCellAttributes([
                    'style' => 'width: fit-content; max-width: 15.625rem; overflow-wrap: anywhere;',
                ], merge: true);

            if ($column instanceof TextColumn) {
                $column->wrap();
            }
        }

        return $columns;
    }
}
