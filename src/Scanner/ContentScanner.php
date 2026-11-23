<?php
/**
 * Finds every reference to a sister site in one post's content, excerpt and
 * meta (which includes all ACF fields, since ACF stores them as post meta).
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\Scanner;

use RedC\SisterSync\Url\UrlClassifier;

final class ContentScanner {

	/**
	 * Meta that never holds content references, or is a cache.
	 */
	public const DEFAULT_META_EXCLUDES = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_thumbnail_id',
		'_encloseme',
		'_pingme',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
	);

	/**
	 * Meta key prefixes to skip: oEmbed caches and our own data.
	 */
	public const DEFAULT_META_EXCLUDE_PREFIXES = array( '_oembed_', '_sistersync_' );

	private UrlExtractor $extractor;
	private BlockWalker $walker;

	/**
	 * @param list<string> $metaExcludes
	 * @param list<string> $metaExcludePrefixes
	 */
	public function __construct(
		private readonly UrlClassifier $classifier,
		private readonly SeverityPolicy $policy = new SeverityPolicy(),
		private readonly array $metaExcludes = self::DEFAULT_META_EXCLUDES,
		private readonly array $metaExcludePrefixes = self::DEFAULT_META_EXCLUDE_PREFIXES
	) {
		$this->extractor = new UrlExtractor();
		$this->walker    = new BlockWalker();
	}

	/**
	 * @return list<Finding> One finding per distinct URL and location, with a count of repeats.
	 */
	public function scan( ScanInput $input ): array {
		return $this->findingsFrom( $this->chunks( $input ) );
	}

	/**
	 * Scans any stored value (a string, or an array or object of them) found
	 * outside a post: a menu item URL, a widget, an option, a theme setting.
	 *
	 * @param string $area  One of the TextChunk::AREA_* constants.
	 * @param string $label Where the value lives, for the report.
	 * @return list<Finding>
	 */
	public function scanValue( string $area, string $label, mixed $value ): array {
		$chunks = ( static function () use ( $area, $label, $value ): \Generator {
			foreach ( self::strings( $value ) as $string ) {
				yield new TextChunk( $area, $label, $string );
			}
		} )();
		return $this->findingsFrom( $chunks );
	}

	/**
	 * @param iterable<TextChunk> $chunks
	 * @return list<Finding>
	 */
	private function findingsFrom( iterable $chunks ): array {
		$findings = array();
		foreach ( $chunks as $chunk ) {
			foreach ( $this->extractor->extract( $chunk->text ) as $url ) {
				$classification = $this->classifier->classify( $url );
				if ( null === $classification || ! $classification->isSister() || null === $classification->site ) {
					continue;
				}

				$severity = $this->policy->severity( $this->classifier->current(), $classification->site );
				if ( null === $severity ) {
					continue;
				}

				$key = $url . '|' . $chunk->area . '|' . $chunk->label . '|' . implode( '.', $chunk->blockPath ?? array() );
				if ( isset( $findings[ $key ] ) ) {
					$findings[ $key ] = $findings[ $key ]->withCount( $findings[ $key ]->count + 1 );
					continue;
				}

				$findings[ $key ] = new Finding(
					$classification->site,
					$url,
					$severity,
					$chunk->area,
					$chunk->label,
					$chunk->blockPath,
					$chunk->blockName
				);
			}
		}
		return array_values( $findings );
	}

	/**
	 * @return \Generator<TextChunk>
	 */
	private function chunks( ScanInput $input ): \Generator {
		if ( null !== $input->blocks ) {
			yield from $this->walker->walk( $input->blocks );
		} elseif ( '' !== trim( $input->content ) ) {
			yield new TextChunk( TextChunk::AREA_CONTENT, 'Content', $input->content );
		}

		if ( '' !== trim( $input->excerpt ) ) {
			yield new TextChunk( TextChunk::AREA_EXCERPT, 'Excerpt', $input->excerpt );
		}

		foreach ( $input->meta as $key => $values ) {
			$key = (string) $key;
			if ( $this->isExcludedMeta( $key ) ) {
				continue;
			}
			$label = null;
			if ( is_callable( $input->metaLabel ) ) {
				$label = ( $input->metaLabel )( $key );
			}
			$label ??= 'Meta: ' . $key;

			foreach ( $values as $value ) {
				foreach ( self::strings( $value ) as $string ) {
					yield new TextChunk( TextChunk::AREA_META, $label, $string );
				}
			}
		}
	}

	private function isExcludedMeta( string $key ): bool {
		if ( in_array( $key, $this->metaExcludes, true ) ) {
			return true;
		}
		foreach ( $this->metaExcludePrefixes as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every string inside a scalar, array or object value.
	 *
	 * @return \Generator<string>
	 */
	private static function strings( mixed $value ): \Generator {
		if ( is_string( $value ) ) {
			yield $value;
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $item ) {
				yield from self::strings( $item );
			}
		}
	}
}
