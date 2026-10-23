<x-app-layout>
    <x-slot:title>System Resource Quotas &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <livewire:admin.usage-overview />
    </div>
</x-app-layout>

