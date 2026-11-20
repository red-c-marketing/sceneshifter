<?php
/**
 * Removes Sister Sync options and capabilities when the plugin is deleted.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sister_sync_registry' );
delete_option( 'sister_sync_post_types' );
delete_option( 'sister_sync_scan_state' );
delete_option( 'sister_sync_report_scan' );
delete_option( 'sister_sync_scan_lock' );
delete_option( 'sister_sync_db_version' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sistersync_scan_results" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'sister-sync' );
}

$sister_sync_role = get_role( 'administrator' );
if ( $sister_sync_role ) {
	$sister_sync_role->remove_cap( 'sistersync_transfer' );
	$sister_sync_role->remove_cap( 'sistersync_view' );
}
