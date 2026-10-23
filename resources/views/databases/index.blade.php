<x-app-layout>
    <x-slot:title>Databases &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:databases.database-manager :account="$account" />
</x-app-layout>

