<x-app-layout>
    <x-slot:title>Edit Package: {{ $package->name }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <livewire:packages.package-form :package="$package" />
</x-app-layout>

