=== Deep Media Cleaner ===
Contributors: Stan Han
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later

Finds media that is not used anywhere on the site and moves it to a restorable recycle bin.

== What it scans ==

* Post content, excerpts and filtered content of every post type (pages, posts, WPCode snippets, block templates, global styles, menus, forms…)
* All post meta: featured images, Elementor / Divi / WPBakery / Beaver data, ACF image, gallery and repeater fields, WooCommerce galleries
* Options: widgets, Customizer (logo, site icon, header/background), theme and plugin settings
* Term, user and comment meta; comments
* Every custom table created by plugins (sliders, forms, page builders) – logs, caches and media-organiser tables are skipped
* Active theme, parent theme and must-use plugin files for hard-coded upload URLs
* The uploads folder itself, to find files that are NOT in the Media Library at all

References are matched by attachment ID, by uploads path and (conservatively) by bare file name, with size variants (-300x200, -scaled, -rotated, edited copies, .webp/.avif siblings) all tied back to the original. Korean / Unicode file names are normalised (NFC) so files uploaded from a Mac still match.

== Safety ==

* A scan only reads. Nothing moves until you choose to.
* Every item goes to the recycle bin first. Library items keep their ID, title, alt text and links, and are hidden from the library and the site while in the bin.
* Each item is re-checked right before it is moved (setting).
* Recently uploaded media is protected (default 7 days).
* Ignore list for items you want to keep.
* Uninstalling restores everything that is still in the bin.
* Activity log of every scan, move, restore and delete.

Make a full backup (database + uploads) before cleaning, and check the site for a few days before emptying the bin.

== Missing files ==

A library item is marked "File missing" when its entry still exists but the file is gone from the server (deleted by FTP, incomplete migration). The Missing files tab lists them with the places that still reference them.

* Move to bin – removes the broken library entry. References elsewhere keep a broken link.
* Remove simple references + move to bin – also deletes custom-field rows (wp_postmeta) whose whole value is the item's ID or file URL, e.g. a featured image, an ACF image/file field or a form upload field. A bare ID is only removed under a media-type key or an ACF field.
* Never edited: post content, page-builder data, serialized or JSON values, lists of IDs, options and widgets, term/user meta, plugin tables, menu items and WordPress internals.
* Preview references shows what would be removed and what would stay, without changing anything.
* Restoring the item from the bin puts the removed rows back (with their original meta ID when it is free).

== Known limits ==

* Media referenced only by code that builds file names at runtime (e.g. "logo-" . $year . ".png") cannot be detected. Use the Ignore list.
* Media offloaded to S3/CDN with no local file is listed as used and left alone.
* On nginx the bin folder's .htaccess has no effect; its name is randomised instead.

== WP-CLI ==

    wp dmc scan
    wp dmc stats
    wp dmc list [--view=unused|used|ignored] [--format=table|csv|json|ids]
    wp dmc trash <id>... | --all [--yes]
    wp dmc bin
    wp dmc restore <bin-id>... | --all
    wp dmc delete <bin-id>... | --all [--yes]
