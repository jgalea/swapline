=== Swapline ===
Contributors: rebelcode, jeangalea
Tags: search replace, database, migration, serialized, multisite
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search and replace across your database with serialized data and JSON handled safely, plus backups and safe imports for moving a site.

== Description ==

Swapline finds and replaces text in your database tables. It's built for moving a site to a new domain, switching to HTTPS, or fixing a string that appears in hundreds of places.

* Choose the tables to process. The list shows each table's size, with shortcuts for all tables and core tables only. On multisite you can filter the list by site.
* Run several search and replace pairs in one pass. They're applied in order, each on the result of the one before.
* Limit a run, backup or import to the columns you name, or skip some.
* Serialized data is rebuilt with correct lengths at any depth, including serialized strings stored inside other serialized strings. Objects are never instantiated.
* JSON values, such as page builder data, are matched in their escaped form too, so `https:\/\/example.com` is found when you search for `https://example.com`.
* Values that look serialized but are broken are left alone and counted in the report.
* Case-insensitive matching and regular expressions, with the pattern checked before anything runs.
* Dry runs report matched rows and cells per table.
* "View exactly what changed" shows a side-by-side diff of every changed cell, with its table, row and column. The last five runs are kept in History.
* Undo a live run from History. Each cell the run changed goes back to its earlier value, and cells edited since the run keep their newer value.
* Save any number of named profiles and load them later.
* Large tables are processed in batches with a progress bar. Tables without a primary key work too.
* If a run is interrupted, for example by closing the tab, Swapline offers to resume it where it stopped.
* On multisite, the tool lives in the network admin and super admins can pick tables from any site.
* Back up the whole database or selected tables to a gzipped SQL file. Optionally apply a search and replace to the backup only, to move the site to a new domain without touching the live site.
* Backups are stored in a folder with a random name in your uploads directory, with random file names and .htaccess and web.config rules that deny web access. If your web server serves files from that folder anyway, the Backup tab warns you so you can add a server rule. Download backups from the admin and keep as many as you like.
* A live search and replace can back up the selected tables first. History links each run to that backup, with a button to restore it.
* Restore any backup from the Backup list.
* Import a .sql or .sql.gz file, or one of your backups, with an optional search and replace. Every statement is checked first, the data loads into temporary tables, and the live tables are swapped only when everything has loaded. If anything fails, the live database is untouched.
* Importing can keep you logged in and keep the site's address.
* WP-CLI: `wp swapline run`, `wp swapline backup` and `wp swapline import`, with the same column options as the admin screen.

== Installation ==

1. Upload the `swapline` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it. On multisite, network-activate it.
3. Go to Tools > Search & Replace (Settings > Search & Replace in the network admin).

== Frequently Asked Questions ==

= Should I back up first? =

Yes. Do a dry run first and check the diff. Tick "Back up the selected tables first" when you start a live run, and History shows a button to restore that backup next to the run. History can also undo a live run cell by cell.

= Can I let other roles use Swapline? =

Yes. Return a different capability from the `swapline_capability` filter, for example `add_filter( 'swapline_capability', fn() => 'manage_woocommerce' );`. It controls the Search & Replace and History screens. Backups, downloads, imports, restores and runs on the users tables always need `manage_options` (`manage_network` on multisite), because they expose password hashes and can create administrators. Anyone you give the capability to can rewrite your posts, options and settings, so pick a role you'd trust with the database.

= Why is the guid column skipped? =

WordPress uses post GUIDs as permanent identifiers, and feed readers rely on them not changing. Untick the option if you really need to change them.

= Are array keys replaced? =

No. Only values are changed, inside serialized data and JSON alike, which matches how WordPress and WP-CLI handle it.

= How do I write a regular expression? =

Enter the pattern without delimiters, for example `https?://old\.example\.com`. Use `$1`, `$2` in the replacement for captured groups.

= Where are backups stored? =

In a folder with a random name inside your uploads directory. It has .htaccess and web.config rules that deny web access, and the file names are random too, so on servers that ignore those rules (such as nginx) the file names can't be guessed. If the server serves files from the folder, the Backup tab says so and names the folder, so you can add a rule that denies access to it. Download backups from Tools > Search & Replace > Backup.

= What can be imported? =

SQL files with CREATE TABLE, DROP TABLE, INSERT ... VALUES, LOCK/UNLOCK TABLES and session SET statements, which covers backups from Swapline, WP-CLI and mysqldump. Anything else, such as GRANT, triggers or stored procedures, is listed and nothing is imported. Table names must use this site's table prefix.

= What does WP-CLI's command add over wp search-replace? =

It uses the same engine as the admin screen, so it also replaces JSON-escaped values, reports broken serialized values it skipped, can load a saved profile with `--profile`, and records the run in the admin history so you can view the diff.

== Screenshots ==

1. Search and replace with two pairs, run on the tables you pick.
2. A dry run reports the rows and cells that would change in each table.
3. The side-by-side diff of every changed cell, here a serialized value with its lengths rebuilt and a JSON-escaped URL.
4. History keeps recent runs, with undo and a restore button for the backup taken before a run.
5. Create, download, restore and delete backups.
6. Import a backup or SQL file, with an optional search and replace and your login kept.

== Changelog ==

= 0.4.1 =
* The History tab's action buttons wrap onto extra lines instead of running past the edge of the table.
* The WP-CLI run summary now reads "1 cell in 1 row changed (1 replacement)" for single counts.

= 0.4.0 =
* Undo a live run from History. Cells the run changed go back to their earlier value, and cells edited since keep their newer value.
* Restore any backup from the Backup list, or the backup taken before a run straight from its History row.
* Run several search and replace pairs in one pass, applied in order.
* Limit a run, backup or import to chosen columns, or skip some. WP-CLI takes `--columns` and `--skip-columns`.
* Runs show in History while they're in progress. An interrupted run can be resumed or stopped, and its diff stays available.
* The Backup tab warns you when the web server serves files from the backup folder.
* `wp swapline run` asks before a live run writes. Add `--yes` to skip the question.
* On multisite, filter the table list by site.
* Admin, API and WP-CLI runs no longer interfere with each other's recorded changes.
* Table names match the site's prefix exactly, so other installs sharing the database are left out.
* Password hashes and session tokens are kept out of the change history.
* Backups, downloads, imports, restores and runs on the users, usermeta and options tables stay limited to administrators when the capability filter delegates Swapline to other roles.
* Only the user who started a run, or an administrator, can continue or cancel it.
* Imports reject gzip files that expand far beyond their size, and use this site's prefix for temporary tables.
* Database errors show a general message in the browser and are logged in full.
* SQLite sites get a clear message on the Import tab.
* The reasons an import is refused can be translated.
* The search and replace screen, report, diff and History work at phone widths.

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

== Upgrade Notice ==

= 0.4.0 =
Adds undo from History, several search and replace pairs per run, column limits, backup restore and resumable runs, with security hardening for sites that delegate Swapline to other roles.
