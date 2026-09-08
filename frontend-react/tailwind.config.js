/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // ── MUWASCO "Blue Ocean" primary palette ─────────────────────
        // Sky-tinted highlights (50–300), vivid sea (400–600),
        // deep ocean navy (700–950). Used for all brand elements.
        ocean: {
          50: '#eff9ff',
          100: '#def2ff',
          200: '#b8e8ff',
          300: '#7ad8ff',
          400: '#3cc0f9',
          500: '#14a3e8',
          600: '#0684c9',
          700: '#086aa2',
          800: '#0d5a86',
          900: '#104c70',
          950: '#0a3049',
        },
        // ── Aqua accent (secondary) — shallows, positive states ──────
        aqua: {
          50: '#effcf9',
          100: '#c8f7ec',
          200: '#91edda',
          300: '#58dcc6',
          400: '#2ac3ac',
          500: '#12a892',
          600: '#098777',
          700: '#0a6b61',
          800: '#0c554e',
          900: '#0d4741',
          950: '#022926',
        },
        // ── Deep water neutrals — sidebar / header surfaces ─────────
        deep: {
          50: '#f1f6f9',
          100: '#e2ecf3',
          200: '#c2d5e3',
          300: '#95b4cc',
          400: '#6491b1',
          500: '#447497',
          600: '#355d7d',
          700: '#2c4b66',
          800: '#284055',
          900: '#253748',
          950: '#182430',
        },
        // Remap Tailwind's default `blue` to the ocean scale so every
        // existing blue-* class across all pages automatically renders in
        // MUWASCO ocean tones — no need to touch every file.
        blue: {
          50: '#eff9ff',
          100: '#def2ff',
          200: '#b8e8ff',
          300: '#7ad8ff',
          400: '#3cc0f9',
          500: '#14a3e8',
          600: '#0684c9',
          700: '#086aa2',
          800: '#0d5a86',
          900: '#104c70',
          950: '#0a3049',
        },
      },
    },
  },
  plugins: [],
}
