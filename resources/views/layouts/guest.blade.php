<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Naggasican NHS DSS</title>
        <x-app-favicon />

        {{-- No remote font: the app renders on the local system font stack
             (tailwind.config.js), so guest pages look the same offline. --}}
        <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-surface">
            <div>
                <a href="/" aria-label="Naggasican NHS DSS home">
                    <img src="{{ asset('images/nagga-logo.png') }}" alt="Naggasican National High School" class="w-20 h-20 object-contain">
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 card overflow-hidden">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
