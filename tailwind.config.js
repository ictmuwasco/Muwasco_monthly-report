/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',                    // legacy root pages during gradual migration
    './resources/views/**/*.php',
    './app/**/*.php',
    './public/assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        // MUWASCO brand palette — tune to logo once approved
        primary: {
          DEFAULT: '#0e7490',
          dark: '#155e75',
          light: '#67e8f9',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
