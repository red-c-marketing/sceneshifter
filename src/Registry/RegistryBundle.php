<?php
/**
 * A registry packaged for sending to a sister site, by link or as a file.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Registry;

use InvalidArgumentException;
use JsonException;

final class RegistryBundle {

	public const FORMAT         = 'sister-sync-registry';
	public const FORMAT_VERSION = 1;

	/** Far more than any real registry needs; stops oversized input early. */
	public const MAX_BYTES = 65536;

	/**
	 * @param Registry $registry The registry being sent.
	 * @param string   $from     ID of the sending site, or '' when it isn't in its own registry.
	 */
	public function __construct(
		public readonly Registry $registry,
		public readonly string $from = ''
	) {}

	/**
	 * Readable JSON, for the downloadable file.
	 */
	public function toJson(): string {
		return json_encode( $this->payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	}

	/**
	 * Compact URL-safe text, for the fragment of a Send link.
	 */
	public function encode(): string {
		$json = json_encode( $this->payload(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * Reads either form: the JSON file or the encoded link text.
	 *
	 * @throws InvalidArgumentException When the input isn't a valid registry bundle.
	 */
	public static function decode( string $input ): self {
		$input = trim( $input );
		if ( '' === $input ) {
			throw new InvalidArgumentException( 'Nothing was received.' );
		}
		if ( strlen( $input ) > self::MAX_BYTES ) {
			throw new InvalidArgumentException( 'This is too large to be a Sister Sync registry.' );
		}

		$json = $input;
		if ( ! str_starts_with( $input, '{' ) ) {
			$json = base64_decode( strtr( $input, '-_', '+/' ), true );
			if ( false === $json ) {
				throw new InvalidArgumentException( 'This isn\'t a Sister Sync registry. It may have been cut short when it was copied.' );
			}
		}

		try {
			$data = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException( 'This isn\'t a Sister Sync registry. It may have been cut short when it was copied.' );
		}

		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) ) {
			throw new InvalidArgumentException( 'This isn\'t a Sister Sync registry.' );
		}
		if ( self::FORMAT_VERSION !== ( $data['format_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'This registry was made by a different version of Sister Sync. Update the plugin on both sites.' );
		}
		if ( ! is_array( $data['registry'] ?? null ) ) {
			throw new InvalidArgumentException( 'This registry is missing its list of sites.' );
		}

		$from = is_string( $data['from'] ?? null ) ? $data['from'] : '';
		if ( '' !== $from && ! preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $from ) ) {
			$from = '';
		}

		return new self( Registry::fromArray( $data['registry'] ), $from );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'from'           => $this->from,
			'registry'       => $this->registry->toArray(),
		);
	}
}
