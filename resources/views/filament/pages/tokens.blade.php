<x-filament-panels::page>
    @if ($plainTextToken)
        <x-filament::section>
            <x-slot name="heading">New token created</x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Copy this token now — it will not be shown again.
            </p>

            <div class="mt-3 flex items-center gap-3">
                <code class="flex-1 break-all rounded-md bg-gray-100 px-3 py-2 font-mono text-sm dark:bg-gray-800" wire:key="plain-text-token">
                    {{ $plainTextToken }}
                </code>

                <x-filament::button color="gray" size="sm" wire:click="dismissPlainTextToken">
                    Dismiss
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
