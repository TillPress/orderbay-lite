# wordpress.org directory assets — OrderBay Lite

These files are **SVN `/assets/` art**, not part of the plugin zip.
`tools/build-release.sh` and `seo-fixes/build_orderbay_lite_zip.py` exclude
`.wordpress-org/`. After the slug is approved, commit them into the plugin's
SVN `/assets/` directory (sibling of `/trunk/`), never into `/trunk/`.

Palette: Tillpress Shopfront — coral `#ff5a3c` on warm white `#fff9f4`.

## Header art
- `banner-1544x500.png` — hi-DPI plugin header banner
- `banner-772x250.png` — standard plugin header banner
- `icon-256x256.png` — hi-DPI plugin icon
- `icon-128x128.png` — standard plugin icon

## Screenshots (map 1:1 to `== Screenshots ==` in readme.txt)
Captured 9 Sep 2026 from a fresh WordPress + WooCommerce + SQLite install
with **only** OrderBay Lite active (no paid OrderBay, no license tab, no
packing slips / labels / bulk print).

- `screenshot-1.png` — invoice print view (browser Print / Save as PDF)
- `screenshot-2.png` — settings: business details, VAT / Tax ID, number format
- `screenshot-3.png` — order screen: Invoice, E-invoice UBL, E-invoice CII
- `screenshot-4.png` — customer My Account invoice download

Regenerate banners/icons with `~/workspace/wp-verify/make_orderbay_lite_art.py`.
Recapture screenshots with `~/workspace/wp-verify/run_orderbay_lite_shots.sh`
against the local verify stack (Lite-only).
