=== Export/Import Media ===
Contributors: mairaforesto
Tags: import, export, media, csv, migration, images, seo
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 1.6.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and export your WordPress media library using CSV, with preview, batch processing, duplicate prevention, and support for media metadata.

== Description ==

**Export/Import Media** helps you move media between WordPress sites using CSV while keeping core media metadata such as alt text, title, caption, and description.

The plugin generates a CSV file containing useful media information such as URLs, relative paths, alt text, titles, captions, and descriptions. You can then validate that CSV, preview it, and import it in batches.

**Why use this plugin?**
* **Batch import:** AJAX-powered processing helps avoid browser and timeout issues on medium and large imports.
* **Metadata aware:** Supports title, alt text, caption, and description columns.
* **Developer friendly:** Includes hooks and filters for extending CSV columns, validation, admin UI, and import/export behavior.

== Features ==

* **CSV Export:** Export media data to CSV with filters by date, media type, and attachment context.
* **CSV Preview:** Validate and preview the file before importing.
* **Batch Processing:** Import media rows in AJAX batches.
* **Local Import Mode:** Register files that already exist in `/uploads/` without downloading them again.
* **Honor Relative Path:** Reuse or preserve folder paths from the CSV.
* **Skip Thumbnail Generation:** Speed up large imports when needed.
* **Duplicate Prevention:** Uses source meta and file fingerprints to avoid importing the same media twice.
* **Downloadable Log:** Save an import log as `.txt` after the process finishes.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Media > Export/Import Media** to start using the tool.

== Frequently Asked Questions ==

= Does this plugin move the actual media files? =
Yes. During a remote import, the plugin securely downloads the file from the URL provided in the CSV and adds it to your media library, generating the necessary attachment records.

= How does the "Skip Thumbnail Generation" work? =
By checking this option, WordPress imports only the original image and skips creating intermediate image sizes during the import process. This makes large image imports faster. You can regenerate thumbnails later using a dedicated plugin.

= What happens if a media file already exists? =
The plugin performs duplicate detection using stored source data, relative paths, attachment paths, and fingerprints. If it finds an existing match, it skips that row to avoid duplicates.

= Can I filter which media items to export? =
Yes. You can filter by date range, media type, and attachment context such as unattached files or media attached to posts, pages, and WooCommerce products.

== Changelog ==

= 1.6.4 =
* Fix: Added the proper `1.6.4` tag so WordPress.org serves the correct release package instead of an older build.
* Fix: Added a bootstrap fallback for the config layer so the plugin does not fatal if an update is incomplete or a new file was not copied yet.
* Improvement: Bumped asset versions again to force admin CSS and JS cache refresh after update.

= 1.6.3 =
* Fix: Added a bootstrap fallback for the config layer so the plugin does not fatal if a partial update misses the new config file.
* Improvement: Kept the release packaging compatible with older installations that may update files out of sync.

= 1.6.2 =
* Improvement: Simplified the public admin UI by removing add-on and Pro marketing elements from the plugin screen.
* Improvement: Removed the plugin status sidebar and kept only neutral documentation and support links in the admin header.
* Improvement: Bumped asset versions to prevent stale cached admin CSS and JS after update.

= 1.6.1 =
* Fix: Removed the UTF-8 BOM from AJAX-related PHP files to prevent invalid JSON responses during CSV validation.
* Improvement: Added safer importer i18n fallbacks so missing localized keys do not render empty labels in the admin UI.
* Improvement: Replaced the external admin Google Font dependency with a local system font stack for a cleaner WordPress.org release.
* Improvement: Aligned the admin screen title with the published plugin name.

= 1.6.0 =
* Improvement: Centralized plugin defaults, feature flags, and extension-ready configuration.
* Improvement: Export pipeline refactored to support column definitions and cleaner request handling.
* Improvement: Admin screen prepared for future add-on sections and Pro-ready feature slots without changing the free workflow.
* Improvement: Import pipeline now supports extensible header definitions and row-level validation hooks.
* Fix: Readme and release metadata aligned with the current plugin version.

= 1.4.4 =
* Fix: Prevent duplicated imports by detecting existing attachments via source URL / relative path / file fingerprint.
* Improvement: Store and backfill source and fingerprint meta to make future imports faster and consistent.

= 1.4.3 =
* Fix: Removed stray HTML text output in the import form.
* UX: Hides third-party admin notices inside the plugin screen to keep the interface clean.

= 1.4.2 =
* New: Export all media types with a dedicated "Media Type" filter.
* New: Import option to honor the original relative path when importing.
* New: Downloadable sample CSV template.
* Security/UX: Hardened Local Import Mode to keep file access inside uploads.

= 1.4.0 =
* New: Modern drag and drop interface for easier CSV uploads.
* New: Advanced export filters for attachments linked to WooCommerce products, posts, or pages.
* New: Downloadable import log for better debugging.
* UX: Improved file selection with visual feedback.

= 1.3.0 =
* Security: Major hardening using native WordPress sideload APIs.
* New: Date range filters in the export section.
* New: Skip Thumbnail Generation option for faster imports.
* Improvement: Refactored the plugin into object-oriented classes.

= 1.2.3 =
* Improved compatibility with PHP 8.x.
* Fixed minor UI bugs in the progress bar.

= 1.2 =
* Added Local Import Mode.
* Added automatic cleanup for temporary files.
* Improved error reporting for downloads.

= 1.0 =
* Initial release.
