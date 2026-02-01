import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

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
            spacing: {
                '4.5': '1.125rem',
                '7.5': '1.875rem',
                '8.5': '2.125rem',
                '9.5': '2.375rem',
                '12.5': '3.125rem',
                '62.5': '15.625rem',
                '75': '18.75rem',
            },
            zIndex: {
                '1': '1',
                '8': '8',
                '9': '9',
                '60': '60',
                '80': '80',
                '81': '81',
            },
            boxShadow: {
                '2xs': '0 1px 2px rgba(15, 23, 42, 0.06)',
            },
            colors: {
                background: 'var(--app-background)',
                foreground: 'var(--app-foreground)',
                layer: 'var(--app-layer)',
                'layer-line': 'var(--app-layer-line)',
                'layer-foreground': 'var(--app-layer-foreground)',
                'layer-hover': 'var(--app-layer-hover)',
                'layer-focus': 'var(--app-layer-focus)',
                surface: 'var(--app-surface)',
                'surface-1': 'var(--app-surface-1)',
                'surface-2': 'var(--app-surface-2)',
                'surface-hover': 'var(--app-surface-hover)',
                'surface-focus': 'var(--app-surface-focus)',
                'surface-foreground': 'var(--app-surface-foreground)',
                sidebar: 'var(--app-sidebar)',
                'sidebar-2': 'var(--app-sidebar-2)',
                'sidebar-line': 'var(--app-sidebar-line)',
                'sidebar-divider': 'var(--app-sidebar-divider)',
                'sidebar-2-divider': 'var(--app-sidebar-2-divider)',
                'line-1': 'var(--app-line-1)',
                'line-2': 'var(--app-line-2)',
                'line-3': 'var(--app-line-3)',
                'line-6': 'var(--app-line-6)',
                primary: 'var(--app-primary)',
                'primary-hover': 'var(--app-primary-hover)',
                'primary-focus': 'var(--app-primary-focus)',
                'primary-line': 'var(--app-primary-line)',
                'primary-foreground': 'var(--app-primary-foreground)',
                'primary-checked': 'var(--app-primary-checked)',
                'muted-foreground': 'var(--app-muted-foreground)',
                'muted-foreground-1': 'var(--app-muted-foreground-1)',
                'muted-foreground-2': 'var(--app-muted-foreground-2)',
                dropdown: 'var(--app-dropdown)',
                'dropdown-line': 'var(--app-dropdown-line)',
                'dropdown-item-hover': 'var(--app-dropdown-item-hover)',
                'dropdown-item-focus': 'var(--app-dropdown-item-focus)',
                'dropdown-item-foreground': 'var(--app-dropdown-item-foreground)',
                select: 'var(--app-select)',
                'select-line': 'var(--app-select-line)',
                'select-item-active': 'var(--app-select-item-active)',
                'select-item-hover': 'var(--app-select-item-hover)',
                'select-item-focus': 'var(--app-select-item-focus)',
                'select-item-foreground': 'var(--app-select-item-foreground)',
                tooltip: 'var(--app-tooltip)',
                'tooltip-line': 'var(--app-tooltip-line)',
                'tooltip-foreground': 'var(--app-tooltip-foreground)',
                overlay: 'var(--app-overlay)',
                'overlay-line': 'var(--app-overlay-line)',
                'overlay-divider': 'var(--app-overlay-divider)',
                'overlay-footer': 'var(--app-overlay-footer)',
                'scrollbar-track': 'var(--app-scrollbar-track)',
                'scrollbar-thumb': 'var(--app-scrollbar-thumb)',
                destructive: 'var(--app-destructive)',
                'destructive-50': 'var(--app-destructive-50)',
            },
        },
    },

    plugins: [
        forms,
        require('preline/plugin'),
        plugin(({ matchUtilities, theme }) => {
            matchUtilities(
                {
                    size: (value) => ({
                        width: value,
                        height: value,
                    }),
                },
                { values: theme('spacing') }
            );
        }),
    ],
};
