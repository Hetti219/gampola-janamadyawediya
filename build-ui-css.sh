#!/usr/bin/env bash
# Rebuild the compiled Tailwind stylesheet for the theme.
# Run after changing any Tailwind classes in the theme's PHP templates
# or in assets/src/input.css. Requires Node.js on the host.
#
# Usage: ./build-ui-css.sh

set -euo pipefail

cd "$(dirname "$0")"

npx -y tailwindcss@3.4.19 \
  -c theme/gampola-janamadyawediya/tailwind.config.js \
  -i theme/gampola-janamadyawediya/assets/src/input.css \
  -o theme/gampola-janamadyawediya/assets/dist/ui.css \
  --minify

echo "Built theme/gampola-janamadyawediya/assets/dist/ui.css"
