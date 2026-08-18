/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{html,ts}'],
  theme: {
    extend: {
      fontFamily: {
        // Tajawal reads well in both Arabic and Latin, so one stack serves
        // the whole bilingual UI (NFR-07).
        sans: ['Tajawal', 'Roboto', 'Helvetica Neue', 'sans-serif'],
      },
    },
  },
  plugins: [],
  corePlugins: {
    // Angular Material ships its own normalize; Tailwind's preflight would
    // fight it over form controls and headings.
    preflight: false,
  },
};
