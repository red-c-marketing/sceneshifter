<?php
/**
 * A pairing step that can't go ahead. The message is written for admins.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Pairing;

use RuntimeException;

final class PairingException extends RuntimeException {}
