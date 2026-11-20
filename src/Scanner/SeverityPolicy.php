<?php
/**
 * Decides how serious a sister reference is.
 *
 * Default: on live, a link to any non-live sister is an error. Every other
 * cross-sister link is a warning. Per-role configuration comes later.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

use RedC\SisterSync\Registry\Site;

final class SeverityPolicy {

	public function severity( Site $current, Site $sister ): string {
		if ( 'live' === $current->role && 'live' !== $sister->role ) {
			return Finding::ERROR;
		}
		return Finding::WARNING;
	}
}
