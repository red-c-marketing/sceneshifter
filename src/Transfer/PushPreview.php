<?php
/**
 * On the source: previews pushing posts to a sister. Packages each post,
 * asks the sister to resolve the references (POST /preflight), rewrites
 * the packages with the answers and compares the result with what the
 * sister has. Nothing changes on either site.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\Registry\Registry;
use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Rewrite\IdMap;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Rewrite\UrlRewriter;
use RedC\Sceneshifter\Scanner\Finding;
use RedC\Sceneshifter\Transport\Transport;

final class PushPreview {

	/** @var callable(int): ?Package */
	private $packager;

	/** @var callable(int): list<Finding> */
	private $findings;

	/** @var callable(string): ?array<int, array<string, mixed>> */
	private $parse;

	/** @var callable(array<int, array<string, mixed>>): string */
	private $serialize;

	/**
	 * @param Site                                                $self      This site.
	 * @param Registry                                            $registry  The site list.
	 * @param Transport|null                                      $transport Signed transport; null when inactive.
	 * @param string                                              $format    Package format (Plugin::PACKAGE_FORMAT).
	 * @param callable(int): ?Package                             $packager  Builds a package for a post ID.
	 * @param callable(int): list<Finding>                        $findings  Links to other environments in a post.
	 * @param callable(string): ?array<int, array<string, mixed>> $parse     Block parser.
	 * @param callable(array<int, array<string, mixed>>): string  $serialize Block serialiser.
	 */
	public function __construct(
		private readonly Site $self,
		private readonly Registry $registry,
		private readonly ?Transport $transport,
		private readonly string $format,
		callable $packager,
		callable $findings,
		callable $parse,
		callable $serialize
	) {
		$this->packager  = $packager;
		$this->findings  = $findings;
		$this->parse     = $parse;
		$this->serialize = $serialize;
	}

	/**
	 * @param list<int> $postIds
	 * @return array{error: string|null, items: list<PreviewItem>}
	 */
	public function build( string $targetId, array $postIds ): array {
		$target = $this->registry->get( $targetId );
		if ( null === $target || null === $this->transport ) {
			return array(
				'error' => __( 'That environment isn\'t in the site list, or Sceneshifter is inactive here.', 'sceneshifter' ),
				'items' => array(),
			);
		}

		$packages = array();
		foreach ( array_slice( $postIds, 0, PreflightResponder::MAX_ITEMS ) as $id ) {
			$package = ( $this->packager )( $id );
			if ( null !== $package ) {
				$packages[] = $package;
			}
		}
		if ( array() === $packages ) {
			return array(
				'error' => __( 'None of those posts can be pushed.', 'sceneshifter' ),
				'items' => array(),
			);
		}

		$reply = $this->preflight( $target, $packages );
		if ( null !== $reply['error'] ) {
			return array(
				'error' => $reply['error'],
				'items' => array(),
			);
		}

		$items = array();
		foreach ( $packages as $i => $package ) {
			$answer  = $reply['answers'][ $i ] ?? null;
			$items[] = null === $answer
				? new PreviewItem( $package, error: __( 'The other site didn\'t answer for this post.', 'sceneshifter' ) )
				: $this->item( $package, $answer, $target );
		}
		return array(
			'error' => null,
			'items' => $items,
		);
	}

	/**
	 * Asks the target to resolve the packages' references (POST /preflight).
	 *
	 * @param list<Package> $packages At most PreflightResponder::MAX_ITEMS.
	 * @return array{error: string|null, answers: list<array<string, mixed>|null>} One answer per package, in order.
	 */
	public function preflight( Site $target, array $packages ): array {
		if ( null === $this->transport ) {
			return array(
				'error'   => __( 'Sceneshifter is inactive on this site.', 'sceneshifter' ),
				'answers' => array(),
			);
		}
		$result = $this->transport->send(
			$target->id,
			'POST',
			PreflightResponder::ROUTE,
			array(
				'format' => $this->format,
				'items'  => array_map( static fn( Package $p ): array => $p->manifest(), $packages ),
			)
		);
		if ( ! $result->ok() ) {
			return array(
				'error'   => $result->message,
				'answers' => array(),
			);
		}
		if ( PreflightResponder::FORMAT_MISMATCH === ( $result->data['error'] ?? null ) ) {
			return array(
				'error'   => self::formatMismatch( $target ),
				'answers' => array(),
			);
		}
		$answers = is_array( $result->data['items'] ?? null ) ? array_values( $result->data['items'] ) : array();
		$out     = array();
		foreach ( array_keys( $packages ) as $i ) {
			$out[] = is_array( $answers[ $i ] ?? null ) ? $answers[ $i ] : null;
		}
		return array(
			'error'   => null,
			'answers' => $out,
		);
	}

	public static function formatMismatch( Site $target ): string {
		return sprintf(
			/* translators: %s: site name. */
			__( 'Sceneshifter on %s uses a different package format, so the two can\'t exchange content. Update Sceneshifter on both sites to the same version.', 'sceneshifter' ),
			$target->label
		);
	}

	/**
	 * Rewrites a package with the target's answers and compares it with
	 * what the target has.
	 *
	 * @param array<string, mixed> $answer    One item of the preflight reply.
	 * @param array<int, int>      $uploaded  Media copied to the target since the preflight: source ID => target ID.
	 * @param bool                 $writing   For the real push: IDs with no match become 0.
	 */
	public function item( Package $package, array $answer, Site $target, array $uploaded = array(), bool $writing = false ): PreviewItem {
		$posts       = self::matches( $answer['posts'] ?? null );
		$attachments = $uploaded + self::matches( $answer['attachments'] ?? null );
		$terms       = self::matches( $answer['term_refs'] ?? null );
		$targetPost  = is_array( $answer['target'] ?? null ) && is_int( $answer['target']['id'] ?? null ) ? $answer['target'] : null;
		if ( null !== $targetPost ) {
			$posts[ $package->id ] = $targetPost['id'];
		}

		$rewriter  = new PackageRewriter( new UrlRewriter( $this->self, $target ), $writing ? ( new IdMap( $attachments, $posts, $terms ) )->clearingMissing() : new IdMap( $attachments, $posts, $terms ), $this->parse, $this->serialize );
		$result    = $rewriter->rewrite( $package );
		$rewritten = $result['package'];

		$missing = array();
		$media   = array();
		foreach ( $result['unresolved'] as $item ) {
			if ( Rewrite::ATTACHMENT === $item->kind && empty( $package->attachments[ $item->id ]['missing'] ) && isset( $package->attachments[ $item->id ] ) ) {
				$ref     = $package->attachments[ $item->id ];
				$media[] = array(
					'id'    => $item->id,
					'title' => (string) ( $ref['title'] ?? '' ),
					'file'  => (string) ( $ref['file'] ?? '' ),
					'size'  => is_int( $ref['size'] ?? null ) ? $ref['size'] : null,
				);
				continue;
			}
			$ref       = Rewrite::POST === $item->kind ? ( $package->posts[ $item->id ] ?? array() ) : ( Rewrite::TERM === $item->kind ? ( $package->termRefs[ $item->id ] ?? array() ) : ( $package->attachments[ $item->id ] ?? array() ) );
			$missing[] = array(
				'id'       => $item->id,
				'kind'     => $item->kind,
				'title'    => (string) ( $ref['title'] ?? $ref['name'] ?? '' ),
				'location' => $item->location,
				'gone'     => ! empty( $ref['missing'] ),
			);
		}

		$newTerms = array();
		foreach ( is_array( $answer['terms'] ?? null ) ? $answer['terms'] : array() as $i => $term ) {
			if ( is_array( $term ) && null === ( $term['id'] ?? null ) ) {
				$newTerms[] = array(
					'taxonomy' => (string) ( $term['taxonomy'] ?? '' ),
					'slug'     => (string) ( $term['slug'] ?? '' ),
					'name'     => (string) ( $package->terms[ $i ]['name'] ?? $term['slug'] ?? '' ),
				);
			}
		}

		$changed  = null;
		$conflict = null;
		if ( null !== $targetPost ) {
			$theirs   = is_array( $targetPost['parts'] ?? null ) ? array_map( 'strval', $targetPost['parts'] ) : array();
			$changed  = ContentHash::changed( ContentHash::parts( $rewritten ), $theirs );
			$synced   = $targetPost['synced_hash'] ?? null;
			$conflict = null === $synced ? PreviewItem::NEVER_SYNCED : ( $synced !== ( $targetPost['hash'] ?? null ) ? PreviewItem::EDITED : null );
		}

		$foreign = array_values( array_filter( ( $this->findings )( $package->id ), static fn( Finding $f ): bool => $f->sister->id !== $target->id ) );
		$author  = is_array( $answer['author'] ?? null ) && is_int( $answer['author']['id'] ?? null ) ? array(
			'id'   => $answer['author']['id'],
			'name' => (string) ( $answer['author']['name'] ?? '' ),
		) : null;

		return new PreviewItem(
			$package,
			$rewritten,
			$targetPost,
			is_int( $answer['blocked_by'] ?? null ) ? $answer['blocked_by'] : null,
			$changed,
			$conflict,
			$result['rewrites'],
			$missing,
			$media,
			count( array_filter( $attachments ) ),
			$newTerms,
			$author,
			is_array( $answer['checks'] ?? null ) ? array_values( array_filter( $answer['checks'], 'is_array' ) ) : array(),
			$foreign
		);
	}

	/**
	 * @return array<int, int> Source ID => target ID, for the ones found.
	 */
	private static function matches( mixed $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( is_array( $row ) && is_int( $row['source'] ?? null ) && is_int( $row['id'] ?? null ) ) {
				$out[ $row['source'] ] = $row['id'];
			}
		}
		return $out;
	}
}
