<?php
/**
 * Decides how serious a sister reference is, from this site's Check
 * settings. By default, on live a link to any other environment is an
 * error, and everywhere else it is a warning.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

use RedC\SisterSync\Registry\Site;
use RedC\SisterSync\Store\CheckSettings;

final class SeverityPolicy {

	public function __construct( private readonly CheckSettings $settings = new CheckSettings() ) {}

	/**
	 * Finding::ERROR, Finding::WARNING, or null when links to that sister
	 * aren't reported.
	 */
	public function severity( Site $current, Site $sister ): ?string {
		return $this->settings->severityFor( $current->role, $sister->role );
	}
}
