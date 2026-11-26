<?php
/**
 * The transfer log as CSV. A cell that a spreadsheet would read as a
 * formula (starting with =, +, -, @, tab or carriage return) gets a
 * leading apostrophe, since titles and names come from people.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Log;

final class LogCsv {

	/**
	 * @return list<string>
	 */
	public static function header(): array {
		return array( 'id', 'when_utc', 'direction', 'environment', 'who', 'post_here', 'post_there', 'title', 'result', 'created', 'pulled', 'files_copied', 'message', 'batch' );
	}

	/**
	 * @return list<string>
	 */
	public static function row( LogEntry $entry ): array {
		$out = LogEntry::OUT === $entry->direction;
		return array(
			(string) $entry->id,
			gmdate( 'Y-m-d H:i:s', $entry->at ),
			$out ? 'out' : 'in',
			$entry->sister,
			$entry->user,
			(string) ( $out ? $entry->sourceId : ( $entry->targetId ?? '' ) ),
			(string) ( $out ? ( $entry->targetId ?? '' ) : $entry->sourceId ),
			$entry->title,
			$entry->result,
			$entry->created ? 'yes' : 'no',
			empty( $entry->details['pulled'] ) ? 'no' : 'yes',
			(string) ( is_int( $entry->details['media'] ?? null ) ? $entry->details['media'] : 0 ),
			(string) $entry->message,
			$entry->transfer,
		);
	}

	/**
	 * One CSV line, with a trailing CRLF as RFC 4180 asks.
	 *
	 * @param list<string> $cells
	 */
	public static function line( array $cells ): string {
		return implode( ',', array_map( array( self::class, 'cell' ), $cells ) ) . "\r\n";
	}

	private static function cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return 1 === preg_match( '/[",\r\n]/', $value ) || str_starts_with( $value, "'" ) ? '"' . str_replace( '"', '""', $value ) . '"' : $value;
	}
}
