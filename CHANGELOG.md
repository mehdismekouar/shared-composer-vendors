# Changelog

All notable changes to `shared-composer-vendors` will be documented in this file.

## v1.0.0 - 2026-09-29

First release: a deploy-time replacement for `composer install` that keeps one copy of each package version in a shared folder and links every project's `vendor/` to it, to save inodes and disk space on shared hosting.

- Builds the new `vendor/` next to the live one and swaps it in by renaming; puts the previous one back if a Composer script fails.
- Shares a package only when it is identical to the zip Composer downloaded, or when two projects changed it in exactly the same way (patches, plugins, scripts).
- Keeps the shared folder read-only. Packages that write into their own folder, or whose files point outside it, get writable folders of their own in the project.
- `extra.non-shared-vendors` in composer.json keeps chosen packages out of the shared folder.
- Runs on PHP 8.0+ with Composer 2, on Linux and Windows (junctions instead of symbolic links).
