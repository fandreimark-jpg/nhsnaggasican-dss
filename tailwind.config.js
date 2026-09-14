import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // 'brand' = the school's green, sampled directly from the pixels
            // of public/images/nagga-logo.png (the seal's ring color), then
            // lightened/softened so it's comfortable to look at on a
            // dashboard used for long stretches — not a generic Tailwind green.
            //
            // 'gold' = a small accent color from the torch/sun in the logo.
            // Used sparingly (e.g. the active sidebar item's left border) —
            // never as a large background, so it stays a highlight, not wallpaper.
            colors: {
                // "UI modernization pass" (2026-09-14) — the school green is
                // now anchored on the design brief's #1F6B2A (800, primary)
                // and #3FAE4D (500, accent). Same role split as before:
                // 800/900 for the sidebar and primary buttons, 500/600 for
                // accents, 50-200 for soft tints. Green is IDENTITY only;
                // statuses use the semantic tokens below.
                brand: {
                    50:  '#f2f9f3',
                    100: '#e3f3e5',
                    200: '#c6e6cb',
                    300: '#9ad4a3',
                    400: '#6cc078',
                    500: '#3FAE4D', // accent
                    600: '#2f9440',
                    700: '#27803a',
                    800: '#1F6B2A', // primary — sidebar, primary buttons
                    900: '#17501f',
                    950: '#0e3414',
                },
                gold: {
                    400: '#e6d574',
                    500: '#e4cc43', // accent — used only for small highlights
                    600: '#d0b519',
                },
                // Neutral system tokens from the design brief.
                ink:     '#18212F', // dark text
                muted:   '#667085', // secondary text
                surface: '#F5F7FA', // page background
                line:    '#E6EAF0', // borders / dividers
                // Semantic tokens — statuses, alerts, badges. Never used as
                // decoration.
                success: { DEFAULT: '#16A34A', soft: '#DCFCE7', text: '#166534' },
                warning: { DEFAULT: '#F59E0B', soft: '#FEF3C7', text: '#92400E' },
                danger:  { DEFAULT: '#DC2626', soft: '#FEE2E2', text: '#991B1B' },
                info:    { DEFAULT: '#2563EB', soft: '#DBEAFE', text: '#1E40AF' },
                // Status colours carry MEANING in this system and are used only for
                // the four DSS states. Named semantically so that "what colour is At
                // Risk" is answered in one place, and so a plain count can never
                // accidentally borrow a status hue.
                status: {
                    ontrack:   '#1F6B2A', // = brand-800, the institutional green
                    attention: '#b45309', // amber-700 — readable on white, unlike amber-500
                    risk:      '#b91c1c', // red-700
                    failing:   '#7f1d1d', // red-900 — an outcome, graver than At Risk
                },
                // COUNT — cool family, deliberately disjoint from the warm
                // status.* hues above so a plain master-data count can never
                // be mistaken for a DSS status at a glance. Eight hues so
                // eight cards (the Admin dashboard's master-data grid) stay
                // visually distinguishable from each other.
                count: {
                    1: '#0284c7', 2: '#2563eb', 3: '#4f46e5', 4: '#7c3aed',
                    5: '#9333ea', 6: '#0891b2', 7: '#0d9488', 8: '#475569',
                },
            },
            boxShadow: {
                card: '0 1px 2px rgba(16, 24, 40, 0.04), 0 1px 3px rgba(16, 24, 40, 0.06)',
                'card-hover': '0 4px 6px -1px rgba(16, 24, 40, 0.08), 0 10px 15px -3px rgba(16, 24, 40, 0.08)',
                modal: '0 20px 25px -5px rgba(16, 24, 40, 0.15), 0 10px 10px -5px rgba(16, 24, 40, 0.06)',
            },
        },
    },

    // <x-stat-card>'s accent prop builds its border-{accent} class at
    // runtime (resources/views/components/stat-card.blade.php), so
    // Tailwind's static content scanner never sees the literal class name
    // and would otherwise purge it.
    safelist: [
        { pattern: /border-(count|status)-(1|2|3|4|5|6|7|8|ontrack|attention|risk|failing)/ },
        { pattern: /(bg|text)-(count|status)-(1|2|3|4|5|6|7|8|ontrack|attention|risk|failing)/ },
        { pattern: /(bg|text)-(success|warning|danger|info)(-soft|-text)?/ },
    ],

    plugins: [forms],
};