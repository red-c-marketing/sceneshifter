<?php
/**
 * Removes Sceneshifter options and capabilities when the plugin is deleted.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sceneshifter_registry' );
delete_option( 'sceneshifter_post_types' );
delete_option( 'sceneshifter_check' );
delete_option( 'sceneshifter_push' );
delete_option( 'sceneshifter_scan_state' );
delete_option( 'sceneshifter_report_scan' );
delete_option( 'sceneshifter_scan_lock' );
delete_option( 'sceneshifter_db_version' );
delete_option( 'sceneshifter_caps_version' );
delete_option( 'sceneshifter_schema_version' );
delete_option( 'sceneshifter_keys' );
delete_option( 'sceneshifter_pairings' );
delete_option( 'sceneshifter_pairing_codes' );
delete_option( 'sceneshifter_allow_dev_http' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sceneshifter_scan_results" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sceneshifter_nonces" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sceneshifter_map" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sceneshifter_snapshots" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sceneshifter_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'sceneshifter_inventory_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_post_meta_by_key( '_sceneshifter_uuid' );

// Partial media uploads that never finished.
$sceneshifter_uploads = wp_upload_dir( null, false );
$sceneshifter_tmp     = trailingslashit( $sceneshifter_uploads['basedir'] ) . 'sceneshifter-tmp';
if ( is_dir( $sceneshifter_tmp ) ) {
	foreach ( array_merge( (array) glob( $sceneshifter_tmp . '/*.part' ), array( $sceneshifter_tmp . '/index.php', $sceneshifter_tmp . '/.htaccess' ) ) as $sceneshifter_file ) {
		wp_delete_file( (string) $sceneshifter_file );
	}
	rmdir( $sceneshifter_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

wp_unschedule_hook( 'sceneshifter_scheduled_scan' );
wp_unschedule_hook( 'sceneshifter_scan_batch' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'sceneshifter' );
}

$sceneshifter_role = get_role( 'administrator' );
if ( $sceneshifter_role ) {
	$sceneshifter_role->remove_cap( 'sceneshifter_transfer' );
	$sceneshifter_role->remove_cap( 'sceneshifter_view' );
}
