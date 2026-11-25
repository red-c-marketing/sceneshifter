<?php
/**
 * Writes "posts" into FakeSiteFacts and MemoryTargetLookup, for tests.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use RedC\Sceneshifter\Transfer\Package;
use RedC\Sceneshifter\Transfer\PostWriter;

final class MemoryPostWriter implements PostWriter {

	public int $nextId = 900;

	/** Set to make write() throw with this message. */
	public ?string $fail = null;

	/** @var list<array{id: int, package: Package, options: array<string, mixed>}> */
	public array $writes = array();

	/** @var list<int> */
	public array $restored = array();

	/** @var list<int> */
	public array $discarded = array();

	public function __construct(
		private readonly FakeSiteFacts $facts,
		private readonly MemoryTargetLookup $lookup
	) {}

	public function snapshot( int $id ): array {
		$package = $this->facts->packages[ $id ] ?? null;
		return array(
			'package' => $package?->toArray(),
			'status'  => $this->facts->statuses[ $id ] ?? 'publish',
		);
	}

	public function write( ?int $id, Package $package, array $options ): int {
		if ( null !== $this->fail ) {
			if ( null !== $id ) {
				// Half-written, as a real failure part-way through would leave it.
				$this->facts->packages[ $id ] = $package;
			}
			throw new \RuntimeException( $this->fail );
		}
		$id                           = $id ?? $this->nextId++;
		$stored                       = Package::fromArray( array( 'id' => $id ) + $package->toArray() );
		$this->facts->packages[ $id ] = $stored ?? $package;
		$this->facts->statuses[ $id ] = $options['status'];
		$this->lookup->post( $id, $package->type(), $package->path, $package->uuid );
		$this->writes[] = array(
			'id'      => $id,
			'package' => $package,
			'options' => $options,
		);
		return $id;
	}

	public function restore( int $id, array $snapshot ): void {
		$package = Package::fromArray( $snapshot['package'] ?? null );
		if ( null !== $package ) {
			$this->facts->packages[ $id ] = $package;
		}
		$this->facts->statuses[ $id ] = (string) ( $snapshot['status'] ?? 'publish' );
		$this->restored[]             = $id;
	}

	public function discard( int $id, bool $trash ): void {
		unset( $this->facts->packages[ $id ], $this->lookup->posts[ $id ] );
		$this->discarded[] = $id;
	}

	public function urls( int $id ): array {
		return array(
			'edit' => "https://staging.client.co.uk/wp-admin/post.php?post={$id}&action=edit",
			'view' => '',
		);
	}
}
