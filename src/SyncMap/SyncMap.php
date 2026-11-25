<?php
/**
 * This site's sync map and the tools around it, so screens, REST endpoints
 * and WP-CLI share one set.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Transport\Transport;

final class SyncMap {

	public function __construct(
		private readonly ContentIndex $index,
		private readonly MapStore $map,
		private readonly InventoryCache $cache,
		private readonly Registry $registry,
		private readonly ?Transport $transport
	) {}

	public function index(): ContentIndex {
		return $this->index;
	}

	public function map(): MapStore {
		return $this->map;
	}

	public function responder(): Responder {
		return new Responder( $this->index, $this->map );
	}

	public function linkExisting(): LinkExisting {
		return new LinkExisting( $this->index, $this->map, $this->cache, $this->registry, $this->transport );
	}
}
