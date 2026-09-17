{{--
    The ONE place the browser-tab icon is declared. Every layout — the
    authenticated app shell, the guest (password-reset) shell, the login
    page, and the error pages — includes this component instead of
    carrying its own <link rel="icon">, so the tab icon can never drift
    between pages again (before this component, only the login page had
    an icon and public/favicon.ico was a zero-byte placeholder).

    All three files are derived from public/images/nagga-logo.png; the
    32px PNG is what the tab actually shows, the 192px one is for
    Android/PWA "add to home screen", and apple-touch-icon.png for iOS.
    public/favicon.ico (a 32x32 PNG-in-ICO) covers clients that ignore
    <link rel="icon"> and request /favicon.ico directly.
--}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/favicon-192.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
<link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
