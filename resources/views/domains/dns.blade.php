<x-app-layout>
    <x-slot:title>DNS Records &middot; {{ $domain->domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:domains.dns-helper :domain="$domain" />
</x-app-layout>

