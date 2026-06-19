# Changelog

All notable changes to AssetDrips are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project aims to
follow [Semantic Versioning](https://semver.org/) once it reaches 1.0.

Entries below are grouped by the feature set ("tool") they belong to. AssetDrips
is pre-1.0 and under active development.

## [Unreleased]

### Added
- First public release preparation: GPLv2 license, README, contributing and
  security policies, issue/PR templates, and CI (PHPUnit + PHP_CodeSniffer).

### Changed
- License changed to **GPL-2.0-or-later** (matching WordPress) and the plugin
  header/description updated to describe the full suite.

### Fixed
- **Squeeze — "Optimize now" (dashboard Biggest Offenders):** the button ran the
  optimisation but redirected to the Media Library where the confirmation notice
  was never shown, so it appeared to do nothing. It now renders the notice on any
  admin screen, returns you to the screen you started from (so the offenders list
  and savings refresh), reports clearly when no operations are enabled, and
  reports the real reason instead of "Image optimized." when every enabled
  operation is skipped at runtime (e.g. WebP/AVIF unsupported, already optimised).

## Squeeze — image optimisation — 2026-06-13

### Added
- JPEG/PNG recompression and oversized-original resize through a single image
  editor seam, with a content-hash double-optimisation guard.
- Additive WebP/AVIF sibling generation (originals never replaced); availability
  verified with a live encode probe.
- Restorable backups: originals copied to a web-inaccessible location before any
  destructive change, with atomic single-item and bulk restore.
- Transparent next-gen serving via a PHP filter (`Vary: Accept`, wp-admin and
  feeds excluded), opt-in and instantly reversible.
- Thumbnail/sizes audit: missing, orphaned (report-only), and unused registered
  sizes; additive regeneration that preserves custom crops.
- A Squeeze dashboard (disk saved, biggest offenders, history), a `squeeze_state`
  Find facet, a Media Library status column, and `wp assetdrips squeeze` /
  `wp assetdrips sizes-audit` CLI commands.

## Sort — folders, tags, bulk editing — 2026-06-10

### Added
- Hierarchical virtual folders (metadata-only, reversible) with an admin tree,
  single-item assignment, and child-preserving delete.
- Freeform tags with REST autocomplete and a combinable, saveable Find facet.
- Multi-select Find grid with filter-scoped "select all matching" and batched,
  progress-tracked bulk operations (folder assign, tag add/remove, metadata edit
  with "fill empty only"). Index stays in sync automatically.

## Find — index + faceted search — 2026-06-09

### Added
- The denormalized `assetdrips_media` index with two-lane (structural/usage)
  freshness, backfill, self-healing reconcile, and `wp assetdrips index` CLI.
- Faceted search over the index: filename/alt/title/caption/description search and
  combinable filters (type, size, dimensions, orientation, date, uploader,
  used/unused, missing-alt), with classic pagination.
- "Used on …" lookups and per-user saved smart views.
- A findability Health slice (indexed / missing-alt / unused), each deep-linking
  into a pre-filtered Find view.

## Sift — unused-media detection

### Added
- Reference/usage index and unused-media detection with honest confidence tiers
  and coverage (blind-spot) detection.
- Reversible quarantine (copy + snapshot + one-click restore) instead of hard
  delete, with a CLI (`scan` / `list` / `quarantine` / `restore` / `purge`) and an
  admin review screen.
