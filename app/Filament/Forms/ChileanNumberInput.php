<?php

namespace App\Filament\Forms;

use App\Support\ChileanNumber;
use App\Support\ChileanNumberStateCast;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Model;

class ChileanNumberInput extends TextInput
{
    protected bool $isSurface = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inputMode('decimal')
            ->stateCast(new ChileanNumberStateCast)
            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $state === null || $state === '' || preg_match('/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d+)?$/D', (string) $state)
                ? ChileanNumber::normalize($state)
                : 'Formato inválido')
            ->mask(fn (): ?RawJs => $this->preservesHistoricalAmount() ? null : RawJs::make('/^-?[\\d.]*$/.test($input) ? $money($input, ",", ".", 0) : null'))
            ->readOnly(fn (): bool => $this->preservesHistoricalAmount())
            ->hint(fn (): ?string => $this->preservesHistoricalAmount() ? 'Importe histórico: solo lectura.' : null)
            ->dehydrateStateUsing(fn (mixed $state): mixed => $this->preservesHistoricalAmount()
                ? $this->getRecord()->getAttribute($this->getName())
                : $state)
            ->rules(fn (): array => $this->preservesHistoricalAmount()
                ? ['numeric']
                : ($this->isSurface
                    ? ['regex:/^-?\d+(?:\.\d{1,4})?$/D', 'numeric']
                    : ['regex:/^-?\d+$/D', 'numeric']))
            ->validationMessages(['regex' => 'Ingrese un monto entero, sin decimales.']);
    }

    public function surface(): static
    {
        $this->isSurface = true;

        return $this
            ->mask(RawJs::make('/^-?[\\d.]*(?:,\\d{0,4})?$/.test($input) ? $money($input, ",", ".", 4) : null'))
            ->validationMessages(['regex' => 'Ingrese una superficie válida, con hasta 4 decimales.']);
    }

    protected function preservesHistoricalAmount(): bool
    {
        $record = $this->getRecord();

        return ! $this->isSurface
            && $record instanceof Model
            && $this->getContainer()->getOperation() === 'edit'
            && preg_match('/\.\d*[1-9]\d*$/D', (string) $record->getAttribute($this->getName())) === 1;
    }
}
