<?php
/**
 * Plugin bootstrap: works out which environment this is and wires up the
 * features that should run.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter;

use RedC\Sceneshifter\Admin\AdminBar;
use RedC\Sceneshifter\Admin\HeldNotices;
use RedC\Sceneshifter\Admin\LogPage;
use RedC\Sceneshifter\Admin\Notices;
use RedC\Sceneshifter\Admin\PullPage;
use RedC\Sceneshifter\Admin\PushPage;
use RedC\Sceneshifter\Admin\RegistryTransfer;
use RedC\Sceneshifter\Admin\SettingsPage;
use RedC\Sceneshifter\Check\CheckPage;
use RedC\Sceneshifter\Check\ClassicMetaBox;
use RedC\Sceneshifter\Check\EditorAssets;
use RedC\Sceneshifter\Check\ListIntegration;
use RedC\Sceneshifter\Check\PublishGuard;
use RedC\Sceneshifter\Check\RestController;
use RedC\Sceneshifter\Cli\Command;
use RedC\Sceneshifter\Cli\MapCommand;
use RedC\Sceneshifter\Cli\PullCommand;
use RedC\Sceneshifter\Cli\PushCommand;
use RedC\Sceneshifter\Pairing\SecretMaterial;
use RedC\Sceneshifter\Pairing\Sisters;
use RedC\Sceneshifter\Registry\Identity;
use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Scanner\ContentScanner;
use RedC\Sceneshifter\Scanner\PostScanner;
use RedC\Sceneshifter\Scanner\SeverityPolicy;
use RedC\Sceneshifter\SiteScan\ListColumn;
use RedC\Sceneshifter\SiteScan\ReportPage;
use RedC\Sceneshifter\SiteScan\ScanController;
use RedC\Sceneshifter\SiteScan\ScheduledScan;
use RedC\Sceneshifter\SiteScan\SiteScanner;
use RedC\Sceneshifter\Store\DbNonceStore;
use RedC\Sceneshifter\Store\Options;
use RedC\Sceneshifter\Store\WpOptionStore;
use RedC\Sceneshifter\SyncMap\DbMapStore;
use RedC\Sceneshifter\SyncMap\InventoryCache;
use RedC\Sceneshifter\SyncMap\SyncMap;
use RedC\Sceneshifter\SyncMap\Uuid;
use RedC\Sceneshifter\SyncMap\WpContentIndex;
use RedC\Sceneshifter\Log\DbLogStore;
use RedC\Sceneshifter\Log\Retention;
use RedC\Sceneshifter\Transfer\DbSnapshotStore;
use RedC\Sceneshifter\Transfer\CopiedMedia;
use RedC\Sceneshifter\Transfer\ExportResponder;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PullRunner;
use RedC\Sceneshifter\Transfer\HeldUpdates;
use RedC\Sceneshifter\Transfer\Rollback;
use RedC\Sceneshifter\Transfer\ImportResponder;
use RedC\Sceneshifter\Transfer\MediaReceiver;
use RedC\Sceneshifter\Transfer\Packager;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PushPreview;
use RedC\Sceneshifter\Transfer\PushRunner;
use RedC\Sceneshifter\Transfer\WpMediaFiles;
use RedC\Sceneshifter\Transfer\WpMediaSource;
use RedC\Sceneshifter\Transfer\WpPostWriter;
use RedC\Sceneshifter\Transfer\WpSiteFacts;
use RedC\Sceneshifter\Transfer\WpTargetLookup;
use RedC\Sceneshifter\Transport\SignedEndpoints;
use RedC\Sceneshifter\Url\UrlClassifier;

final class Plugin {

	public const CAP_TRANSFER = 'sceneshifter_transfer';
	public const CAP_VIEW     = 'sceneshifter_view';

	public const CAPS_VERSION        = '1';
	public const CAPS_VERSION_OPTION = 'sceneshifter_caps_version';

	/**
	 * Version of the package format sisters exchange. Sisters with a
	 * different format refuse each other's packages.
	 */
	public const PACKAGE_FORMAT = '1';

	/** Statuses another environment can pull. */
	public const PULLABLE_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	private static ?self $instance = null;

	private Options $options;
	private Registry $registry;
	private Identity $identity;
	private ?ContentScanner $contentScanner = null;
	private ?PostScanner $postScanner = null;
	private ?SiteScanner $siteScanner = null;
	private ?Sisters $sisters = null;
	private ?SyncMap $syncMap = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->options = new Options();
	}

	/**
	 * Version string for a file under assets/: its modification time, so a
	 * deploy that changes the file also changes its URL and browsers don't
	 * keep the old copy. Falls back to the plugin version.
	 */
	public static function assetVersion( string $relativePath ): string {
		$mtime = @filemtime( SCENESHIFTER_DIR . $relativePath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $mtime ? SCENESHIFTER_VERSION : SCENESHIFTER_VERSION . '.' . $mtime;
	}

	public static function activate(): void {
		self::grantCapabilities();
		Upgrade::run();
	}

	/**
	 * Gives administrators the plugin's capabilities. Runs on activation, and
	 * once on load (from Upgrade::maybeRun()) for installs that never
	 * activate, such as must-use plugins. The version option stops it
	 * re-adding a capability that someone later removed on purpose.
	 */
	public static function grantCapabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAP_TRANSFER );
			$role->add_cap( self::CAP_VIEW );
		}
		update_option( self::CAPS_VERSION_OPTION, self::CAPS_VERSION, true );
	}

	public function boot(): void {
		Upgrade::maybeRun();

		$this->registry = $this->options->registry();
		$this->identity = $this->registry->resolveCurrent( home_url(), self::requestHost() );

		// Registered even when inactive, so a queued batch can record that
		// the scan stopped instead of failing with no handler.
		$this->siteScanner()->register();

		// Always on, so a site with no list yet can receive one.
		( new RegistryTransfer( $this ) )->register();
		( new ScheduledScan( $this, $this->siteScanner() ) )->register();
		( new Retention( $this ) )->register();

		// Always registered: when this site is inactive the gate refuses
		// every signed request with the same plain 404.
		( new SignedEndpoints( $this ) )->register();

		// Duplicate Post (Yoast) must not copy the sync map UUID to the copy.
		add_filter( 'duplicate_post_excludelist_filter', array( Uuid::class, 'excludeFromCopies' ) );
		add_filter( 'duplicate_post_blacklist_filter', array( Uuid::class, 'excludeFromCopies' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sceneshifter', new Command( $this ) );
			\WP_CLI::add_command( 'sceneshifter map', new MapCommand( $this ) );
			$push = new PushCommand( $this );
			\WP_CLI::add_command( 'sceneshifter push', array( $push, 'push' ) );
			\WP_CLI::add_command( 'sceneshifter log', array( $push, 'log' ) );
			\WP_CLI::add_command( 'sceneshifter rollback', array( $push, 'rollback' ) );
			\WP_CLI::add_command( 'sceneshifter apply', array( $push, 'apply' ) );
			\WP_CLI::add_command( 'sceneshifter discard', array( $push, 'discard' ) );
			$pull = new PullCommand( $this );
			\WP_CLI::add_command( 'sceneshifter pull', array( $pull, 'pull' ) );
			\WP_CLI::add_command( 'sceneshifter search', array( $pull, 'search' ) );
		}

		if ( is_admin() ) {
			( new SettingsPage( $this ) )->register();
			( new Notices( $this ) )->register();
		}
		( new AdminBar( $this ) )->register();

		if ( ! $this->identity->isActive() ) {
			return;
		}

		( new RestController( $this ) )->register();
		( new ScanController( $this->siteScanner() ) )->register();
		( new PublishGuard( $this ) )->register();
		if ( is_admin() ) {
			( new CheckPage( $this ) )->register();
			( new ReportPage( $this, $this->siteScanner() ) )->register();
			( new ListIntegration( $this ) )->register();
			( new ListColumn( $this ) )->register();
			( new ClassicMetaBox( $this ) )->register();
			( new EditorAssets( $this ) )->register();
			( new PushPage( $this ) )->register();
			( new PullPage( $this ) )->register();
			( new LogPage( $this ) )->register();
			( new HeldNotices( $this ) )->register();
		}
	}

	public function options(): Options {
		return $this->options;
	}

	public function registry(): Registry {
		return $this->registry;
	}

	public function identity(): Identity {
		return $this->identity;
	}

	/**
	 * Only call when identity()->isActive() is true.
	 */
	public function contentScanner(): ContentScanner {
		if ( null === $this->contentScanner ) {
			$site = $this->identity->site;
			if ( null === $site || ! $this->identity->isActive() ) {
				throw new \LogicException( 'Sceneshifter is inactive: this site is not identified in the registry.' );
			}
			$this->contentScanner = new ContentScanner(
				new UrlClassifier( $this->registry, $site ),
				new SeverityPolicy( $this->options->checkSettings() )
			);
		}
		return $this->contentScanner;
	}

	/**
	 * Only call when identity()->isActive() is true.
	 */
	public function postScanner(): PostScanner {
		return $this->postScanner ??= new PostScanner( $this->contentScanner() );
	}

	/**
	 * Keys, pairings and the signed transport for this site.
	 */
	public function sisters(): Sisters {
		return $this->sisters ??= new Sisters(
			$this->identity->isActive() ? $this->identity->site : null,
			$this->registry,
			new WpOptionStore(),
			DbNonceStore::forSite(),
			SecretMaterial::fromConstants(),
			$this->options->allowDevHttp()
		);
	}

	/**
	 * The sync map and Link existing content, or null when this site is
	 * inactive.
	 */
	public function syncMap(): ?SyncMap {
		$self = $this->sisters()->self();
		if ( null === $self ) {
			return null;
		}
		return $this->syncMap ??= new SyncMap(
			new WpContentIndex( $this->options->postTypes() ),
			DbMapStore::forSite( $self->id ),
			new InventoryCache( new WpOptionStore() ),
			$this->registry,
			$this->sisters()->transport()
		);
	}

	/**
	 * Target side of a push: answers POST /preflight. Null when inactive.
	 */
	public function preflightResponder(): ?PreflightResponder {
		$syncMap = $this->syncMap();
		$self    = $this->sisters()->self();
		if ( null === $syncMap || null === $self ) {
			return null;
		}
		return new PreflightResponder( self::PACKAGE_FORMAT, $syncMap->map(), new WpTargetLookup(), new WpSiteFacts( new Packager( $self ) ) );
	}

	/**
	 * Target side of a push, and of a pull started here: answers POST
	 * /import. Null when inactive.
	 *
	 * @param bool|null $hold Hold updates to published posts for review; null for this site's Push setting.
	 */
	public function importResponder( ?bool $hold = null ): ?ImportResponder {
		$syncMap = $this->syncMap();
		$self    = $this->sisters()->self();
		if ( null === $syncMap || null === $self ) {
			return null;
		}
		return new ImportResponder(
			self::PACKAGE_FORMAT,
			$syncMap->map(),
			new WpTargetLookup(),
			new WpSiteFacts( new Packager( $self ) ),
			$this->postWriter(),
			DbSnapshotStore::forSite( $self->id ),
			DbLogStore::forSite( $self->id ),
			fn( int $id ): array => $this->findings( $id ),
			null,
			$hold ?? $this->options->pushSettings()->holdsUpdates()
		);
	}

	/**
	 * Undoes pushes this site received. Null when inactive.
	 */
	public function rollback(): ?Rollback {
		$syncMap = $this->syncMap();
		$self    = $this->sisters()->self();
		if ( null === $syncMap || null === $self ) {
			return null;
		}
		return new Rollback( $syncMap->map(), DbSnapshotStore::forSite( $self->id ), $this->postWriter(), new WpSiteFacts( new Packager( $self ) ), DbLogStore::forSite( $self->id ), new CopiedMedia( $this->mediaFiles(), $syncMap->map() ) );
	}

	/**
	 * Applies or discards pushed updates held for review here. Null when inactive.
	 */
	public function heldUpdates(): ?HeldUpdates {
		$syncMap = $this->syncMap();
		$self    = $this->sisters()->self();
		if ( null === $syncMap || null === $self ) {
			return null;
		}
		return new HeldUpdates( $syncMap->map(), DbSnapshotStore::forSite( $self->id ), $this->postWriter(), new WpSiteFacts( new Packager( $self ) ), DbLogStore::forSite( $self->id ), new CopiedMedia( $this->mediaFiles(), $syncMap->map() ) );
	}

	/**
	 * Writes pushed posts, with this site's default author.
	 */
	public function postWriter(): WpPostWriter {
		return new WpPostWriter( $this->options->pushSettings()->defaultAuthor );
	}

	/**
	 * Files for media pushed here, renaming clashes if this site is set to.
	 */
	public function mediaFiles(): WpMediaFiles {
		return new WpMediaFiles( $this->options->pushSettings()->renamesClashes() );
	}

	/**
	 * This site's transfer log. Null when inactive.
	 */
	public function log(): ?DbLogStore {
		$self = $this->sisters()->self();
		return null === $self ? null : DbLogStore::forSite( $self->id );
	}

	/**
	 * Target side of a push: receives media. Null when inactive.
	 */
	public function mediaReceiver(): ?MediaReceiver {
		$syncMap = $this->syncMap();
		return null === $syncMap ? null : new MediaReceiver( $syncMap->map(), $this->mediaFiles() );
	}

	/**
	 * Source side of a push: pushes one post. Null when inactive.
	 */
	public function pushRunner(): ?PushRunner {
		$preview = $this->pushPreview();
		$self    = $this->sisters()->self();
		$syncMap = $this->syncMap();
		if ( null === $preview || null === $self || null === $syncMap ) {
			return null;
		}
		$packager = new Packager( $self );
		return new PushRunner(
			$this->registry,
			$this->sisters()->transport(),
			self::PACKAGE_FORMAT,
			$preview,
			array( $packager, 'build' ),
			array( self::class, 'ensureUuid' ),
			$syncMap->map(),
			new WpMediaSource(),
			DbLogStore::forSite( $self->id )
		);
	}

	/**
	 * Gives a post a Sceneshifter ID if it has none, and returns it.
	 */
	public static function ensureUuid( int $id ): ?string {
		if ( null === get_post( $id ) ) {
			return null;
		}
		$uuid = get_post_meta( $id, Uuid::META_KEY, true );
		if ( Uuid::isValid( $uuid ) ) {
			return $uuid;
		}
		$uuid = Uuid::generate();
		update_post_meta( $id, Uuid::META_KEY, $uuid );
		return $uuid;
	}

	/**
	 * Source side of a pull: answers the site that is pulling. Null when inactive.
	 */
	public function exportResponder(): ?ExportResponder {
		$self    = $this->sisters()->self();
		$syncMap = $this->syncMap();
		if ( null === $self || null === $syncMap ) {
			return null;
		}
		$packager = new Packager( $self );
		$types    = $this->options->postTypes();
		return new ExportResponder(
			self::PACKAGE_FORMAT,
			static function ( string $query, string $type, int $page, int $perPage ) use ( $types ): array {
				$args  = array(
					'post_type'              => '' !== $type && in_array( $type, $types, true ) ? $type : $types,
					'post_status'            => self::PULLABLE_STATUSES,
					'posts_per_page'         => $perPage,
					'paged'                  => $page,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					// Updates waiting for review are copies, not content of their own.
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => WpPostWriter::HELD_FOR,
							'compare' => 'NOT EXISTS',
						),
					),
				);
				if ( '' !== $query ) {
					$args['s'] = $query;
				}
				$found = new \WP_Query( $args );
				$items = array();
				foreach ( $found->posts as $post ) {
					if ( $post instanceof \WP_Post ) {
						$items[] = array(
							'id'       => $post->ID,
							'title'    => get_the_title( $post ),
							'type'     => $post->post_type,
							'status'   => $post->post_status,
							'modified' => (string) get_post_modified_time( 'c', true, $post ),
							'path'     => WpContentIndex::path( $post ),
						);
					}
				}
				return array(
					'items' => $items,
					'total' => (int) $found->found_posts,
				);
			},
			static function ( int $id ) use ( $packager, $types ): ?Package {
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $types, true ) || ! in_array( $post->post_status, self::PULLABLE_STATUSES, true ) || '' !== (string) get_post_meta( $id, WpPostWriter::HELD_FOR, true ) ) {
					return null;
				}
				return $packager->build( $id );
			},
			static function ( int $id ) use ( $types ): ?string {
				$post = get_post( $id );
				return $post instanceof \WP_Post && in_array( $post->post_type, $types, true ) ? self::ensureUuid( $id ) : null;
			},
			fn( int $id ): array => $this->findings( $id ),
			new WpMediaSource(),
			$syncMap->map(),
			DbLogStore::forSite( $self->id )
		);
	}

	/**
	 * Pulls posts from another environment into this site. Null when inactive.
	 */
	public function pullRunner(): ?PullRunner {
		$self      = $this->sisters()->self();
		$preflight = $this->preflightResponder();
		$receiver  = $this->mediaReceiver();
		$importer  = $this->importResponder( false );
		if ( null === $self || null === $preflight || null === $receiver || null === $importer || ! $this->identity->isActive() ) {
			return null;
		}
		return new PullRunner(
			$self,
			$this->registry,
			$this->sisters()->transport(),
			self::PACKAGE_FORMAT,
			$preflight,
			$receiver,
			$importer,
			DbLogStore::forSite( $self->id ),
			array( Packager::class, 'parse' ),
			array( Packager::class, 'serialize' )
		);
	}

	/**
	 * Links to other environments in a post.
	 *
	 * @return list<\RedC\Sceneshifter\Scanner\Finding>
	 */
	public function findings( int $id ): array {
		$post = get_post( $id );
		return $post instanceof \WP_Post ? $this->postScanner()->scan( $post ) : array();
	}

	/**
	 * Source side of a push: the preview. Null when inactive.
	 */
	public function pushPreview(): ?PushPreview {
		$self = $this->sisters()->self();
		if ( null === $self || ! $this->identity->isActive() ) {
			return null;
		}
		$packager = new Packager( $self );
		return new PushPreview(
			$self,
			$this->registry,
			$this->sisters()->transport(),
			self::PACKAGE_FORMAT,
			array( $packager, 'build' ),
			fn( int $id ): array => $this->findings( $id ),
			array( Packager::class, 'parse' ),
			array( Packager::class, 'serialize' )
		);
	}

	public function siteScanner(): SiteScanner {
		return $this->siteScanner ??= new SiteScanner( $this );
	}

	/**
	 * The Host header of a web request, or null for CLI and cron where there
	 * is no request to compare against.
	 */
	private static function requestHost(): ?string {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || empty( $_SERVER['HTTP_HOST'] ) ) {
			return null;
		}
		return sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) );
	}
}
