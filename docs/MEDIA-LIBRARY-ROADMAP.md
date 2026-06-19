# AssetDrips — Media Library Enhancement Roadmap

> Status: planning draft · Last updated: 2026-06-08
>
> AssetDrips is a media-library **enhancement suite**, not a scanner. Sift
> (unused-media detection) shipped first because it built the hard machinery;
> the rest of the suite rides on what Sift already computes.

---

## 1. The thesis

WordPress's default media library degrades badly as it grows: a flat,
date-ordered list, weak search, no idea where a file is used, no folders, no
dedupe, no optimisation, and queries that fight serialized `wp_postmeta`. Every
one of these gets *worse* the bigger the library.

AssetDrips fixes this with **one fast index + everything reversible** — and Sift
already half-built both.

### What Sift already gives us (reuse, don't reinvent)

| Capability | Where it lives | Reused by |
|---|---|---|
| Variant-aware catalogue of every attachment, memory-safe & resumable (keyset, not `OFFSET`) | `Inventory\AttachmentCatalogue::each_batch` | Index backfill (Phase 1) |
| "Where is this file used" — source + human-readable locator per reference | `Usage\UsageHit` / `Usage\UsageMap` | Find "used on", usage columns |
| Custom indexed tables + self-healing migration | `Db\Schema` (`DB_VERSION` + `maybe_upgrade`) | The media index table |
| Coverage / blind-spot detection | `Coverage\BuilderDetector` | Confidence on dedupe/cleanup |
| Reversible action = move file + snapshot rows + one-click restore | `Quarantine\QuarantineManager` | Compress backups, dedupe merges, replace |
| Batched scan with throttled live progress + CLI for huge libraries | `Scan\ScanService`, `Admin\ScanProgress`, `Cli\ScanCommand` | Backfill & bulk-op progress |

The single biggest insight: **usage data already exists** (`UsageHit::context`)
but is dumped into an `evidence` JSON blob and only used to label USED/unused.
We surface it.

---

## 2. The diagnosis (default library, especially at scale)

1. **Flat, date-ordered list** — no folders/tags/categories. Unusable at 20k+.
2. **Weak search** — title/filename only; no alt, dimensions, size, subtype,
   orientation, uploader, used/unused; filters don't combine.
3. **No "where used"** — `Unattached` ≠ unused; misleads people into deleting
   live images.
4. **Performance collapse** — grid AJAX-scrolls; filtering hits serialized
   `_wp_attachment_metadata` with no usable indexes. 50k–200k libraries jank.
5. **Duplicate accumulation** — re-uploads become `image-1.jpg`; no detection.
   Big libraries are routinely 20–40% dupes.
6. **Thumbnail/disk bloat** — 8–15 sizes per upload, most never served, plus
   orphaned sizes from removed registrations. No visibility.
7. **Oversized originals** served/stored as-is; no compression, no WebP/AVIF.
8. **Metadata debt** — no view of images missing alt text; one-modal-at-a-time edits.
9. **No bulk ops** beyond delete; **no replace-in-place** (re-upload breaks every reference).

---

## 3. The keystone — one fast media index

The default is slow because it filters/sorts over `wp_posts` + serialized
postmeta. Fix it once with a denormalized index table — one row per attachment,
indexed on the columns you actually filter on. Every module reads this instead
of crawling postmeta. **This is the scale answer**; build it first.

See [Phase 1](#phase-1--media-index-table--incremental-freshness) for the
concrete schema and hook design.

---

## 4. Phased roadmap

| Phase | Module | Delivers | Depends on |
|---|---|---|---|
| **1** | Index | `assetdrips_media` table + backfill + incremental hooks | — |
| **2** | **Find** | Faceted search & filtering (incl. used/unused, missing-alt, "used on") | 1 |
| **3** | **Sort** | Virtual folders + tags, bulk metadata edit (esp. bulk alt text) | 1 |
| **4** | **Squeeze** | Compression, WebP/AVIF, thumbnail/disk cleanup | 1 |
| **5** | Dedupe + Replace | Content/perceptual hash, reference-rewrite merges, replace-in-place | 1, Sift |
| **★** | Health dashboard | Library health numbers on the AssetDrips home | each phase feeds it |

Principles carried through every phase:
- **Reversible by default** — extend the quarantine move+snapshot+restore pattern
  to compression backups, dedupe merges, and replace.
- **Scale primitives** — keyset batching, resumable jobs, throttled progress, CLI
  for very large libraries. Already present; reuse them.
- **Honest confidence** — anything destructive is tiered/gated like Sift.

### Phase 2 — Find (search & filtering)
- Faceted search over filename/title/alt/caption/description.
- Combinable filters: subtype (PNG vs WebP), size range, dimension range,
  orientation, date, uploader, folder/tag, **used/unused**, **missing alt**, has-duplicates.
- **"Used on …"** — media used by a specific post/page/product (from usage index).
- Saved smart views ("Large unused images", "Missing alt", "Mine this month").
- *Scale payoff:* replaces infinite scroll with one indexed query.

### Phase 3 — Sort (organization)
- **Virtual** folders + tags as attachment taxonomies (DB association, files
  don't move → reversible, no broken URLs). Drag-and-drop + bulk assign.
- **Bulk metadata edit** across a selection — especially **bulk alt text** (SEO/a11y win).
- Bulk move / tag / regenerate-thumbnails / download-as-zip / delete (delete via Sift quarantine).
- *Scale payoff:* the only way to impose structure on an already-huge library.

### Phase 4 — Squeeze (optimization)
- Compress (lossy/lossless) with before/after savings, **reversible** (original backup).
- WebP/AVIF generation + serving; resize oversized originals to a max dimension.
- **Thumbnail/disk audit**: find generated sizes no registered size matches anymore
  (theme/plugin dropped a size) → orphaned files → safe cleanup; flag never-served sizes.
- Bulk queue with live progress + CLI.
- *Scale payoff:* disk and page-weight are the most visceral big-library pains.

### Phase 5 — Dedupe + Replace-in-place
- **Dedupe:** content-hash (exact) + perceptual hash (near). Group → pick canonical
  → **rewrite the other copies' references** to it → quarantine the rest.
- **Replace-in-place:** new file onto an existing attachment ID; keep URL/ID,
  regenerate sizes, cache-bust. Kills "re-upload breaks every link".
- Both need reference *rewriting* (vs Sift's *reading*); gate behind confidence tiers.

### ★ Library Health dashboard (the AssetDrips home)
Each a one-query read off the index, with a CTA into the relevant module:

> Library **14.2 GB** · **6.1 GB unused** (Sift) · **1.8 GB duplicates** (Dedupe)
> · **2,140 missing alt** (Sort) · **920 MB orphaned thumbnails** (Squeeze)
> · **310 oversized originals** (Squeeze)

---

## Phase 1 — Media index table + incremental freshness

The foundation. Everything else is a read against this table.

### 1a. Schema (`assetdrips_media`)

Add a third definition to `Db\Schema::table_definitions()` and bump
`Schema::DB_VERSION` so `maybe_upgrade()` self-heals on update. dbDelta
formatting rules from the existing file apply (lowercase types, one def per
line, **two spaces** after `PRIMARY KEY`, no "tidying").

```sql
CREATE TABLE {prefix}assetdrips_media (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	attachment_id bigint(20) unsigned NOT NULL,
	filename varchar(255) NOT NULL DEFAULT '',
	title text NOT NULL,
	alt text NOT NULL,
	mime varchar(100) NOT NULL DEFAULT '',
	mime_subtype varchar(40) NOT NULL DEFAULT '',
	width mediumint(8) unsigned NOT NULL DEFAULT 0,
	height mediumint(8) unsigned NOT NULL DEFAULT 0,
	orientation varchar(10) NOT NULL DEFAULT '',
	filesize bigint(20) unsigned NOT NULL DEFAULT 0,
	has_alt tinyint(1) NOT NULL DEFAULT 0,
	folder_id bigint(20) unsigned DEFAULT NULL,
	usage_count int(10) unsigned NOT NULL DEFAULT 0,
	is_used tinyint(1) NOT NULL DEFAULT 0,
	content_hash char(40) DEFAULT NULL,
	uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
	uploaded_at datetime NOT NULL,
	indexed_at datetime NOT NULL,
	usage_synced_at datetime DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY attachment_id (attachment_id),
	KEY mime_subtype (mime_subtype),
	KEY is_used (is_used),
	KEY filesize (filesize),
	KEY content_hash (content_hash),
	KEY folder_id (folder_id),
	KEY uploaded_at (uploaded_at),
	KEY has_alt (has_alt)
) {charset_collate};
```

Notes:
- `orientation` (`landscape|portrait|square`) is derived from width/height at
  write time — a cheap pre-computed facet so Find never computes it per query.
- `content_hash` (sha1 of the **original** bytes) is nullable: populated lazily
  by Squeeze/Dedupe, not required for Find/Sort.
- A `FULLTEXT (title, filename, alt)` index is a Phase-2 enhancement once the
  storage engine is confirmed InnoDB on a modern MySQL; until then prefix LIKE +
  the column indexes are enough.

### 1b. Two freshness lanes (the key design decision)

Columns split by how expensive they are to keep fresh:

| Lane | Columns | How it stays fresh | Cost |
|---|---|---|---|
| **Structural / metadata** | filename, title, alt, mime, dimensions, filesize, has_alt, orientation, uploaded_* | **Real-time hooks** (below) | cheap — single-row upsert |
| **Usage** | usage_count, is_used, usage_synced_at | **Scan + WP-Cron**, not per-save | expensive — requires crawling content |

Why: recomputing usage means walking post content/meta — far too costly to do on
every keystroke. So usage is "as of last scan" (`usage_synced_at` makes that
honest in the UI), refreshed by the Sift scan and a scheduled cron. Structural
columns are a one-row write and update instantly.

### 1c. Incremental hooks (structural lane)

```
add_attachment            → upsert base row (filename, mime, title, author, date)
wp_generate_attachment_metadata (filter)
                          → fill width/height/filesize/orientation; return $meta unchanged
attachment_updated / edit_attachment
                          → refresh title
added/updated_post_meta for _wp_attachment_image_alt
                          → refresh alt + has_alt
delete_attachment         → delete row
```

`wp_generate_attachment_metadata` is the right place for dimensions/filesize
because the sizes don't exist yet at `add_attachment`.

### 1d. Backfill for existing libraries (reuse, don't rebuild)

- Iterate with `AttachmentCatalogue::each_batch( $batchSize, $consumer, $cursor )`
  — already memory-safe and resumable via the returned cursor.
- Report progress through `Admin\ScanProgress` + the throttled `progress_writer`
  pattern from `ReviewScreen`.
- Ship a `wp assetdrips index` CLI command mirroring `Cli\ScanCommand` /
  `CommandRegistrar` for very large libraries.
- The Sift scan's `UsageMap` already yields `used_ids()` / `count_for($id)` — the
  scan pass writes the usage lane in the same sweep it already makes.

### 1e. Drift reconciliation

A lightweight cron: compare `COUNT(*)` attachments vs index rows, top up missing
rows, drop orphaned ones. Cheap insurance against missed hooks (imports, direct
SQL, `wp media import`).

### 1f. Suggested class layout (matches existing `src/` structure)

```
src/Index/MediaIndex.php          // read/query API over the table (used by Find/Sort/Health)
src/Index/MediaRow.php            // value object for one indexed attachment
src/Index/IndexBuilder.php        // backfill via AttachmentCatalogue::each_batch
src/Index/IndexHooks.php          // the real-time structural-lane hooks
src/Cli/IndexCommand.php          // `wp assetdrips index` (mirrors ScanCommand)
```

### 1g. Phase 1 acceptance

- Fresh install + backfill indexes every attachment; row count matches library.
- Upload / edit-alt / delete reflect in the index within the same request.
- `usage_count` / `is_used` populate from a Sift scan and carry `usage_synced_at`.
- Backfill resumes after interruption (kill mid-run, re-run, no dupes).
- 100k-row backfill stays within memory and completes from CLI.
