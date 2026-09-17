{{--
    Shared shell for the HTTP error pages (403 / 404 / 419 / 500 / 503).

    Deliberately NOT layouts.app: that layout reads auth()->user() for the
    sidebar and greeting, and an error page has to render for a guest, for
    an expired session (419), and while the application itself is failing
    (500). This shell needs nothing but the compiled assets and the same
    favicon/logo as every other page, so a user is never shown a raw
    Symfony/Whoops screen, a stack trace, or a bare "Server Error" line.

    Each page provides: $code, $title, $message. The one navigation
    action is chosen here so it is the same on every error page — back to
    the role dashboard when signed in, otherwise to the sign-in page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $code }} {{ $title }} — Naggasican NHS DSS</title>
    <x-app-favicon />
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-surface flex items-center justify-center px-4 py-8 font-sans text-ink antialiased">
    <main class="w-full max-w-md text-center" role="main">
        <div class="card px-8 py-10">
            <img src="{{ asset('images/nagga-logo.png') }}" alt="Naggasican National High School" class="w-20 h-20 object-contain mx-auto mb-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-700">Naggasican NHS Decision Support System</p>
            <p class="mt-4 text-5xl font-bold text-gray-300 tabular-nums" aria-hidden="true">{{ $code }}</p>
            <h1 class="mt-2 text-xl font-bold text-ink">{{ $title }}</h1>
            <p class="mt-2 text-sm text-muted leading-relaxed">{{ $message }}</p>
            <div class="mt-6 flex flex-col sm:flex-row gap-2 justify-center">
                @if(isset($signedIn) ? $signedIn : auth()->check())
                    <a href="{{ route('dashboard') }}" class="btn btn-primary"><i class="bi bi-speedometer2 mr-1" aria-hidden="true"></i>Return to dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary"><i class="bi bi-box-arrow-in-right mr-1" aria-hidden="true"></i>Go to sign in</a>
                @endif
                @if(($code ?? null) === 404 || ($code ?? null) === 403)
                    <a href="javascript:history.back()" class="btn btn-outline">Go back</a>
                @endif
            </div>
        </div>
        <p class="mt-4 text-xs text-gray-400">Naggasican National High School · Authorized personnel only</p>
    </main>
</body>
</html>
