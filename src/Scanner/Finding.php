<?php
/**
 * One reference to a sister site, at one location.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

use RedC\SisterSync\Registry\Site;

final class Finding {

	public const ERROR   = 'error';
	public const WARNING = 'warning';

	/**
	 * @param list<int>|null $blockPath
	 */
	public function __construct(
		public readonly Site $sister,
		public readonly string $url,
		public readonly string $severity,
		public readonly string $area,
		public readonly string $label,
		public readonly ?array $blockPath = null,
		public readonly ?string $blockName = null,
		public readonly int $count = 1
	) {}

	public function withCount( int $count ): self {
		return new self( $this->sister, $this->url, $this->severity, $this->area, $this->label, $this->blockPath, $this->blockName, $count );
	}

	/**
	 * A human description of the location, e.g. "Block 3 › 1 (core/image)".
	 */
	public function locationText(): string {
		if ( null !== $this->blockPath ) {
			$path = implode( ' › ', array_map( static fn( int $i ) => (string) ( $i + 1 ), $this->blockPath ) );
			return sprintf( 'Block %s (%s)', $path, $this->label );
		}
		return $this->label;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'sister'    => array(
				'id'     => $this->sister->id,
				'label'  => $this->sister->label,
				'role'   => $this->sister->role,
				'colour' => $this->sister->colour(),
			),
			'url'       => $this->url,
			'severity'  => $this->severity,
			'area'      => $this->area,
			'label'     => $this->label,
			'location'  => $this->locationText(),
			'blockPath' => $this->blockPath,
			'blockName' => $this->blockName,
			'count'     => $this->count,
		);
	}
}
