<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — Naggasican NHS DSS</title>
    <link rel="icon" type="image/png" href="{{ asset('images/nagga-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center">

    <div class="w-full max-w-sm">

        {{-- Login Card --}}
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">

            {{-- White Header with Logo --}}
            <div class="px-8 pt-8 pb-6 text-center border-b">
                {{-- School Icon --}}
                <img src="{{ asset('images/nagga-logo.png') }}" 
                    class="w-28 h-28 object-contain mx-auto mb-4" 
                    alt="Naggasican NHS Logo">
                <h1 class="text-gray-800 text-xl font-bold leading-tight">Naggasican NHS</h1>
                <p class="text-gray-500 text-sm mt-1">A Decision Support System</p>
                <p class="text-gray-400 text-xs mt-0.5">for Monitoring Learner's</p>
                <p class="text-gray-400 text-xs mt-0.5">Academic Performance</p>
            </div>

            {{-- Form --}}
            <div class="px-8 py-6">

                {{-- Session Status --}}
                @if(session('status'))
                    <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- Errors --}}
                @if($errors->any())
                    <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf

                    {{-- Email/Username --}}
                    <div>
                        <label for="email" class="block text-sm text-gray-600 mb-1 font-medium">
                            Email
                        </label>
                        <div class="relative">
                            <i class="bi bi-person absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                            <input
                                type="text"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                placeholder="email@naggasican.edu.ph"
                                class="w-full border border-gray-200 rounded-lg pl-9 pr-4 py-2.5 text-sm
                                       focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent
                                       placeholder-gray-300">
                        </div>
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="password" class="block text-sm text-gray-600 mb-1 font-medium">
                            Password
                        </label>
                        <div class="relative">
                            <i class="bi bi-lock absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                placeholder="Enter your password"
                                class="w-full border border-gray-200 rounded-lg pl-9 pr-10 py-2.5 text-sm
                                       focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent
                                       placeholder-gray-300">
                            <button type="button" onclick="togglePassword()"
                                class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                                <i class="bi bi-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Submit --}}
                    <button type="submit"
                        class="w-full bg-brand-700 hover:bg-brand-800 text-white py-2.5 rounded-lg
                               text-sm font-semibold transition">
                        <i class="bi bi-box-arrow-in-right mr-1"></i>Log in
                    </button>
                </form>
            </div>

            {{-- Footer --}}
            <div class="px-8 py-4 bg-gray-50 border-t text-center">
                <p class="text-xs text-gray-400">
                    Naggasican National High School
                </p>
                <p class="text-xs text-gray-300 mt-0.5">Authorized personnel only</p>
            </div>
        </div>

    </div>

    {{-- togglePassword() now lives in resources/js/auth.js --}}

</body>
</html>