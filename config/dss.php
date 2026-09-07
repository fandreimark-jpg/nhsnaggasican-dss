<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transmutation fallback scheme
    |--------------------------------------------------------------------------
    |
    | TASK 1 of "Naggasican DSS: unblock verification" — when a grade's real
    | scheme (see App\Services\TransmutationService::schemeFor()) has no band
    | that matches a particular computed grade, TransmutationService normally
    | reports the grade as unavailable rather than fabricate a transmuted
    | value (see CLAUDE.md's "Known limitations": DO 015, s. 2026's table is
    | only seeded at two anchor points, so almost nothing matches yet).
    |
    | Naming ANOTHER scheme here (e.g. 'do8_2015') tells TransmutationService
    | to use that scheme's bands instead whenever the real scheme has no
    | match, and to mark the resulting grade PROVISIONAL everywhere it is
    | shown (assessment table, Principal Students page, grades screen, and a
    | non-dismissible dashboard banner). Leave this null — the default — to
    | keep today's behavior exactly: no fallback, an honest "Not available."
    |
    | A fresh install must fail loudly, not silently fall back — see
    | .env.example, which pins DSS_TRANSMUTATION_FALLBACK_SCHEME to null.
    |
    */
    'transmutation_fallback_scheme' => env('DSS_TRANSMUTATION_FALLBACK_SCHEME'),

];
