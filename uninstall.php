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
