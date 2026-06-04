<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit">
                Save
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="testProxmox" wire:loading.attr="disabled">
                Test Proxmox
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="testPbs" wire:loading.attr="disabled">
                Test PBS
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="testNtfy" wire:loading.attr="disabled">
                Test ntfy
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
