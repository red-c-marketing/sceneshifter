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

$sister_sync_role = get_role( 'administrator' );
if ( $sister_sync_role ) {
	$sister_sync_role->remove_cap( 'sistersync_transfer' );
	$sister_sync_role->remove_cap( 'sistersync_view' );
}
