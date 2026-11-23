<?php
/**
 * Remembers request nonces so a captured request can't be replayed.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Crypto;

interface NonceStore {

	/**
	 * Records a nonce from a sender. Returns false if it was already seen
	 * within the window, in which case the request must be refused.
	 * Implementations must make check-and-record atomic.
	 */
	public function remember( string $senderId, string $nonce, int $expiresAt ): bool;
}
