# Third-party notices

SOCIAL MEDIA BOOSTER includes, or is built with, the following third-party components. Each keeps its own licence. Only the **first table** ships to a production server; everything else is a build-time or QA tool.

## Shipped with the application

| Component | Where | Licence | Notes |
|---|---|---|---|
| **Alpha UPI PHP SDK** | `app/Vendor/AlphaUpi/` | MIT — © 2026 Alpha UPI Payment Gateway (`app/Vendor/AlphaUpi/LICENSE.txt`) | Bundled **unmodified**; wrapped by our own adapter in `app/Payments/AlphaUpiGateway.php`. |
| **Inter** (variable, Latin + a small ₹ subset) | `public/assets/fonts/` | SIL Open Font License 1.1 (`docs/FONT-LICENSE-Inter.txt`) | © The Inter Project Authors (https://github.com/rsms/inter). Self-hosted; no requests to Google Fonts. |
| **Lucide icons** (a subset, compiled into one SVG sprite) | `public/assets/img/icons.svg` | ISC — © Lucide Contributors; portions © Cole Bemis 2013–2023 (Feather, MIT) | https://lucide.dev. A handful of icons (verified badge, shop, filled heart/play) are our own SVGs in `resources/icons/`. |
| **Tailwind CSS** (compiled output) | `public/assets/css/app.css` | MIT — © Tailwind Labs, Inc. | The licence banner is kept at the top of the compiled file. |
| **esbuild** (bundling output only) | `public/assets/js/` | MIT — © Evan Wallace | The bundles contain only our own JavaScript. |
| **QR-code encoder** | `app/Support/Qr.php`, `QrTables.php` | Original work for this project | Pure PHP implementation of the public ISO/IEC 18004 standard (byte mode, versions 1–40, levels L/M/Q/H, Reed–Solomon, mask selection); verified by decoding generated PNGs with two independent decoders (ZXing, jsQR) across sizes and levels, and by decoding the downloaded QR of a real order in the browser suites. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED. |

No other PHP or JavaScript libraries run on the server or in the visitor's browser: there is no Composer `vendor/` folder, no jQuery, no analytics or chat scripts, no CDN.

## Brand assets

* The **site logo, favicon, app icons and Open Graph image** are original SVG/PNG artwork created for this project (`tools/build-brand-assets.mjs` renders the PNGs from the SVG sources). Replace them with your own in **Admin → Website** at any time.
* The **Instagram** and **YouTube** marks in `public/assets/img/platforms/` are simplified SVG recreations used only to identify those platforms. *Instagram* is a trademark of Meta Platforms, Inc.; *YouTube* is a trademark of Google LLC. This project is not affiliated with or endorsed by either company. Upload your own approved logos in **Admin → Services** (platform logos) if you prefer.
* **Alpha UPI**, **BharatPe**, **Paytm** and **UPI** are trademarks of their respective owners and are named only to describe compatibility.

## Build-time and QA tools (not deployed)

| Tool | Licence | Used for |
|---|---|---|
| Tailwind CSS CLI 4.x, `@tailwindcss/cli` | MIT | compiling the stylesheet |
| esbuild 0.25 | MIT | bundling the JavaScript modules |
| `lucide-static` 0.552 | ISC | source of the icon sprite |
| `@fontsource-variable/inter` | OFL-1.1 | source of the Inter font files |
| Playwright 1.6x | Apache-2.0 | browser-driven QA suites (`tools/qa/*.mjs`) |
| axe-core 4.10 | MPL-2.0 | accessibility checks (`tools/qa/a11y.mjs`) |
| jsQR 1.4, pngjs 7 | Apache-2.0, MIT | decoding generated QR images in tests |
| Apache HTTP Server + mod_php, MariaDB | Apache-2.0, GPL-2.0 | local verification of the deployment layouts (not redistributed) |

Full licence texts are available in each package's folder under `node_modules/` after `npm install`, and at the project websites listed above.
