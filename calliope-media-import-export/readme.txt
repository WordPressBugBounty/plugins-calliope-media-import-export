=== Export/Import Media - CSV Media Library Import & Export ===
Contributors: mairaforesto
Tags: csv import, csv export, media library, media import, media export
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.7.28
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and export your WordPress media library from CSV with preview, batch processing, metadata support, and duplicate prevention.

== Description ==

Need to move or rebuild a media library without manually re-uploading every file? **Export/Import Media** helps you import WordPress media from CSV, export media data to CSV, and keep core attachment metadata organized during the process.

The free plugin focuses on the essential CSV workflow: export your current media library, prepare or upload a CSV, validate the file, preview rows before import, and process media in batches from the WordPress admin. Supported CSV columns include the media URL, relative path, title, alt text, caption, and description.

During standard free imports, detected duplicates are skipped to help prevent duplicate attachments in the media library. Metadata is preserved for newly imported media rows. Updating existing attachments, controlled matching rules, rollback restore points, saved workflows, background processing, image conversion options, and replace-file workflows are handled by the separate **Export/Import Media Pro** add-on.

**Watch the demo:**

https://www.youtube.com/watch?v=QfXuZOJLgFc

**Why use this plugin?**
* **CSV-first workflow:** Export, validate, preview, and import media library data using a readable CSV file.
* **Batch processing:** Import media rows in smaller AJAX batches to reduce timeout risk.
* **Metadata support:** Preserve title, alt text, caption, and description for imported attachments.
* **Duplicate prevention:** Skip existing matches in the standard free workflow.
* **Local file support:** Register files that already exist inside your WordPress uploads directory.
* **Developer friendly:** Use hooks and filters to extend CSV columns, validation, admin UI, and import/export behavior.

== Features ==

* **CSV export:** Export media library data to CSV with filters for date range, media type, and attachment context.
* **CSV validation and preview:** Upload a CSV, validate required columns, and preview rows before import.
* **Batch import:** Process media rows in batches from the WordPress admin.
* **Metadata support:** Import and export title, alt text, caption, and description columns.
* **Duplicate prevention:** Detect existing matches and skip duplicate rows in the standard free workflow.
* **Local Import Mode:** Import files that already exist in `/uploads/` without downloading them again.
* **Honor Relative Path:** Reuse or preserve relative upload paths from the CSV when available.
* **Skip Thumbnail Generation:** Skip intermediate image sizes during import when speed matters more than thumbnails.
* **Downloadable import log:** Download a `.txt` log after an import finishes.
* **Hooks and filters:** Extend CSV columns, validation, admin UI, and import/export behavior.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Export/Import Media** in the WordPress admin menu to start using the tool.

== Frequently Asked Questions ==

= Does this plugin move the actual media files? =
Yes, when you run a remote import. The plugin downloads the file from the URL in the CSV, adds it to the WordPress media library, and creates the attachment record. With Local Import Mode, it can also register files that already exist inside your uploads directory.

= Can I import WordPress media from CSV? =
Yes. Prepare a CSV with an absolute URL or relative path for each file, upload it in the plugin screen, validate it, preview the rows, and start the batch import.

= What metadata can I import? =
The free plugin supports common media metadata columns including title, alt text, caption, and description. It also reads media location columns such as absolute URL and relative path.

= What happens if a file already exists? =
The free plugin checks for existing matches using stored source data, relative paths, attachment paths, and file fingerprints. If it finds a duplicate, it skips that row instead of creating another attachment.

= Does the free plugin update existing media? =
No. In the free plugin, detected duplicates are skipped in the standard workflow. Updating metadata on existing attachments, selecting match rules, and replacing files are Pro workflows.

= How does the "Skip Thumbnail Generation" work? =
By checking this option, WordPress imports only the original image and skips creating intermediate image sizes during the import process. This makes large image imports faster. You can regenerate thumbnails later using a dedicated plugin.

= Can I undo an import? =
The free plugin provides preview and downloadable logs, but it does not include rollback. Rollback restore points before import are available in the Pro add-on.

= What does the Pro add-on add? =
Export/Import Media Pro adds rollback restore points before imports, saved workflows, background processing, controlled matching, selective metadata updates, replace-file workflows, history, and image conversion options where the server and source file handling support them.

= Can I filter which media items to export? =
Yes. You can filter exports by date range, media type, and attachment context, including unattached files and media attached to posts or pages.

= Does it work with WooCommerce product images? =
Export filters can include product attachment context when WooCommerce is active and product attachments are available. The standard free CSV import creates media attachments; assigning imported images to products is outside the free import workflow.

= Can I use local files already inside uploads? =
Yes. Enable Local Import Mode and provide relative paths for files that already exist inside your WordPress uploads directory. Honor Relative Path can preserve or reuse folder paths from the CSV when appropriate.

== Screenshots ==

1. Export media library to CSV with filters for date, media type, and attachment context.
2. Upload and validate a CSV before importing media into WordPress.
3. Preview CSV rows, metadata, and duplicate status before starting the import.
4. Import media in batches with progress feedback and a downloadable log.
5. Review imported media with title, alt text, caption, and description preserved.

== Changelog ==

= 1.7.28 =
* Added the YouTube demo video to the WordPress.org readme so it can be embedded on the plugin page.
* Bumped plugin release metadata to 1.7.28.

= 1.7.27 =
* Improved the WordPress.org readme with clearer CSV media import/export positioning and screenshot captions.
* Added a dismissible review popup shown once after 7 days to administrators, while keeping the existing review footer visible.
* Improved admin internationalization and refreshed translations.
* Changed review stars to a consistent golden color.
* Loaded plugin translations from the bundled languages directory more reliably.

= 1.7.19 =
* Import UX: CSV files that contain only headers now validate as an empty preview with a clear warning instead of showing a failed-validation error.
* Export UX: export now defaults to All Media and warns when the selected filters would create a header-only CSV.
* Translations: refreshed language files for the new empty-CSV and export-filter notices.

= 1.7.18 =
* Import: improved CSV validation for files with legacy line endings and clearer feedback when a CSV has headers but no importable data rows.
* Translations: regenerated language files so new Pro teaser and import error strings do not fall back to English.

= 1.7.17 =
* Compatibility: Adds safe import context and lifecycle hooks for Pro rollback restore points and Pro image conversion without changing the free import workflow.
* Messaging: Clarifies that rollback restore points and WebP/AVIF conversion are Pro features.

= 1.7.16 =
* Design: Aligns the Pro details button and Pro badges with the refreshed logo/banner color palette.

= 1.7.15 =
* Fix: SVG files can now be imported through the plugin when they pass the plugin's SVG safety checks.
* Fix: Remote Pro replacement imports now wait until the replacement file is downloaded before running replace_file on an existing match.
* Security: SVG imports are validated for unsafe markup before attaching or replacing media files.

= 1.7.14 =
* Fix: Turbo imports now get a larger execution window before the safety time cutoff, so 100-row batches can run as intended when the server allows it.
* UX: Import logs now warn when a batch was stopped early by the time limit and continues in the next request.

= 1.7.13 =
* Fix: Keeps PHP upload temporary paths intact during CSV validation so imports work correctly on Windows-based local environments.

= 1.7.12 =
* Design: Refreshes the admin menu icon, banner, calls to action, and review stars with the logo color palette.

= 1.7.11 =
* Fix: Keeps temporary remote downloads available until duplicate handling finishes, so Pro replace-file and force-new workflows can run reliably.
* Fix: Replaces existing attachment files more safely by copying the new file before cleaning up the previous file and generated sizes.
* Compatibility: Adds a server-side gate so advanced import actions only run when an add-on explicitly enables them.
* Performance: Streams CSV exports in batches to reduce memory usage on larger media libraries.

= 1.7.10 =
* Compatibility: Tested with WordPress 7.0.
* Improvement: Simplified the admin banner by removing the highlight chips.
* Improvement: Added a suggestions mail link to the admin banner.
* Improvement: Moved remaining inline admin styles into the plugin stylesheet.

= 1.7.9 =
* Fixed export media type filtering so selecting Videos, Audio, Documents, or Images uses explicit MIME type lists instead of falling back to images.
* Export filenames now include the selected media type for easier verification.

= 1.7.6 =
* Fix: Adjusted the footer review star styles so inline SVG stars are not clipped by admin line-height or inherited image rules.

= 1.7.5 =
* Fix: Made large AJAX imports safer on shared hosting by capping oversized batches, using a host-aware soft time limit, and preventing long stale locks after failed requests.
* Improvement: Import retries now wait for active batches instead of immediately failing against the import lock, and reduce the runtime batch size after server/network failures.

= 1.7.2 =
* Fix: The downloadable sample CSV now uses stable canonical column headers and points to a bundled sample image so it can validate and run more reliably on translated admin sites.
* Fix: CSV validation now recognizes translated column headers for URL, relative path, title, alt text, caption, and description when users import files created from localized exports.

= 1.7.1 =
* Fix: CSV validation no longer fails because the importer now closes file handles correctly instead of re-entering its own close helper.
* Improvement: AJAX validation errors now surface a clearer server response when a host returns a non-JSON error.

= 1.7 =
* Internal: Replaced the global service map with an internal service registry to keep bootstrapped plugin services more controlled and maintainable.
* Compatibility: Kept `eim_get_service()` working as the compatibility layer for importer, exporter, admin, and add-on access patterns.

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
