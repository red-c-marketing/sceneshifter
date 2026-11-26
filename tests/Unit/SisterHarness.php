<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Crypto\MemoryNonceStore;
use RedC\Sceneshifter\Crypto\SignedRequest;
use RedC\Sceneshifter\Crypto\SignedResponse;
use RedC\Sceneshifter\Pairing\Handshake;
use RedC\Sceneshifter\Pairing\Sisters;
use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Store\MemoryKeyValueStore;
use RedC\Sceneshifter\SyncMap\InventoryCache;
use RedC\Sceneshifter\SyncMap\LinkExisting;
use RedC\Sceneshifter\SyncMap\MemoryMapStore;
use RedC\Sceneshifter\SyncMap\Responder;
use RedC\Sceneshifter\Log\MemoryLogStore;
use RedC\Sceneshifter\Transfer\ImportResponder;
use RedC\Sceneshifter\Transfer\CopiedMedia;
use RedC\Sceneshifter\Transfer\ExportResponder;
use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PullRunner;
use RedC\Sceneshifter\Transfer\HeldUpdates;
use RedC\Sceneshifter\Transfer\Rollback;
use RedC\Sceneshifter\Transfer\MediaReceiver;
use RedC\Sceneshifter\Transfer\MemorySnapshotStore;
use RedC\Sceneshifter\Transfer\PreflightResponder;

/**
 * A pretend site: its own stores and salts, a Sisters instance, and a fake
 * HTTP layer that hands requests to the other pretend sites the way
 * SignedEndpoints does on a real site.
 */
final class SisterHarness {

	public MemoryKeyValueStore $store;
	public MemoryNonceStore $nonces;
	public Sisters $sisters;
	public MemoryContentIndex $content;
	public MemoryMapStore $map;
	public MemoryTargetLookup $lookup;
	public FakeSiteFacts $facts;
	public MemoryPostWriter $writer;
	public MemorySnapshotStore $snapshots;
	public MemoryMediaFiles $mediaFiles;
	public MemoryLogStore $log;
	private Registry $registry;

	/** @var array<int, list<\RedC\Sceneshifter\Scanner\Finding>> Links to other environments found in a written post. */
	public array $findings = array();

	/** @var array<int, Package> Posts this site can be pulled from, by ID. */
	public array $packages = array();

	/** @var array<int, list<\RedC\Sceneshifter\Scanner\Finding>> Links to other environments in those posts. */
	public array $sourceFindings = array();

	/** Media this site can be pulled from. */
	public MemoryMediaSource $source;

	/** Hold pushed updates to published posts for review (Settings → Push). */
	public bool $holdUpdates = false;

	/** @var list<string> Routes this site answered, in order. */
	public array $served = array();

	/** @var array<string, self> by host */
	public static array $network = array();

	/** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
	public array $sent = array();

	public function __construct(
		public readonly string $siteId,
		public readonly string $salts,
		?Registry $registry = null,
		bool $active = true
	) {
		$registry     = $registry ?? Fixtures::registry();
		$this->store  = new MemoryKeyValueStore();
		$this->nonces = new MemoryNonceStore();
		$this->content = new MemoryContentIndex();
		$this->map     = new MemoryMapStore();
		$this->lookup  = new MemoryTargetLookup();
		$this->facts   = new FakeSiteFacts();
		$this->writer     = new MemoryPostWriter( $this->facts, $this->lookup );
		$this->snapshots  = new MemorySnapshotStore();
		$this->mediaFiles = new MemoryMediaFiles();
		$this->log        = new MemoryLogStore();
		$this->source     = new MemoryMediaSource();
		$this->boot( $registry, $active );
		$site = $registry->get( $siteId );
		if ( null !== $site ) {
			self::$network[ (string) parse_url( $site->url, PHP_URL_HOST ) ] = $this;
		}
	}

	/**
	 * Rebuilds Sisters over the same stores, as a new request would.
	 */
	public function boot( ?Registry $registry = null, bool $active = true ): void {
		$registry       = $registry ?? Fixtures::registry();
		$this->registry = $registry;
		$this->sisters  = new Sisters(
			$active ? $registry->get( $this->siteId ) : null,
			$registry,
			$this->store,
			$this->nonces,
			$this->salts,
			true,
			function ( string $method, string $url, array $headers, string $body ): array|string {
				$this->sent[] = compact( 'method', 'url', 'headers', 'body' );
				$target       = self::$network[ (string) parse_url( $url, PHP_URL_HOST ) ] ?? null;
				return null === $target ? 'Could not resolve host' : $target->serve( $method, $url, $headers, $body );
			}
		);
	}

	/**
	 * What SignedEndpoints does, without WordPress.
	 *
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function serve( string $method, string $url, array $headers, string $body ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$route = is_string( $query['rest_route'] ?? null ) ? $query['rest_route'] : '';
		$gate  = $this->sisters->gate();

		if ( Handshake::ROUTE === $route ) {
			$handshake = $gate->isOpen() ? $this->sisters->handshake() : null;
			$peer      = $handshake?->accept( $method, $route, $headers, $body, time() );
			return null === $peer || null === $handshake ? self::notFound() : $this->signed( $headers, $peer->siteId, $handshake->responseData( $peer ) );
		}

		$sender = $gate->verify( $method, $route, $headers, $body );
		if ( null === $sender ) {
			return self::notFound();
		}
		if ( Sisters::STATUS_ROUTE === $route && 'GET' === $method ) {
			return $this->signed(
				$headers,
				$sender,
				array(
					'site'             => $this->siteId,
					'plugin_version'   => '9.9.9',
					'format'           => '1',
					'registry_version' => 3,
					'paired'           => true,
					'time'             => time(),
				)
			);
		}
		if ( Responder::INVENTORY_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->responder()->inventory( (array) json_decode( $body, true ) ) );
		}
		$this->served[] = $route;
		if ( ImportResponder::ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->importer()->import( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( Rollback::ROUTE === $route && 'POST' === $method ) {
			$data = (array) json_decode( $body, true );
			return $this->signed( $headers, $sender, $this->rollback()->undo( $sender, (string) ( $data['transfer'] ?? '' ), (int) ( $data['post'] ?? 0 ), ! empty( $data['force'] ), (string) ( $data['user'] ?? '' ) ) );
		}
		if ( MediaReceiver::CHUNK_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->receiver()->chunk( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( MediaReceiver::COMMIT_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->receiver()->commit( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( PreflightResponder::ROUTE === $route && 'POST' === $method ) {
			$responder = new PreflightResponder( '1', $this->map, $this->lookup, $this->facts, $this->writer );
			return $this->signed( $headers, $sender, $responder->preflight( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( ExportResponder::SEARCH_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->exporter()->search( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( ExportResponder::EXPORT_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->exporter()->export( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( ExportResponder::MEDIA_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->exporter()->mediaRead( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( ExportResponder::PULLED_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->exporter()->pulled( $sender, (array) json_decode( $body, true ) ) );
		}
		if ( Responder::LINK_ROUTE === $route && 'POST' === $method ) {
			return $this->signed( $headers, $sender, $this->responder()->link( $sender, (array) json_decode( $body, true ), time() ) );
		}
		if ( Sisters::UNPAIR_ROUTE === $route && 'POST' === $method ) {
			$this->sisters->pairings()->remove( $sender );
			return $this->signed( $headers, $sender, array( 'unpaired' => true ) );
		}
		return self::notFound();
	}

	public function responder(): Responder {
		return new Responder( $this->content, $this->map );
	}

	public function importer(): ImportResponder {
		return new ImportResponder( '1', $this->map, $this->lookup, $this->facts, $this->writer, $this->snapshots, $this->log, fn( int $id ): array => $this->findings[ $id ] ?? array(), static fn(): int => 1700000000, $this->holdUpdates, $this->mediaFiles );
	}

	public function exporter(): ExportResponder {
		return new ExportResponder(
			'1',
			function ( string $query, string $type, int $page, int $perPage ): array {
				$found = array_values(
					array_filter(
						$this->packages,
						static fn( Package $p ): bool => ( '' === $type || $p->type() === $type ) && ( '' === $query || false !== stripos( $p->title(), $query ) )
					)
				);
				$items = array_map(
					static fn( Package $p ): array => array(
						'id'       => $p->id,
						'title'    => $p->title(),
						'type'     => $p->type(),
						'status'   => (string) ( $p->fields['post_status'] ?? '' ),
						'modified' => '',
						'path'     => $p->path,
					),
					array_slice( $found, ( $page - 1 ) * $perPage, $perPage )
				);
				return array(
					'items' => $items,
					'total' => count( $found ),
				);
			},
			fn( int $id ): ?Package => $this->packages[ $id ] ?? null,
			fn( int $id ): ?string => $this->packages[ $id ]->uuid ?? null,
			fn( int $id ): array => $this->sourceFindings[ $id ] ?? array(),
			$this->source,
			$this->map,
			$this->log,
			static fn(): int => 1700000000
		);
	}

	public function puller(): PullRunner {
		$self = $this->registry->get( $this->siteId );
		assert( null !== $self );
		return new PullRunner(
			$self,
			$this->registry,
			$this->sisters->transport(),
			'1',
			new PreflightResponder( '1', $this->map, $this->lookup, $this->facts, $this->writer ),
			$this->receiver(),
			new ImportResponder( '1', $this->map, $this->lookup, $this->facts, $this->writer, $this->snapshots, $this->log, fn( int $id ): array => $this->findings[ $id ] ?? array(), static fn(): int => 1700000000, false, $this->mediaFiles ),
			$this->log,
			static fn( string $content ): ?array => null,
			'json_encode',
			static fn(): int => 1700000000
		);
	}

	public function rollback(): Rollback {
		return new Rollback( $this->map, $this->snapshots, $this->writer, $this->facts, $this->log, new CopiedMedia( $this->mediaFiles, $this->map ) );
	}

	public function held(): HeldUpdates {
		return new HeldUpdates( $this->map, $this->snapshots, $this->writer, $this->facts, $this->log, new CopiedMedia( $this->mediaFiles, $this->map ), static fn(): int => 1700000100 );
	}

	public function receiver(): MediaReceiver {
		return new MediaReceiver( $this->map, $this->mediaFiles, static fn(): int => 1700000000 );
	}

	public function linker(): LinkExisting {
		return new LinkExisting( $this->content, $this->map, new InventoryCache( $this->store ), $this->registry, $this->sisters->transport() );
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $data
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function signed( array $headers, string $sender, array $data ): array {
		$body = (string) json_encode( $data );
		$keys = $this->sisters->keys()?->keyPair();
		$sig  = null === $keys ? '' : SignedResponse::sign( $keys, $sender, $this->siteId, $headers[ SignedRequest::HEADER_NONCE ] ?? '', 200, $body );
		return array(
			'status'  => 200,
			'headers' => array( 'x-sceneshifter-response-signature' => $sig ),
			'body'    => $body,
		);
	}

	/**
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function notFound(): array {
		return array(
			'status'  => 404,
			'headers' => array(),
			'body'    => '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}',
		);
	}
}
