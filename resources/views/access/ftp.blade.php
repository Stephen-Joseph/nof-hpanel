<x-app-layout>
    <x-slot:title>FTP &amp; SFTP Accounts &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:access.ftp-manager :account="$account" />
</x-app-layout>

