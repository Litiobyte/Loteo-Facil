<div>
    @foreach ($revisions as $revision)
        <p>{{ $revision->created_at }} — {{ $revision->reason }} (Administrador #{{ $revision->created_by }})</p>
        <pre>{{ $revision->before }} → {{ $revision->after }}</pre>
    @endforeach
</div>
