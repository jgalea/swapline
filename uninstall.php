<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$swapline_dir_name = is_multisite() ? get_site_option( 'swapline_backup_dir' ) : get_option( 'swapline_backup_dir' );

foreach ( array( 'swapline_runs', 'swapline_profiles', 'swapline_job', 'swapline_db_version', 'swapline_backup_job', 'swapline_import_job', 'swapline_backups', 'swapline_backup_dir', 'swapline_backup_retention' ) as $swapline_option ) {
	if ( is_multisite() ) {
		delete_site_option( $swapline_option );
	} else {
		delete_option( $swapline_option );
	}
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}swapline_changes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'swapline_tmp_', 'swapline_old_' ) as $swapline_prefix ) {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $swapline_prefix ) . '%' ) ) as $swapline_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( preg_match( '/^swapline_(?:tmp|old)_[a-f0-9]{8}_\d+$/', $swapline_table ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $swapline_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- name matched against the plugin's own pattern.
		}
	}
}

if ( is_string( $swapline_dir_name ) && preg_match( '/^swapline-[a-f0-9]{20}$/', $swapline_dir_name ) ) {
	if ( is_multisite() ) {
		switch_to_blog( get_main_site_id() );
	}
	$swapline_uploads = wp_upload_dir( null, false );
	if ( is_multisite() ) {
		restore_current_blog();
	}

	$swapline_dir = untrailingslashit( (string) $swapline_uploads['basedir'] ) . '/' . $swapline_dir_name;
	if ( is_dir( $swapline_dir ) ) {
		foreach ( (array) scandir( $swapline_dir ) as $swapline_file ) {
			if ( is_file( $swapline_dir . '/' . $swapline_file ) ) {
				wp_delete_file( $swapline_dir . '/' . $swapline_file );
			}
		}
		rmdir( $swapline_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the plugin's own folder, emptied above.
	}
}
