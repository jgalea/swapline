<?php

namespace Swapline\Admin;

use Swapline\Database\Tables;
use Swapline\Plugin;
use Swapline\Run\Config;
use Swapline\Run\History;
use Swapline\Run\Profiles;
use Swapline\Run\Runner;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'swapline';

	private static string $hook = '';

	public static function register(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( self::class, 'add_menu' ) );
			return;
		}
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	public static function add_menu(): void {
		$title = __( 'Swapline', 'swapline' );
		$label = __( 'Search & Replace', 'swapline' );

		self::$hook = is_multisite()
			? (string) add_submenu_page( 'settings.php', $title, $label, Plugin::capability(), self::SLUG, array( self::class, 'render' ) )
			: (string) add_management_page( $title, $label, Plugin::capability(), self::SLUG, array( self::class, 'render' ) );

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( string $tab = '' ): string {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' );
		$args = array( 'page' => self::SLUG );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, $base );
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'swapline-admin', plugins_url( 'assets/admin.css', SWAPLINE_FILE ), array(), SWAPLINE_VERSION );
		wp_enqueue_script( 'swapline-admin', plugins_url( 'assets/admin.js', SWAPLINE_FILE ), array( 'wp-i18n' ), SWAPLINE_VERSION, true );
		wp_set_script_translations( 'swapline-admin', 'swapline' );

		$nonces = array();
		foreach ( Ajax::ACTIONS as $action ) {
			$nonces[ $action ] = wp_create_nonce( 'swapline_' . $action );
		}

		wp_add_inline_script(
			'swapline-admin',
			'window.swaplineAdmin = ' . wp_json_encode(
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonces'     => $nonces,
					'profiles'   => (object) Profiles::all(),
					'defaults'   => Config::defaults(),
					'unfinished' => Ajax::job_summary( Runner::current() ),
				)
			) . ';',
			'before'
		);

		do_action( 'swapline_enqueue_assets' );
	}

	/**
	 * @return array<string, array{label: string, render: callable}>
	 */
	private static function tabs(): array {
		$tabs = array(
			'replace' => array(
				'label'  => __( 'Search & Replace', 'swapline' ),
				'render' => array( self::class, 'render_replace' ),
			),
			'history' => array(
				'label'  => __( 'History', 'swapline' ),
				'render' => array( self::class, 'render_history' ),
			),
		);

		return (array) apply_filters( 'swapline_admin_tabs', $tabs );
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to use this tool.', 'swapline' ) );
		}

		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'replace';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'replace';
		}
		?>
		<div class="wrap swapline">
			<h1><?php esc_html_e( 'Swapline', 'swapline' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Swapline sections', 'swapline' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( $tabs[ $current ]['render'] ); ?>
		</div>
		<?php
	}

	public static function render_replace(): void {
		$tables = Tables::all();
		if ( ! current_user_can( Plugin::database_capability() ) ) {
			$tables = array_diff_key( $tables, array_flip( Plugin::protected_tables( array_keys( $tables ) ) ) );
		}
		$multisite = is_multisite();
		$sites     = $multisite ? self::site_labels( array_unique( array_column( $tables, 'site' ) ) ) : array();
		$profiles  = Profiles::all();
		?>
		<div class="swapline-profiles">
			<label for="swapline-profile" class="screen-reader-text"><?php esc_html_e( 'Saved profile', 'swapline' ); ?></label>
			<select id="swapline-profile">
				<option value=""><?php esc_html_e( 'Saved profiles', 'swapline' ); ?></option>
				<?php foreach ( array_keys( $profiles ) as $name ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="swapline-profile-load"><?php esc_html_e( 'Load', 'swapline' ); ?></button>
			<button type="button" class="button button-link-delete" id="swapline-profile-delete"><?php esc_html_e( 'Delete', 'swapline' ); ?></button>
		</div>

		<div id="swapline-notice" class="notice inline hidden" role="status" aria-live="polite"><p></p></div>

		<div id="swapline-resume" class="notice notice-warning inline hidden">
			<p></p>
			<p>
				<button type="button" class="button button-primary" data-action="resume"><?php esc_html_e( 'Resume', 'swapline' ); ?></button>
				<button type="button" class="button" data-action="stop"><?php esc_html_e( 'Stop and keep the record', 'swapline' ); ?></button>
			</p>
		</div>

		<form id="swapline-form" novalidate>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="swapline-search"><?php esc_html_e( 'Search for', 'swapline' ); ?></label></th>
					<td><textarea id="swapline-search" name="search" class="large-text code" rows="2" spellcheck="false" required></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="swapline-replace"><?php esc_html_e( 'Replace with', 'swapline' ); ?></label></th>
					<td><textarea id="swapline-replace" name="replace" class="large-text code" rows="2" spellcheck="false"></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tables', 'swapline' ); ?></th>
					<td>
						<div class="swapline-table-tools">
							<button type="button" class="button" data-select="all"><?php esc_html_e( 'Select all', 'swapline' ); ?></button>
							<button type="button" class="button" data-select="core"><?php esc_html_e( 'Core tables only', 'swapline' ); ?></button>
							<button type="button" class="button" data-select="none"><?php esc_html_e( 'Clear', 'swapline' ); ?></button>
							<label for="swapline-table-filter" class="screen-reader-text"><?php esc_html_e( 'Filter tables', 'swapline' ); ?></label>
							<?php if ( $multisite && count( $sites ) > 2 ) : ?>
								<label for="swapline-site-filter" class="screen-reader-text"><?php esc_html_e( 'Show tables of one site', 'swapline' ); ?></label>
								<select id="swapline-site-filter">
									<option value=""><?php esc_html_e( 'All sites', 'swapline' ); ?></option>
									<?php foreach ( $sites as $site_id => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $site_id ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
							<input type="search" id="swapline-table-filter" placeholder="<?php esc_attr_e( 'Filter tables', 'swapline' ); ?>">
							<span id="swapline-selected-count" class="description"></span>
						</div>
						<div class="swapline-tables" tabindex="0" role="group" aria-label="<?php esc_attr_e( 'Tables', 'swapline' ); ?>">
							<table class="widefat striped">
								<thead>
									<tr>
										<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'swapline' ); ?></span></td>
										<th scope="col"><?php esc_html_e( 'Table', 'swapline' ); ?></th>
										<?php if ( $multisite ) : ?>
											<th scope="col"><?php esc_html_e( 'Site', 'swapline' ); ?></th>
										<?php endif; ?>
										<th scope="col" class="num"><?php esc_html_e( 'Rows', 'swapline' ); ?></th>
										<th scope="col" class="num"><?php esc_html_e( 'Size', 'swapline' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $tables as $table ) : ?>
										<tr data-core="<?php echo $table['core'] ? '1' : '0'; ?>" data-site="<?php echo esc_attr( (string) $table['site'] ); ?>" data-name="<?php echo esc_attr( $table['name'] ); ?>">
											<th scope="row" class="check-column">
												<input type="checkbox" name="tables[]" value="<?php echo esc_attr( $table['name'] ); ?>" id="swapline-t-<?php echo esc_attr( md5( $table['name'] ) ); ?>">
											</th>
											<td><label for="swapline-t-<?php echo esc_attr( md5( $table['name'] ) ); ?>"><code><?php echo esc_html( $table['name'] ); ?></code></label></td>
											<?php if ( $multisite ) : ?>
												<td><?php echo esc_html( $sites[ $table['site'] ] ?? '' ); ?></td>
											<?php endif; ?>
											<td class="num"><?php echo esc_html( number_format_i18n( $table['rows'] ) ); ?></td>
											<td class="num"><?php echo esc_html( size_format( $table['size'], 1 ) ? size_format( $table['size'], 1 ) : '0 B' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<p class="description"><?php esc_html_e( 'Row counts are estimates from MySQL. Sizes include indexes.', 'swapline' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'swapline' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Options', 'swapline' ); ?></legend>
							<label><input type="checkbox" name="case_insensitive" value="1"> <?php esc_html_e( 'Case-insensitive', 'swapline' ); ?></label><br>
							<label><input type="checkbox" name="regex" value="1"> <?php esc_html_e( 'Regular expression', 'swapline' ); ?></label><br>
							<p class="description swapline-regex-help"><?php esc_html_e( 'Enter the pattern without delimiters, for example https?://old\.example\.com. Use $1, $2 in the replacement for captured groups.', 'swapline' ); ?></p>
							<label><input type="checkbox" name="skip_guid" value="1" checked> <?php esc_html_e( 'Skip the guid column', 'swapline' ); ?></label><br>
							<label><input type="checkbox" name="dry_run" value="1" checked> <?php esc_html_e( 'Dry run: report what would change without writing anything', 'swapline' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="swapline-page-size"><?php esc_html_e( 'Max page size', 'swapline' ); ?></label></th>
					<td>
						<input type="number" id="swapline-page-size" name="page_size" class="small-text" min="<?php echo esc_attr( (string) Config::MIN_PAGE_SIZE ); ?>" max="<?php echo esc_attr( (string) Config::MAX_PAGE_SIZE ); ?>" step="1" value="<?php echo esc_attr( (string) Config::DEFAULT_PAGE_SIZE ); ?>">
						<p class="description"><?php esc_html_e( 'Rows processed per request. Lower it if runs time out on your host.', 'swapline' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary" id="swapline-run"><?php esc_html_e( 'Run dry run', 'swapline' ); ?></button>
				<button type="button" class="button hidden" id="swapline-cancel"><?php esc_html_e( 'Cancel', 'swapline' ); ?></button>
			</p>

			<div class="swapline-save-profile">
				<label for="swapline-profile-name"><?php esc_html_e( 'Save these settings as a profile', 'swapline' ); ?></label>
				<input type="text" id="swapline-profile-name" class="regular-text" maxlength="100">
				<button type="button" class="button" id="swapline-profile-save"><?php esc_html_e( 'Save profile', 'swapline' ); ?></button>
			</div>
		</form>

		<div id="swapline-progress" class="hidden">
			<progress max="100" value="0"></progress>
			<p class="swapline-progress-text" aria-live="polite"></p>
		</div>

		<div id="swapline-results" class="hidden"></div>
		<div id="swapline-changes" class="hidden"></div>

		<dialog id="swapline-confirm" aria-labelledby="swapline-confirm-title">
			<form method="dialog">
				<h2 id="swapline-confirm-title"><?php esc_html_e( 'Run the search and replace?', 'swapline' ); ?></h2>
				<p><?php esc_html_e( 'This writes to the database and cannot be undone.', 'swapline' ); ?></p>
				<p><label><input type="checkbox" id="swapline-confirm-backup" checked> <?php esc_html_e( 'Back up the selected tables first', 'swapline' ); ?></label></p>
				<p class="swapline-dialog-actions">
					<button type="submit" class="button" value="cancel"><?php esc_html_e( 'Cancel', 'swapline' ); ?></button>
					<button type="submit" class="button button-primary" value="run"><?php esc_html_e( 'Run', 'swapline' ); ?></button>
				</p>
			</form>
		</dialog>
		<?php
	}

	public static function render_history(): void {
		$runs = History::runs();
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of runs kept, 2: number of changed cells recorded per run. */
					_n( 'The last %1$d run is kept. Up to %2$s changed cells are recorded per run.', 'The last %1$d runs are kept. Up to %2$s changed cells are recorded per run.', History::size(), 'swapline' ),
					History::size(),
					number_format_i18n( History::max_recorded_changes() )
				)
			);
			?>
		</p>
		<?php if ( array() === $runs ) : ?>
			<p><?php esc_html_e( 'No runs yet.', 'swapline' ); ?></p>
			<?php
			return;
		endif;
		?>
		<div class="swapline-scroll">
		<table class="widefat striped swapline-history">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'swapline' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Search', 'swapline' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Replace', 'swapline' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Mode', 'swapline' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Rows', 'swapline' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Cells', 'swapline' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'swapline' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $runs as $run ) : ?>
					<tr>
						<td>
							<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['started'] ) ); ?>
							<br><span class="description"><?php echo esc_html( trim( $run['user'] . ' · ' . $run['source'], ' ·' ) ); ?></span>
						</td>
						<td><code><?php echo esc_html( self::excerpt( (string) $run['config']['search'] ) ); ?></code></td>
						<td><code><?php echo esc_html( self::excerpt( (string) $run['config']['replace'] ) ); ?></code></td>
						<td>
							<?php echo esc_html( $run['config']['dry_run'] ? __( 'Dry run', 'swapline' ) : __( 'Live', 'swapline' ) ); ?>
							<?php if ( 'cancelled' === $run['status'] ) : ?>
								<br><span class="description"><?php esc_html_e( 'Cancelled', 'swapline' ); ?></span>
							<?php elseif ( 'running' === $run['status'] ) : ?>
								<br><span class="description"><?php esc_html_e( 'Unfinished', 'swapline' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $run['totals']['rows_matched'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $run['totals']['cells_changed'] ) ); ?></td>
						<td><button type="button" class="button" data-run="<?php echo esc_attr( $run['id'] ); ?>"><?php esc_html_e( 'View changes', 'swapline' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<script type="application/json" id="swapline-history-data"><?php echo wp_json_encode( $runs, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		<div id="swapline-results" class="hidden"></div>
		<div id="swapline-changes" class="hidden"></div>
		<?php
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private static function site_labels( array $ids ): array {
		$labels = array( 0 => __( 'Network', 'swapline' ) );
		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$site          = get_site( $id );
			$labels[ $id ] = $site ? untrailingslashit( $site->domain . $site->path ) : sprintf( /* translators: %d: site ID. */ __( 'Site %d', 'swapline' ), $id );
		}
		return $labels;
	}

	private static function excerpt( string $value ): string {
		return mb_strlen( $value ) > 60 ? mb_substr( $value, 0, 57 ) . '…' : $value;
	}
}
