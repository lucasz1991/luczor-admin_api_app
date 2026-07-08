import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                luczor: {
                    bg: '#050b12',
                    panel: '#0b1520',
                    cyan: '#22d3ee',
                    blue: '#38bdf8',
                    text: '#e6f6ff',
                },
            },
        },
    },
    plugins: [forms],
};
