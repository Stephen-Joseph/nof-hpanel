<x-app-layout>
    <x-slot:title>Logs &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:logs.log-viewer :account="$account" />
</x-app-layout>

