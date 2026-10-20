<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-900">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'HPanel') }}</title>

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-slate-100 antialiased bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="text-center">
            <h1 class="text-3xl font-extrabold tracking-tight text-white flex items-center justify-center gap-2">
                <span class="inline-block w-8 h-8 rounded-lg bg-indigo-600 text-white font-black text-xl leading-8">H</span>
                {{ config('app.name', 'HPanel') }}
            </h1>
            <p class="mt-2 text-sm text-slate-400">cPanel-Style Cloud Hosting Management</p>
        </div>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
        <div class="bg-slate-900 border border-slate-800 py-8 px-6 shadow-xl rounded-2xl sm:px-10">
            {{ $slot }}
        </div>
    </div>

    @livewireScripts
</body>
</html>

