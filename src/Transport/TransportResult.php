<?php
/**
 * The outcome of one signed request to a sister.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transport;

final class TransportResult {

	public const OK              = 'ok';
	public const NOT_IN_REGISTRY = 'not_in_registry';
	public const NOT_PAIRED      = 'not_paired';
	public const NO_KEY          = 'no_key';
	public const INSECURE_URL    = 'insecure_url';
	public const UNREACHABLE     = 'unreachable';
	public const REDIRECTED      = 'redirected';
	public const REFUSED         = 'refused';
	public const HTTP_ERROR      = 'http_error';
	public const BAD_SIGNATURE   = 'bad_signature';
	public const BAD_RESPONSE    = 'bad_response';

	/**
	 * @param string               $code      One of the constants above.
	 * @param string               $message   Plain-English explanation for admins; empty on success.
	 * @param array<string, mixed> $data      Decoded reply on success.
	 * @param int                  $status    HTTP status, 0 if no reply.
	 * @param int                  $elapsedMs Round-trip time.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $message = '',
		public readonly array $data = array(),
		public readonly int $status = 0,
		public readonly int $elapsedMs = 0
	) {}

	public function ok(): bool {
		return self::OK === $this->code;
	}
}
