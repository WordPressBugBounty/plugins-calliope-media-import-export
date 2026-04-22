=== Export/Import Media ===
Contributors: mairaforesto
Tags: import, export, media, images, seo
Requires at least: 5.6
Tested up to: 6.9
Stable tag: 1.6.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and export your WordPress media library using CSV, with preview, batch processing, duplicate prevention, and core media metadata columns.

== Description ==

**Export/Import Media** helps you move media between WordPress sites using CSV while preserving core media metadata columns such as alt text, title, caption, and description.

The free workflow is built for straightforward media export and import. The plugin generates a CSV file containing useful media information such as URLs, relative paths, alt text, titles, captions, and descriptions. You can validate that CSV, preview it before importing, and process rows in batches from a cleaner admin screen focused on the core workflow.

During normal free imports, detected duplicates are skipped to help prevent duplicate attachments in the library. Metadata columns are supported for rows that are imported, while controlled matching and update rules for already-existing media are handled by the separate Pro add-on.

**Why use this plugin?**
* **Straightforward CSV workflows:** Export media data, validate incoming CSV files, preview them, and import them in batches.
* **Batch import:** AJAX-powered processing helps avoid browser and timeout issues on medium and large imports.
* **Duplicate prevention:** Detects existing matches and skips them in the normal free workflow.
* **Metadata columns:** Supports title, alt text, caption, and description columns in CSV import/export.
* **Developer friendly:** Includes hooks and filters for extending CSV columns, validation, admin UI, and import/export behavior.

Need more control for larger libraries? The separate **Export/Import Media Pro** add-on adds saved workflows, remote or server-side CSV sources, controlled matching against existing media, selective metadata refreshes, safer replace-file workflows, and background processing with history tools.

== Features ==

* **CSV Export:** Export media data to CSV with filters by date, media type, and attachment context.
* **CSV Preview:** Validate and preview the file before importing.
* **Batch Processing:** Import media rows in AJAX batches.
* **Local Import Mode:** Register files that already exist in `/uploads/` without downloading them again.
* **Honor Relative Path:** Reuse or preserve folder paths from the CSV.
* **Skip Thumbnail Generation:** Speed up large imports when needed.
* **Duplicate Prevention:** Uses source meta and file fingerprints to detect existing matches and skip those rows in the standard free workflow.
* **Metadata Columns:** Imports and exports title, alt text, caption, and description columns for supported rows.
* **Downloadable Log:** Save an import log as `.txt` after the process finishes.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Export/Import Media** in the WordPress admin menu to start using the tool.

== Frequently Asked Questions ==

= Does this plugin move the actual media files? =
Yes. During a remote import, the plugin securely downloads the file from the URL provided in the CSV and adds it to your media library, generating the necessary attachment records.

= How does the "Skip Thumbnail Generation" work? =
By checking this option, WordPress imports only the original image and skips creating intermediate image sizes during the import process. This makes large image imports faster. You can regenerate thumbnails later using a dedicated plugin.

= What happens if a media file already exists? =
The free plugin performs duplicate detection using stored source data, relative paths, attachment paths, and fingerprints. If it finds an existing match, it skips that row to help prevent duplicates in the library.

= Does "metadata support" mean the free plugin updates existing media records? =
No. In the free plugin, metadata columns are supported for standard export/import rows, while detected duplicates are skipped in the normal workflow. Controlled matching, metadata refreshes, selective field updates, and replace-file workflows for existing attachments belong to the separate Pro add-on.

= What does the Pro add-on add? =
Export/Import Media Pro adds saved workflows, remote or server-side CSV sources, controlled matching against existing media, selective metadata updates, replace-file workflows, history, and background processing for larger media libraries.

= Can I filter which media items to export? =
Yes. You can filter by date range, media type, and attachment context such as unattached files or media attached to posts, pages, and WooCommerce products.

== Changelog ==

= 1.6.15 =
* Fixed broken Spanish MO encoding so accented text renders correctly again in the admin.
* Added a small follow-up Spanish i18n pass for the latest admin wording.


= 1.6.14 =
* UX: Returned the main Export to CSV and Start Import actions to a stronger fuchsia accent so the key workflow buttons stand out again.
* Housekeeping: Aligned the free stylesheet version comment and readme release metadata with the current plugin version.

= 1.6.13 =
* Tightened the free header layout so all four feature chips stay compact and use the full hero width better.
* Added a shared hook after the main import flow so Pro tools can appear below preview/progress instead of interrupting the standard workflow.

= 1.6.11 =
* UX: Rebalanced the main hero feature chips into a compact full-width row so the banner stays shorter and uses space more efficiently.

= 1.6.10 =
* UX: Removed the oversized Pro upsell callout from inside the main hero so the free header stays more compact.
* UX: Hide the Free badge automatically when the Pro add-on is active to keep the shared admin header consistent.

= 1.6.9 =
* i18n: Wrapped remaining visible admin and CSV header labels for translation coverage.
* i18n: Refreshed the translation template and locale files so the latest free-screen strings are ready for localization.

= 1.6.8 =
* UX: Refined the free admin screen with a cleaner hierarchy, calmer styling, and clearer step-by-step guidance.
* UX: Reworked the free vs Pro messaging so the upgrade path stays visible without getting in the way of the core workflow.
* Messaging: Clarified more explicitly that updating metadata on already-existing media is a Pro workflow.

= 1.6.6 =
* UX: Removed locked Pro submenus from the free plugin so the admin experience stays focused and less intrusive.
* UX: Reworked the free admin page to keep the core export/import workflow front and center while showing a lighter Pro teaser section.
* Messaging: Clarified the free vs Pro boundary around updating metadata on already-existing media items.


= 1.6.5 =
* Internal: Added a service getter so companion add-ons can safely access the free plugin importer without duplicating core logic.
* Internal: Added a programmatic import runner for compatible add-ons, including optional dry-run and duplicate-strategy context.
* Internal: Added import lifecycle events and request context support so add-ons can store history and extend the import flow without changing the free UI.
* Compatibility: This release prepares the free core for the separate Pro add-on while keeping the free plugin clean and fully usable on its own.

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

