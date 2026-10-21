<x-app-layout>
    <x-slot:title>{{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:accounts.account-detail :account="$account" />
</x-app-layout>

