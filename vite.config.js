import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/admin.js',
            ],
            refresh: [
                'resources/views/**',
                'routes/**',
            ],
        }),
    ],
})
