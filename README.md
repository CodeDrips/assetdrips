# AssetDrips

**Find, organise, and optimise large WordPress media libraries — non-destructively.**

[![CI](https://github.com/codedrips/assetdrips/actions/workflows/ci.yml/badge.svg)](https://github.com/codedrips/assetdrips/actions/workflows/ci.yml)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

WordPress's default media library degrades badly as it grows: a flat, date-ordered
list, weak search, no idea where a file is used, no folders, no optimisation, and
queries that fight serialized `wp_postmeta`. Every one of these gets *worse* the
bigger the library.

AssetDrips fixes this with **one fast index and everything reversible**. It is a
suite of four complementary tools that all read the same index, so they stay fast
at tens of thousands of attachments — and every destructive action keeps a
restorable backup.

---

## The four tools

### 🔍 Sift — unused-media detection
Finds attachments that nothing references and tells you what is safe to delete,
with honest confidence tiers and coverage detection (so it knows its own blind
spots). Deletion is a **reversible quarantine** — move to a holding area with a
one-click restore, never an immediate hard delete.

### 🧭 Find — fast index + faceted search
A denormalized `assetdrips_media` index makes any file locatable in seconds by
attribute (filename, alt text, type, size, dimensions, orientation, uploader,
date) **or by usage** ("used on this page"). Combinable filters, classic
pagination, a findability **Health slice** (indexed / missing-alt / unused
counts), and per-user saved smart views.

### 🗂️ Sort — folders, tags, and bulk editing
Non-destructive organisation for large libraries: hierarchical **virtual folders**
(metadata-only, reversible), freeform **tags** filterable in Find, and **bulk
metadata editing** (alt / title / caption / description, with "fill empty only") —
all over a multi-select grid with filter-scoped "select all matching" and
batched, progress-tracked operations. Zero file moves.

### 🗜️ Squeeze — image optimisation
Shrinks storage and serves next-gen formats without breaking anything:
- **Recompression** — JPEG (lossy, configurable quality) and PNG (lossless).
- **WebP / AVIF** — additive sibling files; originals are never replaced.
- **Resize oversized originals** to a maximum dimension.
- **Thumbnail / sizes audit** — find missing, orphaned (report-only), and unused
  registered sizes, and regenerate missing ones without clobbering custom crops.
- **Transparent next-gen serving** — browsers that support WebP/AVIF get the
  sibling via a PHP filter (no HTML or URL changes, `Vary: Accept`, wp-admin
  excluded).
- **Restorable** — every recompress/resize backs up the original first, with
  atomic single-item and bulk restore.

All Squeeze operations run from the Find grid (filter-scoped bulk), auto-on-upload
(opt-in), a library-wide resumable batch, per-item "optimize now", or WP-CLI.

---

## Why AssetDrips

- **Scales.** Everything reads one indexed table instead of crawling
  `wp_posts` + serialized `wp_postmeta`. Built and tested against libraries of
  1,500+ attachments.
- **Reversible by default.** Cleanup quarantines (doesn't delete); optimisation
  backs up the original before any destructive change. You can always get back to
  the untouched file.
- **Honest.** AVIF/WebP support is verified with a live encode probe (not a
  format-list guess); "where used" is a real indexed lookup; the audit reports
  orphans rather than auto-deleting them.
- **No lock-in, no external services.** Optimisation is local-first (Imagick/GD).
  No file moves, no URL rewrites, no third-party API calls.

---

## Requirements

| | |
|---|---|
| WordPress | 6.0 or later |
| PHP | 8.1 or later |
| Image optimisation | ImageMagick or GD. WebP/AVIF require the relevant encoder support in your server's Imagick/GD build (detected automatically; features that aren't available are clearly disabled in the UI). |

> **Status:** 1.0 — the four tools above are implemented and unit-tested. See the
> [Changelog](CHANGELOG.md).

---

## Installation

### From a release ZIP

1. Download the latest `assetdrips.zip` from the
   [Releases](https://github.com/codedrips/assetdrips/releases) page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, install,
   and activate.
3. On activation, AssetDrips creates its tables and begins building the media
   index in the background. You can also build it immediately with
   `wp assetdrips index` or the one-click "Build index now" admin notice.

### From source

```bash
git clone https://github.com/codedrips/assetdrips.git
cd assetdrips
composer install --no-dev --optimize-autoloader   # production autoloader only
```

Then symlink/copy the directory into `wp-content/plugins/assetdrips` and activate.
(The plugin's only runtime dependency is the Composer PSR-4 autoloader — there are
no third-party runtime packages.)

---

## Usage

### Admin

After activation, find AssetDrips in the wp-admin sidebar. The submenus cover
Find (search/filter the library), folders, the Sift review screen, and the
Squeeze settings + dashboard (capabilities, optimisation toggles, biggest
offenders, history, and the sizes audit).

### WP-CLI

All long-running work is scriptable and resumable:

```bash
# Build or refresh the media index (faceted search + health depend on it)
wp assetdrips index

# Sift: scan for unused media, then act on the results
wp assetdrips scan
wp assetdrips list
wp assetdrips quarantine <ids>
wp assetdrips restore <ids>
wp assetdrips purge <ids>

# Squeeze: optimise the library (resumable; choose which operations to run)
wp assetdrips squeeze --batch=100 --ops=recompress,webp,avif,resize
wp assetdrips squeeze --resume

# Thumbnail / sizes audit
wp assetdrips sizes-audit
```

Run `wp help assetdrips <command>` for the full flags of any command.

---

## How it works

- **The index is the keystone.** `assetdrips_media` is a denormalized table with a
  **two-lane freshness** model: a *structural lane* (filename, title, alt, mime,
  dimensions, filesize, orientation…) kept current in real time via hooks, and a
  *usage lane* (used/unused, usage count) refreshed by scan + WP-Cron. Every tool
  reads this index.
- **Reversibility is a first-class pattern.** Sift quarantines via a
  copy-and-snapshot manager; Squeeze backs up originals to a web-inaccessible
  location with verified file size before any encode, and restores atomically
  (file → sub-sizes → metadata → index sync).
- **One encode seam.** All optimisation routes through a single
  `wp_get_image_editor()` wrapper, so backup-first, double-optimisation guards,
  and capability gating live in exactly one place across every trigger path.

For the project's architecture and module layout, see [`src/`](src/) (PSR-4
`AssetDrips\`): `Index`, `Find`/`Admin`, `Sort`, `Squeeze`, `Usage`, `Quarantine`,
`Scan`, `Coverage`, `Score`, `Db`, `Cli`.

---

## Development

```bash
composer install            # installs dev tooling (PHPUnit, PHPCS/WPCS)
composer test               # run the unit suite (standalone, no WP install needed)
composer lint               # PHP_CodeSniffer (WordPress Coding Standards)
composer lint:fix           # auto-fix what PHPCBF can
```

The unit suite uses a lightweight WordPress-function stub bootstrap
(`tests/unit-bootstrap.php`), so it runs in milliseconds without a database. An
integration suite (`composer test:integration`) is available for running against a
real WordPress test install via `bin/install-wp-tests.sh`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for coding standards, branch conventions,
and how to open a pull request.

---

## Contributing

Issues and pull requests are welcome. Please read
[CONTRIBUTING.md](CONTRIBUTING.md) first, and report security issues privately per
our [Security Policy](SECURITY.md).

## License

AssetDrips is free software, released under the
[GNU General Public License v2.0 or later](LICENSE) — the same license as
WordPress.

## Credits

Built by [CodeDrips](https://codedrips.com).
