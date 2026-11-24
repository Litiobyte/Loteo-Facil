<x-filament-panels::page>
    @include('filament.owner.pages.partials.mis-pagos-detail')
    <x-filament::section heading="Comprobantes">
        @livewire('document-manager', ['entity' => $payment, 'moduleKey' => 'finanzas'], key('collection-documents-'.$payment->id))
    </x-filament::section>
</x-filament-panels::page>
