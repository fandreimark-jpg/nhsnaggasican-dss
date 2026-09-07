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
                brand: {
                    50:  '#f8fbf7',
                    100: '#eef7ed',
                    200: '#dbeed7',
                    300: '#b9dfb2',
                    400: '#93d288',
                    500: '#70c661',
                    600: '#52b840',
                    700: '#45a035',
                    800: '#3b892d', // primary — used for sidebar bg, buttons
                    900: '#2c6722',
                },
                gold: {
                    400: '#e6d574',
                    500: '#e4cc43', // accent — used only for small highlights
                    600: '#d0b519',
                },
                // Status colours carry MEANING in this system and are used only for
                // the four DSS states. Named semantically so that "what colour is At
                // Risk" is answered in one place, and so a plain count can never
                // accidentally borrow a status hue.
                status: {
                    ontrack:   '#3b892d', // = brand-800, the institutional green
                    attention: '#b45309', // amber-700 — readable on white, unlike amber-500
                    risk:      '#b91c1c', // red-700
                    failing:   '#7f1d1d', // red-900 — an outcome, graver than At Risk
                },
            },
        },
    },

    plugins: [forms],
};