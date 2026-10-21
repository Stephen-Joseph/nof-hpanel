<x-app-layout>
    <x-slot:title>File Manager &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:files.file-manager :account="$account" />
</x-app-layout>

