=== Swapline ===
Contributors: rebelcode, jeangalea
Tags: search replace, database, migration, serialized, multisite
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search and replace across your database with serialized data and JSON handled safely, plus backups and safe imports for moving a site.

== Description ==

Swapline finds and replaces text in your database tables. It's built for moving a site to a new domain, switching to HTTPS, or fixing a string that appears in hundreds of places.

* Choose the tables to process. The list shows each table's size, with shortcuts for all tables and core tables only.
* Serialized data is rebuilt with correct lengths at any depth, including serialized strings stored inside other serialized strings. Objects are never instantiated.
* JSON values, such as page builder data, are matched in their escaped form too, so `https:\/\/example.com` is found when you search for `https://example.com`.
* Values that look serialized but are broken are left alone and counted in the report.
* Case-insensitive matching and regular expressions, with the pattern checked before anything runs.
* Dry runs report matched rows and cells per table.
* "View exactly what changed" shows a side-by-side diff of every changed cell, with its table, row and column. The last five runs are kept.
* Save any number of named profiles and load them later.
* Large tables are processed in batches with a progress bar. Tables without a primary key work too.
* On multisite, the tool lives in the network admin and super admins can pick tables from any site.
* Back up the whole database or selected tables to a gzipped SQL file. Optionally apply a search and replace to the backup only, to move the site to a new domain without touching the live site.
* Backups are stored in a private folder that can't be opened from the web, and downloaded from the admin. Keep as many as you like.
* A live search and replace can back up the selected tables first.
* Import a .sql or .sql.gz file, or one of your backups, with an optional search and replace. Every statement is checked first, the data loads into temporary tables, and the live tables are swapped only when everything has loaded. If anything fails, the live database is untouched.
* Importing can keep you logged in and keep the site's address.
* WP-CLI: `wp swapline run`, `wp swapline backup` and `wp swapline import`.

== Installation ==

1. Upload the `swapline` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it. On multisite, network-activate it.
3. Go to Tools > Search & Replace (Settings > Search & Replace in the network admin).

== Frequently Asked Questions ==

= Should I back up first? =

Yes. A live run writes to the database and can't be undone. Do a dry run first and check the diff.

= Can I let other roles use Swapline? =

Yes. Return a different capability from the `swapline_capability` filter, for example `add_filter( 'swapline_capability', fn() => 'edit_others_posts' );`. It controls the admin page, the backups and the API access that other plugins get. Search and replace changes the whole database, so choose a capability you trust.

= Why is the guid column skipped? =

WordPress uses post GUIDs as permanent identifiers, and feed readers rely on them not changing. Untick the option if you really need to change them.

= Are array keys replaced? =

No. Only values are changed, inside serialized data and JSON alike, which matches how WordPress and WP-CLI handle it.

= How do I write a regular expression? =

Enter the pattern without delimiters, for example `https?://old\.example\.com`. Use `$1`, `$2` in the replacement for captured groups.

= Where are backups stored? =

In a folder with a random name inside your uploads directory. It has .htaccess and web.config rules that deny web access, and the file names are random too, so on servers that ignore those rules (such as nginx) the files still can't be guessed. Download backups from Tools > Search & Replace > Backup.

= What can be imported? =

SQL files with CREATE TABLE, DROP TABLE, INSERT ... VALUES, LOCK/UNLOCK TABLES and session SET statements, which covers backups from Swapline, WP-CLI and mysqldump. Anything else, such as GRANT, triggers or stored procedures, is listed and nothing is imported. Table names must use this site's table prefix.

= What does WP-CLI's command add over wp search-replace? =

It uses the same engine as the admin screen, so it also replaces JSON-escaped values, reports broken serialized values it skipped, can load a saved profile with `--profile`, and records the run in the admin history so you can view the diff.

== Changelog ==

= 0.3.0 =
* Other plugins can run previews, replacements and backups through Swapline's API.
* The `swapline_capability` filter sets which capability can use Swapline.

= 0.2.1 =
* Backups and imports keep columns whose default is an expression, such as CURRENT_TIMESTAMP.
* Imports check quoted storage engine names against the allowed list.
* Serialized data inside reformatted JSON, and serialized strings written with S:, are left unchanged and reported instead of being corrupted.

= 0.2.0 =
* Backups, with an optional search and replace applied to the backup only.
* Imports through temporary tables, with statement checks and an optional search and replace.
* Optional backup before a live search and replace.
* WP-CLI: `wp swapline backup`, `wp swapline import` and `--backup` on `wp swapline run`.

= 0.1.0 =
* First release.
