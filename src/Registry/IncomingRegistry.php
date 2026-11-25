<?php
/**
 * Decides whether a registry sent from another site may be applied here.
 *
 * Nothing is applied without an administrator on this site reviewing it and
 * confirming. These checks stop the obvious mistakes and refuse a registry
 * that clearly didn't come from a sister.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Registry;

use RedC\Sceneshifter\Url\UrlNormaliser;

final class IncomingRegistry {

	public const SOURCE_LINK = 'link';
	public const SOURCE_FILE = 'file';

	// Reasons it can't be applied.
	public const NOT_INCLUDED   = 'not_included';
	public const UNKNOWN_SENDER = 'unknown_sender';
	public const WRONG_ORIGIN   = 'wrong_origin';

	// Reasons to look carefully before applying.
	public const FIRST_SETUP       = 'first_setup';
	public const UNVERIFIED_ORIGIN = 'unverified_origin';
	public const OLDER             = 'older';
	public const SENDER_NOT_KNOWN  = 'sender_not_known';

	/**
	 * @param Registry       $current  This site's saved registry.
	 * @param RegistryBundle $bundle   What arrived.
	 * @param string         $homeUrl  This site's home_url().
	 * @param string         $source   self::SOURCE_LINK or self::SOURCE_FILE.
	 * @param string|null    $referrer For links, the page the browser says the link was clicked on, or null if it didn't say.
	 */
	public function __construct(
		private readonly Registry $current,
		public readonly RegistryBundle $bundle,
		private readonly string $homeUrl,
		public readonly string $source,
		private readonly ?string $referrer = null
	) {}

	public function incoming(): Registry {
		return $this->bundle->registry;
	}

	/**
	 * The sending site's entry: this site's copy when it knows the sender,
	 * otherwise the sender's own description of itself.
	 */
	public function sender(): ?Site {
		if ( '' === $this->bundle->from ) {
			return null;
		}
		return $this->current->get( $this->bundle->from ) ?? $this->incoming()->get( $this->bundle->from );
	}

	/**
	 * This site's entry in the incoming registry.
	 */
	public function self(): ?Site {
		$identity = $this->incoming()->resolveCurrent( $this->homeUrl, null );
		return $identity->isActive() ? $identity->site : null;
	}

	public function originVerified(): bool {
		return true === $this->referrerMatches();
	}

	/**
	 * @return list<string> Reasons it can't be applied; empty when it can.
	 */
	public function blockers(): array {
		$blockers = array();
		if ( null === $this->self() ) {
			$blockers[] = self::NOT_INCLUDED;
		}
		if ( self::SOURCE_LINK === $this->source ) {
			$knownSender = '' !== $this->bundle->from && null !== $this->current->get( $this->bundle->from );
			if ( null === $this->sender() || ( ! $this->current->isEmpty() && ! $knownSender ) ) {
				$blockers[] = self::UNKNOWN_SENDER;
			} elseif ( false === $this->referrerMatches() ) {
				$blockers[] = self::WRONG_ORIGIN;
			}
		}
		return $blockers;
	}

	/**
	 * @return list<string>
	 */
	public function warnings(): array {
		$warnings = array();
		if ( $this->current->isEmpty() ) {
			$warnings[] = self::FIRST_SETUP;
		}
		if ( self::SOURCE_LINK === $this->source && null === $this->referrerMatches() ) {
			$warnings[] = self::UNVERIFIED_ORIGIN;
		}
		if ( self::SOURCE_FILE === $this->source && ! $this->current->isEmpty() && null === $this->current->get( $this->bundle->from ) ) {
			$warnings[] = self::SENDER_NOT_KNOWN;
		}
		if ( ! $this->isIdentical() && $this->incoming()->version < $this->current->version ) {
			$warnings[] = self::OLDER;
		}
		return $warnings;
	}

	public function canApply(): bool {
		return array() === $this->blockers() && ! $this->isUpToDate();
	}

	public function isIdentical(): bool {
		return RegistryDiff::identical( $this->current, $this->incoming() );
	}

	/**
	 * Same sites and this site's version is already at least the sender's.
	 */
	public function isUpToDate(): bool {
		return $this->isIdentical() && $this->current->version >= $this->incoming()->version;
	}

	/**
	 * The version to save. Takes the sender's number when it is ahead, so
	 * copies match; otherwise moves past this site's own number.
	 */
	public function newVersion(): int {
		if ( $this->isIdentical() ) {
			return max( $this->current->version, $this->incoming()->version );
		}
		return max( $this->incoming()->version, $this->current->version + 1 );
	}

	public function registryToSave(): Registry {
		return $this->incoming()->withVersion( $this->newVersion() );
	}

	/**
	 * Whether the browser-reported origin is the sender: true, false, or
	 * null when there is no origin to check (none sent, or a file).
	 */
	private function referrerMatches(): ?bool {
		if ( self::SOURCE_LINK !== $this->source || null === $this->referrer || '' === $this->referrer ) {
			return null;
		}
		$sender = $this->sender();
		$origin = ( new UrlNormaliser() )->normalise( $this->referrer );
		if ( null === $sender || null === $origin || $origin->isRelative() ) {
			return false;
		}
		$hostKey = $origin->hostKey();
		return null !== $hostKey && $sender->hasHostKey( $hostKey );
	}
}
