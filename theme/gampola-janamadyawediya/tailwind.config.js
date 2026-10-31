/**
 * Tailwind v3 build config for the compiled theme stylesheet.
 *
 * Build from the repo root:
 *   npx -y tailwindcss@3 -c theme/gampola-janamadyawediya/tailwind.config.js \
 *     -i theme/gampola-janamadyawediya/assets/src/input.css \
 *     -o theme/gampola-janamadyawediya/assets/dist/ui.css --minify
 *
 * preflight is disabled: a global CSS reset would destroy GeneratePress
 * and the child theme's own styles for posts, pages and the footer. The
 * new components are self-styled via utilities + the base layer in
 * assets/src/input.css.
 */

module.exports = {
  content: [
    './theme/gampola-janamadyawediya/**/*.php',
  ],

  corePlugins: {
    preflight: false,
  },
  darkMode: ['selector', '[data-theme="dark"]'],

  theme: {
    extend: {
       fontFamily: {
        // Geist Sans as primary, fallback to Noto and native Sinhala fonts
        sans: [
          '"Geist Sans"',
          '"Noto Sans Sinhala"',
          '"Nirmala UI"',
          '"Sinhala Sangam MN"',
          '"Sinhala MN"',
          '"Iskoola Pota"',
          '"LKLUG"',
          'ui-sans-serif',
          'system-ui',
          'sans-serif',
        ],
        // Doto for Nothing OS 5.0 dot-matrix effect
        dot: [
          '"Doto"',
          'monospace',
        ],
      },
      colors: {
        // Wada palette
        'wada': {
          // Dark mode
          indigo:    '#000831',   // Background
          violet:    '#06004f',   // Surface / Cards
          'violet-up': '#0e0066', // Elevated surface (hover)
          red:       '#f20000',   // Primary accent
          orange:    '#ff5200',   // Secondary accent
          seashell:  '#ffcfc4',   // Typography / Icons

          // Light mode
          buff:      '#ebd999',   // Background
          yellow:    '#f5f5b8',   // Surface / Cards
          blue:      '#008aa1',   // Primary accent
          english:   '#de4500',   // Secondary accent
          slate:     '#0f261f',   // Typography / Icons
        },
      },
    },
  },
};
