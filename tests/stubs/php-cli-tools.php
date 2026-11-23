<?php
/**
 * The part of wp-cli/php-cli-tools the plugin uses, for static analysis.
 */

namespace cli\progress;

class Bar {
	public function tick( int $increment = 1, ?string $msg = null ): void {}
	public function finish(): void {}
}
