<x-app-layout>
    <x-slot:title>SSL Certificates &middot; {{ $domain->domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:domains.domain-ssl :domain="$domain" />
</x-app-layout>

