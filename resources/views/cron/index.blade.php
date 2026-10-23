<x-app-layout>
    <x-slot:title>Cron Jobs &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:cron.cron-manager :account="$account" />
</x-app-layout>

