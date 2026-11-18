@php
    $logoUrl = \App\Models\CommunitySetting::logoUrl();
@endphp

<span style="display:inline-flex;align-items:center;gap:.75rem;">
    <img src="{{ $logoUrl }}" alt="{{ \App\Models\CommunitySetting::displayName() }}" style="height:3.25rem;width:auto;">
    @if (\Filament\Facades\Filament::getCurrentPanel()?->getId() !== 'owner' || $logoUrl === asset('branding/logo-full-transparent.png'))
        <span style="color:var(--lf-blue,#0b2e57);font-weight:600;font-size:1.05rem;letter-spacing:.01em;">{{ \App\Models\CommunitySetting::displayName() }}</span>
    @endif
</span>
