<x-app-layout>
    <x-slot:title>Backups &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:backups.backup-manager :account="$account" />
</x-app-layout>

