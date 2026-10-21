<x-app-layout>
    <x-slot:title>PHP Manager &middot; {{ $domain->domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:domains.php-manager :domain="$domain" />
</x-app-layout>

