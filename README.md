<h1 align="center">Swapline</h1>

<p align="center">
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img alt="License" src="https://img.shields.io/badge/LICENSE-GPLv2-5C9E31?style=for-the-badge"></a>
  <a href="https://rebelcode.com"><img alt="Built by" src="https://img.shields.io/badge/BUILT%20BY-REBELCODE-8A2BE2?style=for-the-badge"></a>
</p>

<p align="center"><b>Swapline is a free WordPress plugin for search and replace across database tables, with safe handling of serialized data and JSON.</b></p>

## What it does

- Search and replace across the tables you pick, with table sizes, select-all and core-only shortcuts.
- Serialized values are rebuilt with correct byte lengths at any depth, including serialized strings inside serialized strings. Objects are never instantiated (`allowed_classes => false`).
- JSON values are matched in their escaped form (`https:\/\/…`, `é`) and must still decode after the change.
- Broken serialized values are left alone and counted.
- Case-insensitive and regex modes, dry runs, and a side-by-side diff of every changed cell for the last five runs.
- Unlimited saved profiles.
- Batched AJAX processing with a progress bar. Tables without a primary key are supported.
- Multisite: network admin only, tables from every site.
- Backups of the whole database or selected tables to a gzipped SQL file, written in batches with a progress bar. A search and replace can be applied to the backup file only, which leaves the live site alone and gives you a file to import on a new domain.
- Backups are kept in a randomly named folder under uploads, closed to the web by `.htaccess`, `web.config` and random file names, and downloaded through a nonce- and capability-checked handler. A retention setting keeps the latest backups.
- A live search and replace can back up the selected tables first.
- Import a `.sql` or `.sql.gz` upload, or one of your backups, with an optional search and replace on the imported data. Every statement is checked before anything runs: only `CREATE TABLE`, `DROP TABLE`, `INSERT ... VALUES` with literal values, `LOCK`/`UNLOCK TABLES` and session `SET` are accepted. Tables load under temporary names and are swapped in with one `RENAME TABLE`, so a failed import leaves the live database as it was.
- Importing can keep you logged in and keep the site's `siteurl` and `home`.
- `wp swapline run <search> <replace> [<table>...] [--dry-run] [--regex] [--case-insensitive] [--profile=<name>] [--backup]`.
- `wp swapline backup [<table>...] [--search=<search> --replace=<replace>]` and `wp swapline import <file> [--search=<search> --replace=<replace>] [--keep-url]`.

The screen is under Tools > Search & Replace, or Settings > Search & Replace in the network admin.

## Requirements

- WordPress 6.2 or later
- PHP 8.0 or later

## For other plugins

`Swapline\Api::preview( $input )` runs a dry run and returns counts per table with a few before/after samples. `Swapline\Api::replace( $input, $backup = true )` backs up the affected tables and replaces. `Swapline\Api::backup( $tables )` and `Swapline\Api::restore( $name )` write and load Swapline backups. `$input` takes the same keys as the WP-CLI command: `search`, `replace`, `tables`, `case_insensitive`, `regex`, `skip_guid`. The calls run in the current request and do no capability checks.

## Development

```
composer install
vendor/bin/phpunit
vendor/bin/phpcs
```

`SWAPLINE_NAIVE_ENGINE=1 vendor/bin/phpunit` runs the suite against a bare `str_replace`, and every test should fail. `SWAPLINE_NAIVE_SPLITTER=1 vendor/bin/phpunit --filter SplitterTest` does the same for the SQL splitter with `explode(';')`.

## Extending

- `swapline_admin_tabs` filters the admin tabs (`slug => [label, render]`).
- `swapline_before_run` and `swapline_after_run` fire around every run, from the admin and WP-CLI.
- `swapline_excluded_tables`, `swapline_history_size` and `swapline_max_recorded_changes` adjust what's listed and kept.
- `swapline_step_seconds` sets how long each backup or import request works before handing back to the browser.
- `swapline_enqueue_assets` fires when the admin screen loads its scripts.

## License

GPLv2 or later.
