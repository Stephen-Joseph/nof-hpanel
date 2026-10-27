<x-app-layout>
    <x-slot:title>Online SFTP &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:files.online-sftp :account="$account" />
</x-app-layout>

