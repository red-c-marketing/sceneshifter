<?php
/**
 * Where a URL points: this site, a sister site or somewhere else.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Url;

use RedC\SisterSync\Registry\Site;

final class Classification {

	public const SELF     = 'self';
	public const SISTER   = 'sister';
	public const EXTERNAL = 'external';

	public function __construct(
		public readonly string $kind,
		public readonly NormalisedUrl $url,
		public readonly ?Site $site = null
	) {}

	public function isSister(): bool {
		return self::SISTER === $this->kind;
	}
}
