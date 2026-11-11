<?php

namespace Swapline\Admin;

use Swapline\Backup\Storage;
use Swapline\Database\Tables;
use Swapline\Plugin;
use Swapline\Run\Config;

defined( 'ABSPATH' ) || exit;

/**
 * The Backup and Import tabs.
 */
final class BackupScreen {

	public static function register(): void {
		add_filter( 'swapline_admin_tabs', array( self::class, 'tabs' ) );
		add_action( 'swapline_enqueue_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * @param array<string, array{label: string, render: callable}> $tabs
	 * @return array<string, array{label: string, render: callable}>
	 */
	public static function tabs( array $tabs ): array {
		if ( ! current_user_can( Plugin::database_capability() ) ) {
			return $tabs;
		}
		$tabs['backup'] = array(
			'label'  => __( 'Backup', 'swapline' ),
			'render' => array( self::class, 'render_backup' ),
		);
		$tabs['import'] = array(
			'label'  => __( 'Import', 'swapline' ),
			'render' => array( self::class, 'render_import' ),
		);
		return $tabs;
	}

	public static function enqueue(): void {
		if ( ! current_user_can( Plugin::database_capability() ) ) {
			return;
		}
		wp_enqueue_script( 'swapline-backup', plugins_url( 'assets/backup.js', SWAPLINE_FILE ), array( 'wp-i18n' ), SWAPLINE_VERSION, true );
		wp_set_script_translations( 'swapline-backup', 'swapline' );

		$nonces = array();
		foreach ( BackupAjax::ACTIONS as $action ) {
			$nonces[ $action ] = wp_create_nonce( 'swapline_' . $action );
		}

		wp_add_inline_script(
			'swapline-backup',
			'window.swaplineBackup = ' . wp_json_encode(
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonces'    => $nonces,
					'maxUpload' => wp_max_upload_size(),
					'defaults'  => Config::defaults(),
				)
			) . ';',
			'before'
		);
	}

	public static function render_backup(): void {
		$tables = Tables::all();
		$dir    = Storage::dir();
		?>
		<div id="swapline-notice" class="notice inline hidden" role="status" aria-live="polite"><p></p></div>

		<h2><?php esc_html_e( 'Create a backup', 'swapline' ); ?></h2>
		<form id="swapline-backup-form" novalidate>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Tables', 'swapline' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Tables', 'swapline' ); ?></legend>
							<label><input type="radio" name="scope" value="all" checked> <?php esc_html_e( 'All tables', 'swapline' ); ?></label><br>
							<label><input type="radio" name="scope" value="selected"> <?php esc_html_e( 'Only the tables I select', 'swapline' ); ?></label>
						</fieldset>
						<div class="swapline-backup-tables hidden">
							<div class="swapline-table-tools">
								<button type="button" class="button" data-select="all"><?php esc_html_e( 'Select all', 'swapline' ); ?></button>
								<button type="button" class="button" data-select="core"><?php esc_html_e( 'Core tables only', 'swapline' ); ?></button>
								<button type="button" class="button" data-select="none"><?php esc_html_e( 'Clear', 'swapline' ); ?></button>
							</div>
							<?php self::table_list( $tables, 'swapline-b' ); ?>
						</div>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of tables. */
									_n( '%s table uses this site\'s table prefix.', '%s tables use this site\'s table prefix.', count( $tables ), 'swapline' ),
									number_format_i18n( count( $tables ) )
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Search and replace', 'swapline' ); ?></th>
					<td>
						<label><input type="checkbox" name="replace_enabled" value="1"> <?php esc_html_e( 'Replace in the backup file only', 'swapline' ); ?></label>
						<p class="description"><?php esc_html_e( 'Your live database is not changed. Use this to move the site to another domain: import the file on the new site.', 'swapline' ); ?></p>
						<?php self::replace_fields( 'swapline-b' ); ?>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary" id="swapline-backup-run"><?php esc_html_e( 'Create backup', 'swapline' ); ?></button>
				<button type="button" class="button hidden" id="swapline-backup-cancel"><?php esc_html_e( 'Cancel', 'swapline' ); ?></button>
			</p>
		</form>

		<div id="swapline-progress" class="hidden">
			<progress max="100" value="0"></progress>
			<p class="swapline-progress-text" aria-live="polite"></p>
		</div>

		<h2><?php esc_html_e( 'Backups', 'swapline' ); ?></h2>
		<?php if ( is_wp_error( $dir ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $dir->get_error_message() ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! is_wp_error( $dir ) && Storage::is_exposed() ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: folder name. */
						__( 'Your web server serves files from the backup folder (%s), so only the random file names keep backups private. Add a rule to your server configuration that denies access to that folder.', 'swapline' ),
						basename( $dir )
					)
				);
				?>
			</p></div>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Backups are stored in a private folder in your uploads directory that cannot be opened from the web. Download them from here.', 'swapline' ); ?></p>
		<div class="swapline-scroll">
			<table class="widefat striped swapline-backups">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Created', 'swapline' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Size', 'swapline' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Tables', 'swapline' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notes', 'swapline' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swapline' ); ?></span></th>
					</tr>
				</thead>
				<tbody id="swapline-backup-list"></tbody>
			</table>
		</div>
		<script type="application/json" id="swapline-backup-data"><?php echo wp_json_encode( BackupAjax::backup_rows(), JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

		<form id="swapline-retention" class="swapline-inline-form">
			<label for="swapline-retention-keep"><?php esc_html_e( 'Keep the latest', 'swapline' ); ?></label>
			<input type="number" id="swapline-retention-keep" name="keep" class="small-text" min="0" max="1000" step="1" value="<?php echo esc_attr( (string) Storage::retention() ); ?>">
			<span><?php esc_html_e( 'backups (0 keeps them all)', 'swapline' ); ?></span>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'swapline' ); ?></button>
		</form>
		<?php
	}

	public static function render_import(): void {
		$backups = Storage::backups();
		?>
		<div id="swapline-notice" class="notice inline hidden" role="status" aria-live="polite"><p></p></div>

		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Importing replaces every table the file contains. Tables that are not in the file are left alone. The file is checked and loaded into temporary tables first, and the live tables are only swapped once everything has loaded.', 'swapline' ); ?></p>
			<p><?php esc_html_e( 'Only import files you trust. A file that contains a users table brings its own accounts, and they can log in to this site.', 'swapline' ); ?></p>
		</div>

		<form id="swapline-import-form" novalidate>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'File', 'swapline' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'File', 'swapline' ); ?></legend>
							<label><input type="radio" name="source" value="upload" checked> <?php esc_html_e( 'Upload a .sql or .sql.gz file', 'swapline' ); ?></label><br>
							<div class="swapline-source" data-source="upload">
								<label for="swapline-import-file" class="screen-reader-text"><?php esc_html_e( 'SQL file', 'swapline' ); ?></label>
								<input type="file" id="swapline-import-file" name="file" accept=".sql,.gz,application/sql,application/gzip">
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: maximum upload size, e.g. "64 MB". */
											__( 'Maximum upload size: %s. For larger files, upload them with WP-CLI: wp swapline import <file>.', 'swapline' ),
											size_format( wp_max_upload_size() )
										)
									);
									?>
								</p>
							</div>
							<label><input type="radio" name="source" value="backup"<?php disabled( array() === $backups ); ?>> <?php esc_html_e( 'One of the backups on this site', 'swapline' ); ?></label>
							<div class="swapline-source hidden" data-source="backup">
								<label for="swapline-import-backup" class="screen-reader-text"><?php esc_html_e( 'Backup', 'swapline' ); ?></label>
								<select id="swapline-import-backup" name="backup">
									<?php foreach ( $backups as $backup ) : ?>
										<option value="<?php echo esc_attr( $backup['name'] ); ?>"><?php echo esc_html( self::backup_label( $backup ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Search and replace', 'swapline' ); ?></th>
					<td>
						<label><input type="checkbox" name="replace_enabled" value="1"> <?php esc_html_e( 'Replace in the imported data', 'swapline' ); ?></label>
						<?php self::replace_fields( 'swapline-i' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'This site', 'swapline' ); ?></th>
					<td>
						<label><input type="checkbox" name="preserve" value="1" checked> <?php esc_html_e( 'Keep me logged in, and keep this site\'s address (siteurl and home)', 'swapline' ); ?></label>
						<p class="description"><?php esc_html_e( 'Your user account, sessions and roles are carried over into the imported data. Untick this to take the address and users from the file as they are.', 'swapline' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary" id="swapline-import-run"><?php esc_html_e( 'Import', 'swapline' ); ?></button>
				<button type="button" class="button hidden" id="swapline-import-cancel"><?php esc_html_e( 'Cancel', 'swapline' ); ?></button>
			</p>
		</form>

		<div id="swapline-progress" class="hidden">
			<progress max="100" value="0"></progress>
			<p class="swapline-progress-text" aria-live="polite"></p>
		</div>
		<div id="swapline-results" class="hidden"></div>
		<?php
	}

	/**
	 * @param array<string, mixed> $backup
	 */
	public static function backup_label( array $backup ): string {
		return sprintf(
			/* translators: 1: date and time, 2: file size. */
			__( '%1$s (%2$s)', 'swapline' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['created'] ),
			size_format( (int) $backup['size'], 1 )
		);
	}

	/**
	 * @param array<string, array{name: string, rows: int, size: int, core: bool, site: int}> $tables
	 */
	private static function table_list( array $tables, string $id ): void {
		?>
		<div class="swapline-tables" tabindex="0" role="group" aria-label="<?php esc_attr_e( 'Tables', 'swapline' ); ?>">
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'swapline' ); ?></span></td>
						<th scope="col"><?php esc_html_e( 'Table', 'swapline' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Size', 'swapline' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tables as $table ) : ?>
						<?php $field = $id . '-' . md5( $table['name'] ); ?>
						<tr data-core="<?php echo $table['core'] ? '1' : '0'; ?>">
							<th scope="row" class="check-column"><input type="checkbox" name="tables[]" value="<?php echo esc_attr( $table['name'] ); ?>" id="<?php echo esc_attr( $field ); ?>"></th>
							<td><label for="<?php echo esc_attr( $field ); ?>"><code><?php echo esc_html( $table['name'] ); ?></code></label></td>
							<td class="num"><?php echo esc_html( size_format( $table['size'], 1 ) ? size_format( $table['size'], 1 ) : '0 B' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function replace_fields( string $id ): void {
		?>
		<div class="swapline-replace-fields hidden">
			<p>
				<label for="<?php echo esc_attr( $id ); ?>-search"><?php esc_html_e( 'Search for', 'swapline' ); ?></label><br>
				<textarea id="<?php echo esc_attr( $id ); ?>-search" name="search" class="large-text code" rows="2" spellcheck="false"></textarea>
			</p>
			<p>
				<label for="<?php echo esc_attr( $id ); ?>-replace"><?php esc_html_e( 'Replace with', 'swapline' ); ?></label><br>
				<textarea id="<?php echo esc_attr( $id ); ?>-replace" name="replace" class="large-text code" rows="2" spellcheck="false"></textarea>
			</p>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Options', 'swapline' ); ?></legend>
				<label><input type="checkbox" name="case_insensitive" value="1"> <?php esc_html_e( 'Case-insensitive', 'swapline' ); ?></label><br>
				<label><input type="checkbox" name="regex" value="1"> <?php esc_html_e( 'Regular expression', 'swapline' ); ?></label><br>
				<label><input type="checkbox" name="skip_guid" value="1" checked> <?php esc_html_e( 'Skip the guid column', 'swapline' ); ?></label>
			</fieldset>
		</div>
		<?php
	}
}
