import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        'node_modules/preline/dist/*.js',
    ],

    theme: {
        borderRadius: {
            none: '0px',
            sm: '0.125rem',
            DEFAULT: '0.125rem',
            md: '0.125rem',
            lg: '0.125rem',
            xl: '0.125rem',
            '2xl': '0.125rem',
            '3xl': '0.125rem',
            full: '0.125rem',
        },
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [
        forms,
        require('preline/plugin'),
    ],
};
