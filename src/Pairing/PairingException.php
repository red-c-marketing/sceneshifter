<?php
/**
 * A pairing step that can't go ahead. The message is written for admins.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Pairing;

use RuntimeException;

final class PairingException extends RuntimeException {}
