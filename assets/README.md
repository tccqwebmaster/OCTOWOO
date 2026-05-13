# OctoWoo Plugin Assets

This directory contains the WooCommerce.com marketplace listing assets.

## Required files for WC.com submission

| File | Size | Description |
|---|---|---|
| `banner-1544x500.png` | 1544×500px | Plugin banner (high DPI) |
| `banner-772x250.png` | 772×250px | Plugin banner (standard) |
| `icon-256x256.png` | 256×256px | Plugin icon (high DPI) |
| `icon-128x128.png` | 128×128px | Plugin icon (standard) |
| `screenshot-1.png` | Min 1200px wide | Migration tab — entity selection, progress table, ETA |
| `screenshot-2.png` | Min 1200px wide | Settings tab — DB credentials, paths, language IDs |
| `screenshot-3.png` | Min 1200px wide | System Check — validates PHP, memory, extensions |
| `screenshot-4.png` | Min 1200px wide | Logs tab — live log viewer, download button, history |
| `screenshot-5.png` | Min 1200px wide | WP-CLI — progress bar during `wp octowoo migrate` |

## Design notes

- Brand colours: purple `#7952b3` / `#6c4fd4` with white text
- Use the octopus emoji 🐙 as the icon base (flat design, rounded square)
- Background for banner: dark gradient `#1a1035 → #3b2070`
- Font: Inter or system-ui

## Generating screenshots

1. Install OctoWoo on a staging WordPress site with WooCommerce active
2. Connect to the OpenCart demo database (see /tests/fixtures/demo-oc.sql)
3. Take screenshots at 1440px viewport width, crop to the plugin UI area
4. Save as PNG at 2x resolution for retina banner (1544×500)
