<?php
/**
 * Runs the site-wide scan in batches.
 *
 * Each call to advance() does one batch: 50 posts, or (once posts are done)
 * the menus, widgets, ACF options and theme settings. Batches are driven by
 * Action Scheduler in the background and also by the open report page, so a
 * scan still finishes on hosts where background jobs don't run. A database
 * lock makes sure only one batch runs at a time, and re-running a batch after
 * a crash can't double-count.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\SiteScan;

use RedC\SisterSync\Plugin;
use RedC\SisterSync\Scanner\TextChunk;
use Throwable;

final class SiteScanner {

	public const HOOK  = 'sister_sync_scan_batch';
	public const GROUP = 'sister-sync';

	/**
	 * Block-theme content scanned even when not enabled for Check, since the
	 * report should show the full extent of the problem.
	 */
	public const EXTRA_POST_TYPES = array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' );

	private const BATCH_SIZE  = 50;
	private const LOCK_OPTION = 'sister_sync_scan_lock';
	private const LOCK_TTL    = 120;

	private const SKIPPED_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	private const SKIPPED_THEME_MODS = array( 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id', 'custom_logo' );

	public function __construct(
		private readonly Plugin $plugin,
		private readonly ResultStore $store = new ResultStore()
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'handleAction' ) );
	}

	public static function queueAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Starts a new scan, replacing any scan already running.
	 */
	public function start( int $userId ): ScanState {
		$previous = ScanState::load();
		if ( null !== $previous && $previous->isRunning() ) {
			$this->store->discard( $previous->id );
		}
		$this->unschedule();

		$types = $this->postTypes();
		$state = new ScanState(
			wp_generate_uuid4(),
			ScanState::RUNNING,
			ScanState::PHASE_POSTS,
			$types,
			$this->countPosts( $types ),
			0,
			0,
			$userId,
			time()
		);
		$state->save();
		$this->queueNext( $state );

		return $state;
	}

	public function cancel(): ?ScanState {
		$state = ScanState::load();
		if ( null === $state || ! $state->isRunning() ) {
			return $state;
		}
		$state->status     = ScanState::CANCELLED;
		$state->finishedAt = time();
		$state->save();
		$this->unschedule();
		$this->store->discard( $state->id );
		return $state;
	}

	/**
	 * Action Scheduler callback.
	 */
	public function handleAction( string $scanId = '' ): void {
		$state = $this->advance( $scanId );
		if ( null !== $state && $state->isRunning() ) {
			$this->queueNext( $state );
		}
	}

	/**
	 * Runs one batch of the running scan, if there is one and no other batch
	 * holds the lock. Safe to call from anywhere, any number of times.
	 */
	public function advance( string $scanId = '' ): ?ScanState {
		$state = ScanState::load();
		if ( null === $state || ! $state->isRunning() || ( '' !== $scanId && $scanId !== $state->id ) ) {
			return $state;
		}
		if ( ! $this->acquireLock() ) {
			return $state;
		}

		// Another batch may have finished between the first read and the lock.
		wp_cache_delete( ScanState::OPTION, 'options' );
		$state = ScanState::load();
		if ( null === $state || ! $state->isRunning() || ( '' !== $scanId && $scanId !== $state->id ) ) {
			$this->releaseLock();
			return $state;
		}

		try {
			if ( ! $this->plugin->identity()->isActive() ) {
				throw new \RuntimeException( __( 'Sister Sync became inactive on this site, so the scan stopped.', 'sister-sync' ) );
			}

			if ( ScanState::PHASE_POSTS === $state->phase ) {
				$this->scanPostBatch( $state );
			} else {
				$this->store->clearNonPosts( $state->id );
				$this->scanMenus( $state->id );
				$this->scanWidgets( $state->id );
				$this->scanAcfOptions( $state->id );
				$this->scanThemeMods( $state->id );

				$state->status     = ScanState::COMPLETE;
				$state->finishedAt = time();
				$state->save();
				$this->store->publish( $state->id );
			}
		} catch ( Throwable $e ) {
			$state->status     = ScanState::FAILED;
			$state->error      = $e->getMessage();
			$state->finishedAt = time();
			$state->save();
			$this->store->discard( $state->id );
		} finally {
			$this->releaseLock();
		}

		return $state;
	}

	/**
	 * Enabled content types plus block-theme content.
	 *
	 * @return list<string>
	 */
	public function postTypes(): array {
		return array_values(
			array_unique(
				array_merge(
					$this->plugin->options()->postTypes(),
					array_filter( self::EXTRA_POST_TYPES, 'post_type_exists' )
				)
			)
		);
	}

	private function scanPostBatch( ScanState $state ): void {
		$ids     = $this->nextPostIds( $state->postTypes, $state->lastPostId );
		$scanner = $this->plugin->postScanner();

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$this->store->clearObject( $state->id, ScannedObject::POST, $id );
				$this->store->insert(
					$state->id,
					new ScannedObject(
						ScannedObject::POST,
						'' !== $post->post_title ? $post->post_title : __( '(no title)', 'sister-sync' ),
						$id,
						'',
						$post->post_type
					),
					$scanner->scan( $post )
				);
			}
			++$state->processed;
			$state->lastPostId = $id;
		}

		if ( count( $ids ) < self::BATCH_SIZE ) {
			$state->phase = ScanState::PHASE_EXTRAS;
		}
		$state->save();
	}

	/**
	 * Custom links in navigation menus. Links to posts and pages are built
	 * from this site's permalinks, so they can't point at a sister.
	 */
	private function scanMenus( string $scanId ): void {
		$scanner = $this->plugin->contentScanner();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( 'custom' !== ( $item->type ?? '' ) ) {
					continue;
				}
				/* translators: %s: menu name. */
				$label    = sprintf( __( 'Menu "%s"', 'sister-sync' ), $menu->name );
				$findings = $scanner->scanValue( TextChunk::AREA_MENU, $label, (string) $item->url );
				if ( array() !== $findings ) {
					$this->store->insert(
						$scanId,
						new ScannedObject( ScannedObject::MENU_ITEM, '' !== (string) $item->title ? (string) $item->title : (string) $item->url, (int) $item->ID, '', (string) $menu->term_id ),
						$findings
					);
				}
			}
		}
	}

	/**
	 * Every instance of every widget type, including block widgets.
	 */
	private function scanWidgets( string $scanId ): void {
		$scanner = $this->plugin->contentScanner();
		foreach ( $this->optionNames( 'widget\_%' ) as $name ) {
			$instances = get_option( $name );
			if ( ! is_array( $instances ) ) {
				continue;
			}
			$type = substr( $name, strlen( 'widget_' ) );
			foreach ( $instances as $number => $instance ) {
				if ( '_multiwidget' === $number || ! is_array( $instance ) ) {
					continue;
				}
				/* translators: 1: widget type, 2: widget number. */
				$label    = sprintf( __( 'Widget: %1$s #%2$s', 'sister-sync' ), $type, $number );
				$findings = $scanner->scanValue( TextChunk::AREA_WIDGET, $label, $instance );
				if ( array() !== $findings ) {
					$title = isset( $instance['title'] ) && '' !== $instance['title'] ? (string) $instance['title'] : $label;
					$this->store->insert(
						$scanId,
						new ScannedObject( ScannedObject::WIDGET, $title, 0, $name . '[' . $number . ']', $type ),
						$findings
					);
				}
			}
		}
	}

	/**
	 * ACF options pages store each field as an option named "options_{name}",
	 * with "_options_{name}" holding its field key.
	 */
	private function scanAcfOptions( string $scanId ): void {
		$scanner = $this->plugin->contentScanner();
		foreach ( $this->optionNames( 'options\_%' ) as $name ) {
			$label    = $this->acfOptionLabel( $name ) ?? $name;
			$findings = $scanner->scanValue( TextChunk::AREA_OPTION, $label, get_option( $name ) );
			if ( array() !== $findings ) {
				$this->store->insert( $scanId, new ScannedObject( ScannedObject::OPTION, $label, 0, $name, 'acf' ), $findings );
			}
		}
	}

	private function scanThemeMods( string $scanId ): void {
		$scanner = $this->plugin->contentScanner();
		$mods    = get_theme_mods();
		foreach ( is_array( $mods ) ? $mods : array() as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, self::SKIPPED_THEME_MODS, true ) ) {
				continue;
			}
			/* translators: %s: theme setting key. */
			$label    = sprintf( __( 'Theme setting: %s', 'sister-sync' ), $key );
			$findings = $scanner->scanValue( TextChunk::AREA_THEME_MOD, $label, $value );
			if ( array() !== $findings ) {
				$this->store->insert( $scanId, new ScannedObject( ScannedObject::THEME_MOD, $key, 0, $key, get_stylesheet() ), $findings );
			}
		}
	}

	private function acfOptionLabel( string $name ): ?string {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return null;
		}
		$fieldKey = get_option( '_' . $name );
		if ( ! is_string( $fieldKey ) || ! str_starts_with( $fieldKey, 'field_' ) ) {
			return null;
		}
		$field = acf_get_field( $fieldKey );
		return is_array( $field ) && ! empty( $field['label'] ) ? 'ACF option: ' . $field['label'] : null;
	}

	/**
	 * @return list<string>
	 */
	private function optionNames( string $likePattern ): array {
		global $wpdb;
		return array_map(
			'strval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $likePattern ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * @param list<string> $types
	 * @return list<int>
	 */
	private function nextPostIds( array $types, int $afterId ): array {
		global $wpdb;
		if ( array() === $types ) {
			return array();
		}
		[ $typeSql, $statusSql ] = $this->postFilterSql( $types );
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE {$typeSql} AND {$statusSql} AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$afterId,
					self::BATCH_SIZE
				)
			)
		);
	}

	/**
	 * @param list<string> $types
	 */
	private function countPosts( array $types ): int {
		global $wpdb;
		if ( array() === $types ) {
			return 0;
		}
		[ $typeSql, $statusSql ] = $this->postFilterSql( $types );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$typeSql} AND {$statusSql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param list<string> $types
	 * @return array{0: string, 1: string} Prepared SQL fragments.
	 */
	private function postFilterSql( array $types ): array {
		global $wpdb;
		$typeSql   = $wpdb->prepare( 'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ); // phpcs:ignore WordPress.DB.PreparedSQL
		$statusSql = $wpdb->prepare( 'post_status NOT IN (' . implode( ',', array_fill( 0, count( self::SKIPPED_STATUSES ), '%s' ) ) . ')', self::SKIPPED_STATUSES ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( (string) $typeSql, (string) $statusSql );
	}

	/**
	 * Queues the next batch unless one is already pending. The batch that is
	 * running right now doesn't count, which is why this doesn't use
	 * as_has_scheduled_action().
	 */
	private function queueNext( ScanState $state ): void {
		if ( ! self::queueAvailable() ) {
			return;
		}
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( array() === $pending ) {
			as_enqueue_async_action( self::HOOK, array( 'scan_id' => $state->id ), self::GROUP );
		}
	}

	private function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * An atomic lock row in wp_options. add_option() can't be used: it reads
	 * first and then upserts, so two requests could both "win".
	 */
	private function acquireLock(): bool {
		global $wpdb;
		$now = time();

		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $held && (int) $held < $now ) {
			// A batch died without releasing the lock.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				self::LOCK_OPTION,
				(string) ( $now + self::LOCK_TTL )
			)
		);
		return 1 === $inserted;
	}

	private function releaseLock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
