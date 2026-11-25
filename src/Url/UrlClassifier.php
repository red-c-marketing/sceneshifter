<?php
/**
 * Classifies URLs relative to the current site and its registry.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Url;

use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Registry\Site;

final class UrlClassifier {

	private UrlNormaliser $normaliser;

	public function __construct(
		private readonly Registry $registry,
		private readonly Site $current
	) {
		$this->normaliser = new UrlNormaliser();
	}

	public function classify( string $raw ): ?Classification {
		$url = $this->normaliser->normalise( $raw );
		if ( null === $url ) {
			return null;
		}

		if ( $url->isRelative() ) {
			return new Classification( Classification::SELF, $url, $this->current );
		}

		$site = $this->registry->matchUrl( $url );
		if ( null === $site ) {
			return new Classification( Classification::EXTERNAL, $url );
		}

		return new Classification(
			$site->id === $this->current->id ? Classification::SELF : Classification::SISTER,
			$url,
			$site
		);
	}

	public function current(): Site {
		return $this->current;
	}
}
