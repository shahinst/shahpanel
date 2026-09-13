/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Vazirmatn', 'Tahoma', 'sans-serif'],
            },
            colors: {
                accent: {
                    DEFAULT: 'var(--color-accent, #4f46e5)',
                    soft: 'var(--accent-soft, #eef2ff)',
                    600: 'var(--accent-600, #4338ca)',
                    700: 'var(--accent-700, #3730a3)',
                },
                surface: {
                    DEFAULT: 'var(--surface, #ffffff)',
                    2: 'var(--surface-2, #f8fafc)',
                    3: 'var(--surface-3, #f1f5f9)',
                },
                ink: {
                    DEFAULT: 'var(--text, #0f172a)',
                    soft: 'var(--text-soft, #334155)',
                    muted: 'var(--muted, #64748b)',
                },
                line: 'var(--border, #e6e9ef)',
            },
            borderRadius: {
                xs: 'var(--radius-xs, 8px)',
                sm: 'var(--radius-sm, 10px)',
                DEFAULT: 'var(--radius, 14px)',
                lg: 'var(--radius-lg, 18px)',
                xl: 'var(--radius-xl, 22px)',
            },
            boxShadow: {
                soft: 'var(--shadow-sm)',
                card: 'var(--shadow)',
                pop: 'var(--shadow-lg)',
            },
        },
    },
    plugins: [],
};
