# Deep Media Cleaner

A WordPress plugin that finds media files that are not used anywhere on the site and moves them to a recycle bin you can restore from.

- **Version:** 1.5.1 (see the changelog in `readme.txt`)
- **Requires:** WordPress 5.8+, PHP 7.4+
- **License:** GPL-2.0-or-later

> Make a full backup (database + uploads) before cleaning, and check the site for a few days before emptying the bin.

## What it scans

- Post content, excerpts and filtered content of every post type (pages, posts, snippets, block templates, global styles, menus, forms…)
- All post meta: featured images, Elementor / Divi / WPBakery / Beaver data, ACF image, gallery and repeater fields, WooCommerce galleries
- Options: widgets, Customizer (logo, site icon, header/background), theme and plugin settings
- Term, user and comment meta; comments
- Custom tables created by plugins (sliders, forms, page builders). Logs, caches and media-organiser tables are skipped by default
- Active theme, parent theme and must-use plugin files, for hard-coded upload URLs
- The uploads folder itself, to find files that are **not** in the Media Library

References are matched by attachment ID, by uploads path and (conservatively) by bare file name. Size variants (`-300x200`, `-scaled`, `-rotated`, edited copies, `.webp`/`.avif` siblings) are tied back to the original. Unicode file names are normalised (NFC), so files uploaded from macOS still match.

## Safety model

- A scan only reads. Nothing moves until you choose to.
- Every item goes to the recycle bin first. Library items keep their ID, title, alt text and links, and are hidden from the library and the site while in the bin.
- Each item is re-checked right before it is moved (setting).
- Recently uploaded media is protected (default 7 days).
- Ignore list for items you want to keep.
- Uninstalling restores everything that is still in the bin.
- Activity log of every scan, move, restore and delete.

## Missing files

A library item is marked **File missing** when its entry still exists but the file is gone from the server. The *Missing files* view lists them with the places that still reference them.

- **Move to bin** removes the broken library entry. References elsewhere keep a broken link.
- **Remove simple references + move to bin** also deletes custom-field rows (`wp_postmeta`) whose whole value is the item's ID or file URL. Post content, page-builder data, serialized/JSON values, options, widgets, term/user meta and plugin tables are never edited.
- **Preview references** shows what would be removed without changing anything.
- Restoring the item from the bin puts the removed rows back.

## Known limits

- Media referenced only by code that builds file names at runtime (e.g. `"logo-" . $year . ".png"`) cannot be detected. Use the Ignore list.
- Media offloaded to S3/CDN with no local file is listed as used and left alone.
- On nginx the bin folder's `.htaccess` has no effect; the folder name is randomised instead.

## Installation

1. Copy this folder to `wp-content/plugins/deep-media-cleaner/` (or zip it and use *Plugins → Add New → Upload Plugin*).
2. Activate **Deep Media Cleaner**.
3. Open **Media → Deep Media Cleaner** and run a scan.

## WP-CLI

```
wp dmc scan
wp dmc stats
wp dmc list [--view=unused|used|ignored] [--format=table|csv|json|ids]
wp dmc trash <id>... | --all [--yes]
wp dmc bin
wp dmc restore <bin-id>... | --all
wp dmc delete <bin-id>... | --all [--yes]
```

## Layout

| Path | Purpose |
| --- | --- |
| `deep-media-cleaner.php` | Plugin bootstrap, `dmc-trash` post status, hooks |
| `includes/class-dmc-scanner.php` | Step-based, time-budgeted scanner and pre-move re-check |
| `includes/class-dmc-util.php` | Settings, path normalisation, reference extraction |
| `includes/class-dmc-bin.php` | Recycle bin: trash, restore, delete, auto-purge |
| `includes/class-dmc-unlink.php` | Removal/restoration of simple references for missing files |
| `includes/class-dmc-reason.php` | "Why is this kept?" descriptors and rendering |
| `includes/class-dmc-admin.php` | Admin UI and AJAX handlers |
| `includes/class-dmc-cli.php` | WP-CLI commands |
| `includes/class-dmc-db.php` | Table schema and upgrades |
| `uninstall.php` | Restores bin contents, then removes tables/options |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
