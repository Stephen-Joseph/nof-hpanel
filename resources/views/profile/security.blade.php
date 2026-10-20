<x-app-layout>
    <x-slot:title>Security &amp; Sessions &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-white tracking-tight">Security &amp; Sessions</h1>
        <p class="text-slate-400 mt-1">Configure two-factor authentication and manage active device sessions.</p>
    </div>

    <div class="space-y-8">
        <livewire:auth.two-factor-settings />

        <livewire:auth.session-manager />
    </div>
</x-app-layout>

