<?php
/**
 * Plugin bootstrap: works out which environment this is and wires up the
 * features that should run.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync;

use RedC\SisterSync\Admin\AdminBar;
use RedC\SisterSync\Admin\Notices;
use RedC\SisterSync\Admin\RegistryTransfer;
use RedC\SisterSync\Admin\SettingsPage;
use RedC\SisterSync\Check\CheckPage;
use RedC\SisterSync\Check\ClassicMetaBox;
use RedC\SisterSync\Check\EditorAssets;
use RedC\SisterSync\Check\ListIntegration;
use RedC\SisterSync\Check\PublishGuard;
use RedC\SisterSync\Check\RestController;
use RedC\SisterSync\Cli\Command;
use RedC\SisterSync\Registry\Identity;
use RedC\SisterSync\Registry\Registry;
use RedC\SisterSync\Scanner\ContentScanner;
use RedC\SisterSync\Scanner\PostScanner;
use RedC\SisterSync\Scanner\SeverityPolicy;
use RedC\SisterSync\SiteScan\ListColumn;
use RedC\SisterSync\SiteScan\ReportPage;
use RedC\SisterSync\SiteScan\ScanController;
use RedC\SisterSync\SiteScan\ScheduledScan;
use RedC\SisterSync\SiteScan\Schema;
use RedC\SisterSync\SiteScan\SiteScanner;
use RedC\SisterSync\Store\Options;
use RedC\SisterSync\Url\UrlClassifier;

final class Plugin {

	public const CAP_TRANSFER = 'sistersync_transfer';
	public const CAP_VIEW     = 'sistersync_view';

	public const CAPS_VERSION        = '1';
	public const CAPS_VERSION_OPTION = 'sister_sync_caps_version';

	private static ?self $instance = null;

	private Options $options;
	private Registry $registry;
	private Identity $identity;
	private ?ContentScanner $contentScanner = null;
	private ?PostScanner $postScanner = null;
	private ?SiteScanner $siteScanner = null;

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
		$mtime = @filemtime( SISTER_SYNC_DIR . $relativePath ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $mtime ? SISTER_SYNC_VERSION : SISTER_SYNC_VERSION . '.' . $mtime;
	}

	public static function activate(): void {
		self::grantCapabilities();
		Schema::install();
	}

	/**
	 * Gives administrators the plugin's capabilities. Runs on activation, and
	 * once on load for installs that never activate, such as must-use
	 * plugins. The version option stops it re-adding a capability that
	 * someone later removed on purpose.
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
		Schema::maybeInstall();
		if ( get_option( self::CAPS_VERSION_OPTION ) !== self::CAPS_VERSION ) {
			self::grantCapabilities();
		}

		$this->registry = $this->options->registry();
		$this->identity = $this->registry->resolveCurrent( home_url(), self::requestHost() );

		// Registered even when inactive, so a queued batch can record that
		// the scan stopped instead of failing with no handler.
		$this->siteScanner()->register();

		// Always on, so a site with no list yet can receive one.
		( new RegistryTransfer( $this ) )->register();
		( new ScheduledScan( $this, $this->siteScanner() ) )->register();

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
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sistersync', new Command( $this ) );
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
				throw new \LogicException( 'Sister Sync is inactive: this site is not identified in the registry.' );
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
