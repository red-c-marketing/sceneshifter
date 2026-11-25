<?php
/**
 * This site's Check settings. Unlike the site list, these belong to one
 * site: live usually blocks publishing, staging usually doesn't.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Store;

use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Scanner\Finding;

final class CheckSettings {

	public const IGNORE = 'ignore';

	public const SCHEDULE_OFF    = 'off';
	public const SCHEDULE_DAILY  = 'daily';
	public const SCHEDULE_WEEKLY = 'weekly';
	public const SCHEDULES       = array( self::SCHEDULE_OFF, self::SCHEDULE_DAILY, self::SCHEDULE_WEEKLY );

	public const NOTIFY_NEW    = 'new';
	public const NOTIFY_ALWAYS = 'always';

	/**
	 * @param array<string, string> $severity     Role of the linked site => error, warning or ignore. Missing roles use the default.
	 * @param bool                  $blockPublish Refuse to publish or update content that has errors.
	 * @param string                $schedule     How often to scan the whole site.
	 * @param list<string>          $recipients   Who gets the scheduled scan email.
	 * @param string                $notify       Email only when there are new findings, or after every scheduled scan.
	 * @param list<string>          $extraOptions Option names to scan, with * as a wildcard.
	 */
	public function __construct(
		public readonly array $severity = array(),
		public readonly bool $blockPublish = false,
		public readonly string $schedule = self::SCHEDULE_OFF,
		public readonly array $recipients = array(),
		public readonly string $notify = self::NOTIFY_NEW,
		public readonly array $extraOptions = array()
	) {}

	/**
	 * Default severity on a site with this role: on live, links to any other
	 * environment are errors; everywhere else they are warnings.
	 */
	public static function defaultSeverity( string $currentRole, string $sisterRole ): string {
		return 'live' === $currentRole && 'live' !== $sisterRole ? Finding::ERROR : Finding::WARNING;
	}

	/**
	 * Severity for a link from a site with $currentRole to one with
	 * $sisterRole, or null when such links aren't reported.
	 */
	public function severityFor( string $currentRole, string $sisterRole ): ?string {
		$value = $this->severity[ $sisterRole ] ?? self::defaultSeverity( $currentRole, $sisterRole );
		return self::IGNORE === $value ? null : $value;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$severity = array();
		foreach ( (array) ( $data['severity'] ?? array() ) as $role => $value ) {
			if ( in_array( $role, Site::ROLES, true ) && in_array( $value, array( Finding::ERROR, Finding::WARNING, self::IGNORE ), true ) ) {
				$severity[ (string) $role ] = (string) $value;
			}
		}

		$schedule = (string) ( $data['schedule'] ?? self::SCHEDULE_OFF );
		$notify   = (string) ( $data['notify'] ?? self::NOTIFY_NEW );

		return new self(
			$severity,
			! empty( $data['blockPublish'] ),
			in_array( $schedule, self::SCHEDULES, true ) ? $schedule : self::SCHEDULE_OFF,
			self::cleanList( $data['recipients'] ?? array(), static fn( string $email ): bool => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ),
			self::NOTIFY_ALWAYS === $notify ? self::NOTIFY_ALWAYS : self::NOTIFY_NEW,
			self::cleanList( $data['extraOptions'] ?? array(), static fn( string $name ): bool => (bool) preg_match( '/^[A-Za-z0-9_\-.:*]{1,191}$/', $name ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'severity'     => $this->severity,
			'blockPublish' => $this->blockPublish,
			'schedule'     => $this->schedule,
			'recipients'   => $this->recipients,
			'notify'       => $this->notify,
			'extraOptions' => $this->extraOptions,
		);
	}

	/**
	 * Splits text on new lines, commas and spaces.
	 *
	 * @return list<string>
	 */
	public static function splitList( string $text ): array {
		$parts = preg_split( '/[\s,;]+/', $text ) ?: array();
		return array_values( array_filter( array_map( 'trim', $parts ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Turns option-name patterns into SQL LIKE patterns: * matches anything,
	 * and _ and % are literal.
	 *
	 * @return list<string>
	 */
	public function extraOptionLikePatterns(): array {
		return array_map(
			static fn( string $pattern ): string => str_replace( '*', '%', addcslashes( $pattern, '\\_%' ) ),
			$this->extraOptions
		);
	}

	/**
	 * @param mixed                   $value List or text.
	 * @param callable(string): bool $valid
	 * @return list<string>
	 */
	private static function cleanList( mixed $value, callable $valid ): array {
		$items = is_array( $value ) ? array_map( 'strval', $value ) : self::splitList( (string) $value );
		return array_values( array_unique( array_filter( array_map( 'trim', $items ), $valid ) ) );
	}
}
