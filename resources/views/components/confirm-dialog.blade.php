@props([
    'name',
    'title' => 'Confirm Action',
    'description' => 'Are you sure you want to proceed? This action cannot be undone.',
    'confirmText' => 'Confirm',
    'cancelText' => 'Cancel',
    'confirmAction' => null,
])

<x-modal :name="$name" maxWidth="md">
    <div class="sm:flex sm:items-start gap-4">
        <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-rose-100 dark:bg-rose-500/10 sm:mx-0 sm:h-10 sm:w-10">
            <svg class="h-6 w-6 text-rose-600 dark:text-rose-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
        </div>
        <div class="mt-3 text-center sm:mt-0 sm:text-left">
            <h3 class="text-base font-semibold leading-6 text-slate-900 dark:text-white" id="modal-title">
                {{ $title }}
            </h3>
            <div class="mt-2">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $description }}
                </p>
                {{ $slot }}
            </div>
        </div>
    </div>

    <x-slot:footer>
        <x-button variant="secondary" x-on:click="show = false">
            {{ $cancelText }}
        </x-button>
        <x-button
            variant="danger"
            @if($confirmAction) wire:click="{{ $confirmAction }}" @endif
            x-on:click="show = false"
        >
            {{ $confirmText }}
        </x-button>
    </x-slot:footer>
</x-modal>

