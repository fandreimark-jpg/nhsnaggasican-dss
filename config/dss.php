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

    /*
    |--------------------------------------------------------------------------
    | Password for accounts created by `php artisan db:seed`
    |--------------------------------------------------------------------------
    |
    | Final release pass (2026-09-27). Database\Seeders\DatabaseSeeder uses
    | this ONLY for accounts it has to create; existing accounts are never
    | touched. Unset in production => the seeder refuses (no known default
    | password can ever reach a deployed database). Unset locally => a
    | one-time random password is generated and shown on the console.
    | Minimum 12 characters. Never commit a value.
    |
    */
    'seed_account_password' => env('SEED_ACCOUNT_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Enabled academic monitoring rules
    |--------------------------------------------------------------------------
    |
    | "Rule-based academic monitoring" Phase 2B — the ONLY rules
    | App\Services\AcademicMonitoringService runs. Each must implement
    | App\Monitoring\MonitoringRule and must be school-approved.
    |
    | Neither enabled rule introduces a new threshold:
    |   EXPLICIT_MISSING_REQUIREMENT   — the adviser marked an item Missing.
    |   OFFICIAL_FAILING_SUBJECT_GRADE — a subject's OFFICIAL grade is
    |       Failing by the app's existing rule (Grade::scopeFailing(),
    |       DepEd's failing mark). Evaluated per subject, so the overall
    |       average never hides it ("Subject Performance Concern").
    | Attendance was RETIRED (2026-09-27): no approved basis for using it.
    | Its stored rows are history only (AcademicConcern::scopeActiveRules()).
    | An in-term (ongoing) numeric trigger, a count of Missing items, a
    | decline or an attendance figure must not be added here until the
    | school has approved it. Deliberately not an env setting: enabling a
    | rule is a reviewed code change, not a deployment toggle.
    |
    */
    'monitoring_rules' => [
        \App\Monitoring\Rules\MissingRequirementRule::class,
        \App\Monitoring\Rules\OfficialFailingSubjectRule::class,
    ],

];
