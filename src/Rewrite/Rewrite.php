<?php
/**
 * One change the rewriter made, for the preview and the audit log.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Rewrite;

final class Rewrite {

	public const URL        = 'url';
	public const ATTACHMENT = 'attachment';
	public const POST       = 'post';
	public const TERM       = 'term';

	public function __construct(
		public readonly string $kind,
		public readonly string $location,
		public readonly string $old,
		public readonly string $new
	) {}

	/**
	 * @return array{kind: string, location: string, old: string, new: string}
	 */
	public function toArray(): array {
		return array(
			'kind'     => $this->kind,
			'location' => $this->location,
			'old'      => $this->old,
			'new'      => $this->new,
		);
	}
}
