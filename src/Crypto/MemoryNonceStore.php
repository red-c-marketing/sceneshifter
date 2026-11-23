<?php
/**
 * In-memory nonce store for tests and single-process tools.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

final class MemoryNonceStore implements NonceStore {

	/** @var array<string, int> */
	private array $seen = array();

	public function remember( string $senderId, string $nonce, int $expiresAt ): bool {
		$key = $senderId . '|' . $nonce;
		if ( isset( $this->seen[ $key ] ) ) {
			return false;
		}
		$this->seen[ $key ] = $expiresAt;
		return true;
	}
}
