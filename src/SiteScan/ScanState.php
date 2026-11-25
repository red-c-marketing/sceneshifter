<?php
/**
 * Progress of the current or most recent site scan, kept in one option.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SiteScan;

final class ScanState {

	public const OPTION = 'sceneshifter_scan_state';

	public const RUNNING   = 'running';
	public const COMPLETE  = 'complete';
	public const CANCELLED = 'cancelled';
	public const FAILED    = 'failed';

	public const PHASE_POSTS  = 'posts';
	public const PHASE_EXTRAS = 'extras';

	public const TRIGGER_MANUAL    = 'manual';
	public const TRIGGER_SCHEDULED = 'scheduled';
	public const TRIGGER_CLI       = 'cli';

	/**
	 * @param list<string> $postTypes Post types this scan covers.
	 */
	public function __construct(
		public string $id,
		public string $status,
		public string $phase,
		public array $postTypes,
		public int $total,
		public int $processed = 0,
		public int $lastPostId = 0,
		public int $startedBy = 0,
		public int $startedAt = 0,
		public int $finishedAt = 0,
		public int $updatedAt = 0,
		public string $error = '',
		public string $trigger = self::TRIGGER_MANUAL,
		public int $newFindings = 0
	) {}

	public static function load(): ?self {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}
		return new self(
			(string) $data['id'],
			(string) ( $data['status'] ?? self::FAILED ),
			(string) ( $data['phase'] ?? self::PHASE_POSTS ),
			array_values( array_map( 'strval', (array) ( $data['postTypes'] ?? array() ) ) ),
			(int) ( $data['total'] ?? 0 ),
			(int) ( $data['processed'] ?? 0 ),
			(int) ( $data['lastPostId'] ?? 0 ),
			(int) ( $data['startedBy'] ?? 0 ),
			(int) ( $data['startedAt'] ?? 0 ),
			(int) ( $data['finishedAt'] ?? 0 ),
			(int) ( $data['updatedAt'] ?? 0 ),
			(string) ( $data['error'] ?? '' ),
			(string) ( $data['trigger'] ?? self::TRIGGER_MANUAL ),
			(int) ( $data['newFindings'] ?? 0 )
		);
	}

	public function save(): void {
		$this->updatedAt = time();
		update_option( self::OPTION, $this->toArray(), false );
	}

	public function isRunning(): bool {
		return self::RUNNING === $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'status'      => $this->status,
			'phase'       => $this->phase,
			'postTypes'   => $this->postTypes,
			'total'       => $this->total,
			'processed'   => $this->processed,
			'lastPostId'  => $this->lastPostId,
			'startedBy'   => $this->startedBy,
			'startedAt'   => $this->startedAt,
			'finishedAt'  => $this->finishedAt,
			'updatedAt'   => $this->updatedAt,
			'error'       => $this->error,
			'trigger'     => $this->trigger,
			'newFindings' => $this->newFindings,
		);
	}
}
