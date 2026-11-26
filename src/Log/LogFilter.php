<?php
/**
 * Which log entries to show: by direction, environment, result, words in
 * the title or person, a date range, or one batch.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

use RedC\Sceneshifter\SyncMap\Uuid;

final class LogFilter {

	public const RESULTS = array( LogEntry::DONE, LogEntry::FAILED, LogEntry::UNDONE, LogEntry::HELD );

	/**
	 * @param string   $direction LogEntry::IN, LogEntry::OUT or '' for both.
	 * @param string   $sister    Registry ID of the other environment, or '' for any.
	 * @param string   $result    One of RESULTS, or '' for any.
	 * @param string   $search    Words in the post title or the person's name.
	 * @param int|null $from      Unix time, inclusive.
	 * @param int|null $to        Unix time, exclusive.
	 * @param string   $transfer  One batch's UUID, or ''.
	 */
	public function __construct(
		public readonly string $direction = '',
		public readonly string $sister = '',
		public readonly string $result = '',
		public readonly string $search = '',
		public readonly ?int $from = null,
		public readonly ?int $to = null,
		public readonly string $transfer = ''
	) {}

	/**
	 * Builds a filter from untrusted input, dropping anything invalid.
	 *
	 * @param array<string, mixed> $input direction, sister, result, search, from and to (Y-m-d, in the given time zone), transfer.
	 */
	public static function fromInput( array $input, ?\DateTimeZone $zone = null ): self {
		$direction = in_array( $input['direction'] ?? null, array( LogEntry::IN, LogEntry::OUT ), true ) ? (string) $input['direction'] : '';
		$sister    = is_string( $input['sister'] ?? null ) && 1 === preg_match( '/^[a-z0-9_-]{1,32}$/', $input['sister'] ) ? $input['sister'] : '';
		$result    = in_array( $input['result'] ?? null, self::RESULTS, true ) ? (string) $input['result'] : '';
		$search    = is_string( $input['search'] ?? null ) ? mb_substr( trim( $input['search'] ), 0, 100 ) : '';
		$transfer  = Uuid::isValid( $input['transfer'] ?? null ) ? strtolower( (string) $input['transfer'] ) : '';
		return new self( $direction, $sister, $result, $search, self::day( $input['from'] ?? null, $zone, false ), self::day( $input['to'] ?? null, $zone, true ), $transfer );
	}

	public function isEmpty(): bool {
		return '' === $this->direction && '' === $this->sister && '' === $this->result && '' === $this->search && null === $this->from && null === $this->to && '' === $this->transfer;
	}

	public function matches( LogEntry $entry ): bool {
		if ( '' !== $this->direction && $entry->direction !== $this->direction ) {
			return false;
		}
		if ( '' !== $this->sister && $entry->sister !== $this->sister ) {
			return false;
		}
		if ( '' !== $this->result && $entry->result !== $this->result ) {
			return false;
		}
		if ( '' !== $this->transfer && strtolower( $entry->transfer ) !== $this->transfer ) {
			return false;
		}
		if ( ( null !== $this->from && $entry->at < $this->from ) || ( null !== $this->to && $entry->at >= $this->to ) ) {
			return false;
		}
		return '' === $this->search || false !== mb_stripos( $entry->title, $this->search ) || false !== mb_stripos( $entry->user, $this->search );
	}

	/**
	 * The filter as query arguments, for links that keep it.
	 *
	 * @return array<string, string>
	 */
	public function toQuery( ?\DateTimeZone $zone = null ): array {
		$zone = $zone ?? new \DateTimeZone( 'UTC' );
		return array_filter(
			array(
				'direction' => $this->direction,
				'sister'    => $this->sister,
				'result'    => $this->result,
				'search'    => $this->search,
				'from'      => null === $this->from ? '' : ( new \DateTimeImmutable( '@' . $this->from ) )->setTimezone( $zone )->format( 'Y-m-d' ),
				'to'        => null === $this->to ? '' : ( new \DateTimeImmutable( '@' . ( $this->to - 1 ) ) )->setTimezone( $zone )->format( 'Y-m-d' ),
				'transfer'  => $this->transfer,
			),
			static fn( string $v ): bool => '' !== $v
		);
	}

	/**
	 * Midnight at the start of a Y-m-d day, or of the day after it; null
	 * when it isn't a date.
	 */
	private static function day( mixed $value, ?\DateTimeZone $zone, bool $next ): ?int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone ?? new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return ( $next ? $date->modify( '+1 day' ) : $date )->getTimestamp();
	}
}
