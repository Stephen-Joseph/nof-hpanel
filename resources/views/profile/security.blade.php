<x-app-layout>
    <x-slot:title>Security &amp; Sessions &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <x-layout.page-header 
        title="Security &amp; Sessions" 
        subtitle="Configure two-factor authentication and manage active device sessions." 
    />

    <div class="space-y-6">
        <livewire:auth.two-factor-settings />

        <livewire:auth.session-manager />
    </div>
</x-app-layout>
