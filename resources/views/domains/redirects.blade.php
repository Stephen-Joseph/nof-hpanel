<x-app-layout>
    <x-slot:title>Redirects &middot; {{ $domain->domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:domains.domain-redirects :domain="$domain" />
</x-app-layout>

