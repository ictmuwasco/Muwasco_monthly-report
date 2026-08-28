/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',                    // legacy root pages during gradual migration
    './frontend/src/**/*.php',
    './backend/app/**/*.php',
    './backend/bootstrap/**/*.php',
    './public/assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        // MUWASCO brand palette (deep water blues)
        primary: {
          DEFAULT: '#2E6178',
          dark: '#183F52',
          light: '#4E849A',
        },
        accent: '#0F9D8A',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
