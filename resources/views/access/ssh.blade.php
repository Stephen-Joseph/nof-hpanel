<x-app-layout>
    <x-slot:title>SSH Keys &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:access.ssh-key-manager :account="$account" />
</x-app-layout>

