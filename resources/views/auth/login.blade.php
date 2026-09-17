<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — Naggasican NHS DSS</title>
    <x-app-favicon />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
</head>
<body class="min-h-screen bg-surface flex items-center justify-center px-4 py-8">

    <div class="w-full max-w-sm">

        {{-- Login Card --}}
        <div class="bg-white rounded-2xl shadow-modal border border-line overflow-hidden">

            {{-- White Header with Logo --}}
            <div class="px-8 pt-8 pb-6 text-center border-b border-line bg-gradient-to-b from-brand-50/60 to-white">
                {{-- School Icon --}}
                <img src="{{ asset('images/nagga-logo.png') }}" 
                    class="w-28 h-28 object-contain mx-auto mb-4" 
                    alt="Naggasican NHS Logo">
                <h1 class="text-ink text-xl font-bold leading-tight">Naggasican NHS</h1>
                <p class="text-gray-500 text-sm mt-1">A Decision Support System</p>
                <p class="text-gray-400 text-xs mt-0.5">for Monitoring Learner's</p>
                <p class="text-gray-400 text-xs mt-0.5">Academic Performance</p>
            </div>

            {{-- Form --}}
            <div class="px-8 py-6">

                {{-- Session Status --}}
                @if(session('status'))
                    <div class="alert alert-success mb-4">
                        {{ session('status') }}
                    </div>
                @endif

                {{-- Errors --}}
                @if($errors->any())
                    <div class="alert alert-danger mb-4" role="alert" aria-live="assertive">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                {{-- data-loading: resources/js/confirm.js disables the submit button
                     and relabels it with this text the moment the form is sent, so a
                     slow network can't produce a double login attempt. --}}
                <form method="POST" action="{{ route('login') }}" class="space-y-4" data-loading="Signing in…">
                    @csrf

                    {{-- Email/Username --}}
                    <div>
                        <label for="email" class="form-label font-medium">
                            Email
                        </label>
                        <div class="relative">
                            <i class="bi bi-person absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                autocomplete="username"
                                inputmode="email"
                                placeholder="Enter your email address"
                                class="w-full border border-line rounded-lg pl-9 pr-4 py-2.5 text-sm
                                       focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent
                                       placeholder-gray-300">
                        </div>
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="password" class="form-label font-medium">
                            Password
                        </label>
                        <div class="relative">
                            <i class="bi bi-lock absolute left-3 top-2.5 text-gray-400 text-sm"></i>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                class="w-full border border-line rounded-lg pl-9 pr-10 py-2.5 text-sm
                                       focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent
                                       placeholder-gray-300">
                            <button type="button" onclick="togglePassword()" id="togglePasswordBtn"
                                aria-label="Show password" aria-pressed="false"
                                class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-brand-400 rounded">
                                <i class="bi bi-eye" id="eyeIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Submit --}}
                    <button type="submit"
                        class="btn btn-primary w-full py-2.5 font-semibold">
                        <i class="bi bi-box-arrow-in-right mr-1"></i>Log in
                    </button>
                </form>
            </div>

            {{-- Footer --}}
            <div class="px-8 py-4 bg-surface border-t border-line text-center">
                <p class="text-xs text-muted">
                    Naggasican National High School
                </p>
                <p class="text-xs text-gray-400 mt-0.5">Authorized personnel only</p>
            </div>
        </div>

    </div>

    {{-- togglePassword() now lives in resources/js/auth.js --}}

</body>
</html>