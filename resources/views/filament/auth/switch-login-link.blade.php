<div class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">
    <span>{{ $label }}</span>

    <x-filament::link :href="route($routeName)" wire:navigate>
        {{ $linkText }}
    </x-filament::link>
</div>
