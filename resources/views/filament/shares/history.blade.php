<div>
    @foreach ($movements as $movement)
        <p>{{ $movement->created_at }} — {{ $movement->type }}:
            {{ $movement->quantity_before }} → {{ $movement->quantity_after }}.
            {{ $movement->context['status_before'] ?? '' }} → {{ $movement->context['status_after'] ?? '' }}.
            {{ $movement->reason }} (Administrador #{{ $movement->created_by }})
        </p>
    @endforeach
</div>
