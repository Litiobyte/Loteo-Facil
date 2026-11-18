<nav class="lf-import-preview-pages" aria-label="Paginación de registros">
    <button
        type="button"
        aria-label="Página anterior"
        @if ($paginator->onFirstPage())
            disabled aria-disabled="true"
        @else
            wire:click="previousPage('{{ $paginator->getPageName() }}')"
            wire:loading.attr="disabled"
        @endif
    ><span aria-hidden="true">‹</span></button>

    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="lf-import-preview-page-gap" aria-hidden="true">{{ $element }}</span>
        @else
            @foreach ($element as $page => $url)
                <button
                    type="button"
                    wire:key="import-preview-{{ $paginator->getPageName() }}-page-{{ $page }}"
                    aria-label="Página {{ $page }}"
                    @if ($page === $paginator->currentPage())
                        aria-current="page"
                    @else
                        wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                        wire:loading.attr="disabled"
                    @endif
                >{{ $page }}</button>
            @endforeach
        @endif
    @endforeach

    <button
        type="button"
        aria-label="Página siguiente"
        @if ($paginator->hasMorePages())
            wire:click="nextPage('{{ $paginator->getPageName() }}')"
            wire:loading.attr="disabled"
        @else
            disabled aria-disabled="true"
        @endif
    ><span aria-hidden="true">›</span></button>
</nav>
