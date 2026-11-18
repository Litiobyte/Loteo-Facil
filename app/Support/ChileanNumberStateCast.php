<?php

namespace App\Support;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

class ChileanNumberStateCast implements StateCast
{
    public function get(mixed $state): ?string
    {
        return ChileanNumber::normalize($state);
    }

    public function set(mixed $state): ?string
    {
        return ChileanNumber::format($state);
    }
}
