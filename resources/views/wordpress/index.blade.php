<x-app-layout>
    <x-slot:title>WordPress Manager &middot; {{ $account->primary_domain }} &middot; {{ config('app.name', 'HPanel') }}</x-slot:title>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <livewire:word-press.word-press-manager :account="$account" />
    </div>
</x-app-layout>

