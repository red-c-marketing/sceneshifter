<?php
/**
 * The result of working out which registry entry the current site is.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Registry;

final class Identity {

	public const OK                    = 'ok';
	public const UNCONFIGURED          = 'unconfigured';
	public const HOME_NOT_IN_REGISTRY  = 'home_not_in_registry';
	public const REQUEST_HOST_MISMATCH = 'request_host_mismatch';

	public function __construct(
		public readonly string $status,
		public readonly ?Site $site = null,
		public readonly string $detail = ''
	) {}

	/**
	 * The plugin only acts when the site has been positively identified.
	 * Anything else leaves it inert, so a half-migrated clone can't act as
	 * the site it was copied from.
	 */
	public function isActive(): bool {
		return self::OK === $this->status && null !== $this->site;
	}
}
