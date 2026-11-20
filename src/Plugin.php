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
use RedC\SisterSync\Admin\SettingsPage;
use RedC\SisterSync\Check\CheckPage;
use RedC\SisterSync\Check\ClassicMetaBox;
use RedC\SisterSync\Check\EditorAssets;
use RedC\SisterSync\Check\ListIntegration;
use RedC\SisterSync\Check\RestController;
use RedC\SisterSync\Cli\Command;
use RedC\SisterSync\Registry\Identity;
use RedC\SisterSync\Registry\Registry;
use RedC\SisterSync\Scanner\ContentScanner;
use RedC\SisterSync\Scanner\PostScanner;
use RedC\SisterSync\Store\Options;
use RedC\SisterSync\Url\UrlClassifier;

final class Plugin {

	public const CAP_TRANSFER = 'sistersync_transfer';
	public const CAP_VIEW     = 'sistersync_view';

	private static ?self $instance = null;

	private Options $options;
	private Registry $registry;
	private Identity $identity;
	private ?PostScanner $postScanner = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->options = new Options();
	}

	public static function activate(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAP_TRANSFER );
			$role->add_cap( self::CAP_VIEW );
		}
	}

	public function boot(): void {
		$this->registry = $this->options->registry();
		$this->identity = $this->registry->resolveCurrent( home_url(), self::requestHost() );

		if ( is_admin() ) {
			( new SettingsPage( $this ) )->register();
			( new Notices( $this ) )->register();
		}
		( new AdminBar( $this ) )->register();

		if ( ! $this->identity->isActive() ) {
			return;
		}

		( new RestController( $this ) )->register();
		if ( is_admin() ) {
			( new CheckPage( $this ) )->register();
			( new ListIntegration( $this ) )->register();
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
	public function postScanner(): PostScanner {
		if ( null === $this->postScanner ) {
			$site = $this->identity->site;
			if ( null === $site || ! $this->identity->isActive() ) {
				throw new \LogicException( 'Sister Sync is inactive: this site is not identified in the registry.' );
			}
			$this->postScanner = new PostScanner( new ContentScanner( new UrlClassifier( $this->registry, $site ) ) );
		}
		return $this->postScanner;
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
