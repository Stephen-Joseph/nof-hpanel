<x-app-layout>
    <x-slot:title>Edit Server: {{ $server->name }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:servers.server-form :server="$server" />
</x-app-layout>

