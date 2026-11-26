<?php
/**
 * One post in a push or pull preview: what would happen to it, what stops
 * it, what to look out for, and every link and ID that would be rewritten.
 *
 * A push writes to the other environment; a pull writes here. The wording
 * follows, so the person always reads where the change lands.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Admin;

use RedC\Sceneshifter\Registry\Site;
use RedC\Sceneshifter\Rewrite\Rewrite;
use RedC\Sceneshifter\Transfer\PreflightResponder;
use RedC\Sceneshifter\Transfer\PreviewItem;
use RedC\Sceneshifter\Transfer\Resolver;

final class PreviewView {

	/** Most rewrites listed per post. */
	private const REWRITE_LIMIT = 200;

	/**
	 * @param Site $other The environment pushed to, or pulled from.
	 * @param bool $pull  Pulling: the post comes from $other and lands here.
	 */
	public function __construct(
		private readonly Site $other,
		private readonly bool $pull = false
	) {}

	public function render( PreviewItem $item ): void {
		$package = $item->package;
		$title   = '' === $package->title() ? __( '(no title)', 'sceneshifter' ) : $package->title();
		// A push links to the post here; a pull to the post it would update here, if any.
		$local = $this->pull ? ( is_int( $item->target['id'] ?? null ) ? $item->target['id'] : null ) : $package->id;
		$link  = null === $local ? '' : (string) get_edit_post_link( $local );
		?>
		<div class="sceneshifter-push-item<?php echo $item->isBlocked() ? ' is-blocked' : ''; ?>">
			<h2>
				<?php if ( '' === $link ) : ?>
					<?php echo esc_html( $title ); ?>
				<?php else : ?>
					<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php endif; ?>
				<span class="sceneshifter-tag"><?php echo esc_html( self::typeLabel( $package->type() ) ); ?></span>
			</h2>
			<?php if ( null !== $item->error ) : ?>
				<p class="sceneshifter-error-text"><?php echo esc_html( $item->error ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>
			<p><strong><?php echo esc_html( $this->action( $item ) ); ?></strong></p>
			<?php
			foreach ( $this->errors( $item ) as $message ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
			}
			foreach ( $this->warnings( $item ) as $message ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
			}
			?>
			<table class="widefat striped sceneshifter-push-facts">
				<tbody>
					<?php if ( null !== $item->changed ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Would change', 'sceneshifter' ); ?></th>
							<td><?php echo esc_html( array() === $item->changed ? __( 'Nothing: the two are already the same.', 'sceneshifter' ) : implode( ', ', array_map( array( PushPage::class, 'partLabel' ), $item->changed ) ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'sceneshifter' ); ?></th>
						<td><?php echo esc_html( $this->statusText( $item ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Author', 'sceneshifter' ); ?></th>
						<td>
							<?php
							echo esc_html(
								$item->isUpdate()
									? __( 'Stays as it is: updates never change the author.', 'sceneshifter' )
									: ( null !== $item->author
									/* translators: %s: user's name. */
									? sprintf( __( '%s (same email address)', 'sceneshifter' ), $item->author['name'] )
									: ( $this->pull
										? __( 'No user here has the same email address, so the default author in Settings → Push is used.', 'sceneshifter' )
										: __( 'No user there has the same email address, so that site\'s default author is used.', 'sceneshifter' ) ) )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Media', 'sceneshifter' ); ?></th>
						<td>
							<?php
							echo esc_html(
								$this->pull
									/* translators: 1: number of files already here, 2: number to copy. */
									? sprintf( __( '%1$d already here, %2$d to copy', 'sceneshifter' ), $item->mediaFound, count( $item->media ) )
									/* translators: 1: number of files already there, 2: number to copy. */
									: sprintf( __( '%1$d already there, %2$d to copy', 'sceneshifter' ), $item->mediaFound, count( $item->media ) )
							);
							if ( array() !== $item->media ) {
								echo '<ul class="sceneshifter-push-list">';
								foreach ( $item->media as $media ) {
									echo '<li><code>' . esc_html( $media['file'] ) . '</code>' . ( null !== $media['size'] ? ' <span class="description">' . esc_html( size_format( $media['size'] ) ) . '</span>' : '' ) . '</li>';
								}
								echo '</ul>';
							}
							?>
						</td>
					</tr>
					<?php if ( array() !== $item->newTerms ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'New terms', 'sceneshifter' ); ?></th>
							<td>
								<?php echo esc_html( implode( ', ', array_map( static fn( array $t ): string => $t['name'] . ' (' . $t['taxonomy'] . ')', $item->newTerms ) ) ); ?>
								<p class="description"><?php echo esc_html( $this->pull ? __( 'These don\'t exist here yet and would be created.', 'sceneshifter' ) : __( 'These don\'t exist there yet and would be created.', 'sceneshifter' ) ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
			foreach ( $this->lists( $item ) as [ $entries, $heading, $help ] ) :
				if ( array() === $entries ) {
					continue;
				}
				?>
				<h3><?php echo esc_html( $heading ); ?></h3>
				<p class="description"><?php echo esc_html( $help ); ?></p>
				<ul class="sceneshifter-push-list">
					<?php foreach ( $entries as $missing ) : ?>
						<li><?php echo esc_html( PushPage::missingLabel( $missing ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<?php if ( array() !== $item->rewrites ) : ?>
				<details class="sceneshifter-push-rewrites">
					<summary>
						<?php
						echo esc_html(
							$this->pull
								/* translators: %d: number of changes. */
								? sprintf( _n( '%d link or ID rewritten for this site', '%d links and IDs rewritten for this site', count( $item->rewrites ), 'sceneshifter' ), count( $item->rewrites ) )
								/* translators: %d: number of changes. */
								: sprintf( _n( '%d link or ID rewritten for the other site', '%d links and IDs rewritten for the other site', count( $item->rewrites ), 'sceneshifter' ), count( $item->rewrites ) )
						);
						?>
					</summary>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Where', 'sceneshifter' ); ?></th>
								<th><?php esc_html_e( 'From', 'sceneshifter' ); ?></th>
								<th><?php esc_html_e( 'To', 'sceneshifter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $item->rewrites, 0, self::REWRITE_LIMIT ) as $rewrite ) : ?>
								<tr>
									<td><?php echo esc_html( $rewrite->location ); ?></td>
									<td><code><?php echo esc_html( self::shown( $rewrite ) ? $rewrite->old : '#' . $rewrite->old ); ?></code></td>
									<td><code><?php echo esc_html( self::shown( $rewrite ) ? $rewrite->new : '#' . $rewrite->new ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	private function action( PreviewItem $item ): string {
		if ( ! $item->isUpdate() ) {
			/* translators: %s: environment name. */
			return $this->pull ? __( 'New on this site.', 'sceneshifter' ) : sprintf( __( 'New on %s.', 'sceneshifter' ), $this->other->label );
		}
		$how = match ( (string) ( $item->target['how'] ?? '' ) ) {
			Resolver::LINKED => __( 'linked', 'sceneshifter' ),
			Resolver::UUID   => __( 'same Sceneshifter ID', 'sceneshifter' ),
			Resolver::PATH   => __( 'same address, not linked yet', 'sceneshifter' ),
			default          => __( 'matched', 'sceneshifter' ),
		};
		$title = (string) ( $item->target['title'] ?? '' );
		$id    = (int) ( $item->target['id'] ?? 0 );
		return $this->pull
			/* translators: 1: post title here, 2: post ID here, 3: how it was matched. */
			? sprintf( __( 'Updates "%1$s" on this site (ID %2$d, %3$s).', 'sceneshifter' ), $title, $id, $how )
			/* translators: 1: environment name, 2: post title there, 3: post ID there, 4: how it was matched. */
			: sprintf( __( 'Updates "%2$s" on %1$s (ID %3$d, %4$s).', 'sceneshifter' ), $this->other->label, $title, $id, $how );
	}

	private function statusText( PreviewItem $item ): string {
		$status = self::statusLabel( (string) ( $item->target['status'] ?? '' ) );
		if ( $item->isUpdate() ) {
			/* translators: %s: post status, for example "Published". */
			return $this->pull ? sprintf( __( 'Keeps its status here (%s), unless you choose otherwise below.', 'sceneshifter' ), $status ) : sprintf( __( 'Keeps its status there (%s), unless you choose otherwise below.', 'sceneshifter' ), $status );
		}
		return __( 'Arrives as a draft, unless you choose otherwise below.', 'sceneshifter' );
	}

	/**
	 * @return list<string>
	 */
	private function errors( PreviewItem $item ): array {
		$errors = array();
		foreach ( $item->foreign as $finding ) {
			$errors[] = $this->pull
				? sprintf(
					/* translators: 1: environment the link points at, 2: environment pulled from, 3: URL, 4: where it is. */
					__( 'Links to %1$s, which is neither %2$s nor this site: %3$s (%4$s). Fix it on %2$s before pulling.', 'sceneshifter' ),
					$finding->sister->label,
					$this->other->label,
					$finding->url,
					$finding->locationText()
				)
				: sprintf(
					/* translators: 1: environment the link points at, 2: target environment, 3: URL, 4: where it is. */
					__( 'Links to %1$s, which is neither this site nor %2$s: %3$s (%4$s). Fix it before pushing.', 'sceneshifter' ),
					$finding->sister->label,
					$this->other->label,
					$finding->url,
					$finding->locationText()
				);
		}
		foreach ( $item->checks as $check ) {
			if ( PreflightResponder::POST_TYPE_MISSING === ( $check['code'] ?? '' ) ) {
				$errors[] = $this->pull
					/* translators: %s: content type. */
					? sprintf( __( 'This site doesn\'t have the %s content type. Deploy the code that adds it first.', 'sceneshifter' ), $item->package->type() )
					/* translators: 1: environment name, 2: content type. */
					: sprintf( __( '%1$s doesn\'t have the %2$s content type. Deploy the code that adds it first.', 'sceneshifter' ), $this->other->label, $item->package->type() );
			}
		}
		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private function warnings( PreviewItem $item ): array {
		$warnings = array();
		$title    = (string) ( $item->target['title'] ?? '' );
		$id       = (int) ( $item->target['id'] ?? 0 );
		$other    = $this->other->label;
		if ( PreviewItem::EDITED === $item->conflict ) {
			$warnings[] = $this->pull
				/* translators: %s: environment name. */
				? sprintf( __( 'This post was edited here after it was last pulled from or pushed by %s. Pulling would replace those edits.', 'sceneshifter' ), $other )
				/* translators: %s: environment name. */
				: sprintf( __( 'This post was edited on %s after the last push. Pushing would replace those edits.', 'sceneshifter' ), $other );
		} elseif ( $item->overwritesUnlinked() ) {
			$warnings[] = $this->pull
				/* translators: 1: post title here, 2: post ID here, 3: environment name. */
				? sprintf( __( 'This site already has "%1$s" (ID %2$d) at the same address, but it was never linked to the post on %3$s. Pulling would overwrite it and link the two. Agree to this below, or it is left out.', 'sceneshifter' ), $title, $id, $other )
				/* translators: 1: environment name, 2: post title there, 3: post ID there. */
				: sprintf( __( '%1$s already has "%2$s" (ID %3$d) at the same address, but it was never linked to this post. Pushing would overwrite it and link the two. Agree to this below, or it is left out.', 'sceneshifter' ), $other, $title, $id );
		} elseif ( PreviewItem::NEVER_SYNCED === $item->conflict && array() !== (array) $item->changed ) {
			$warnings[] = $this->pull
				/* translators: %s: environment name. */
				? sprintf( __( 'This post has never been copied between here and %s, so any differences here would be replaced.', 'sceneshifter' ), $other )
				/* translators: %s: environment name. */
				: sprintf( __( 'This post has never been pushed to %s, so any differences there would be replaced.', 'sceneshifter' ), $other );
		}
		if ( null !== $item->blockedBy ) {
			$warnings[] = $this->pull
				/* translators: 1: post ID here, 2: environment name. */
				? sprintf( __( 'This site has a post at the same address (ID %1$d), but it is linked to different content on %2$s, so this would arrive as a new post with a changed address.', 'sceneshifter' ), $item->blockedBy, $other )
				/* translators: 1: environment name, 2: post ID there. */
				: sprintf( __( '%1$s has a post at the same address (ID %2$d), but it is linked to different content here, so this would arrive as a new post with a changed address.', 'sceneshifter' ), $other, $item->blockedBy );
		}
		$waiting = $item->waitingReview();
		if ( null !== $waiting ) {
			$who  = '' === $waiting['user'] ? __( 'someone', 'sceneshifter' ) : $waiting['user'];
			$when = 0 === $waiting['at'] ? '' : (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $waiting['at'] );
			$warnings[] = $this->pull
				/* translators: 1: person, 2: environment name, 3: date and time. */
				? sprintf( __( 'An update %1$s pushed from %2$s on %3$s is waiting for review here. Pulling replaces the post, and that waiting update is thrown away.', 'sceneshifter' ), $who, $waiting['label'], $when )
				/* translators: 1: person, 2: environment name, 3: date and time, 4: environment name. */
				: sprintf( __( 'An update %1$s pushed from %2$s on %3$s is still waiting for review on %4$s. This push replaces it.', 'sceneshifter' ), $who, $waiting['label'], $when, $other );
		}
		$where = $this->pull ? __( 'This site', 'sceneshifter' ) : $other;
		foreach ( $item->checks as $check ) {
			$names = implode( ', ', array_map( 'strval', is_array( $check['names'] ?? null ) ? $check['names'] : array() ) );
			$text  = match ( (string) ( $check['code'] ?? '' ) ) {
				/* translators: 1: environment name, or "This site", 2: template file. */
				PreflightResponder::TEMPLATE_MISSING => sprintf( __( '%1$s doesn\'t have the page template %2$s. The post would use the default template.', 'sceneshifter' ), $where, (string) ( $check['template'] ?? '' ) ),
				/* translators: 1: environment name, or "This site", 2: field keys. */
				PreflightResponder::ACF_MISSING      => sprintf( __( '%1$s is missing ACF fields this post uses (%2$s). Their values would be saved but not shown in the editor.', 'sceneshifter' ), $where, $names ),
				/* translators: 1: environment name, or "This site", 2: taxonomy names. */
				PreflightResponder::TAXONOMY_MISSING => sprintf( __( '%1$s doesn\'t have these taxonomies: %2$s. Those terms would be skipped.', 'sceneshifter' ), $where, $names ),
				/* translators: 1: environment name, or "This site", 2: block names. */
				PreflightResponder::BLOCKS_MISSING   => sprintf( __( '%1$s doesn\'t have these blocks: %2$s. They would show as unsupported in the editor.', 'sceneshifter' ), $where, $names ),
				default                              => '',
			};
			if ( '' !== $text ) {
				$warnings[] = $text;
			}
		}
		return $warnings;
	}

	/**
	 * References the receiving site lacks, and ones already broken on the source.
	 *
	 * @return list<array{0: list<array{id: int, kind: string, title: string, location: string, gone: bool}>, 1: string, 2: string}>
	 */
	private function lists( PreviewItem $item ): array {
		$missing = array_values( array_filter( $item->missing, static fn( array $m ): bool => ! $m['gone'] ) );
		$gone    = array_values( array_filter( $item->missing, static fn( array $m ): bool => $m['gone'] ) );
		$other   = $this->other->label;
		if ( $this->pull ) {
			return array(
				/* translators: %s: environment name. */
				array( $missing, __( 'Missing on this site', 'sceneshifter' ), sprintf( __( 'Linked content isn\'t pulled with this post. Pull it from %s first, or the reference would point at nothing.', 'sceneshifter' ), $other ) ),
				/* translators: %s: environment name. */
				array( $gone, sprintf( __( 'Already broken on %s', 'sceneshifter' ), $other ), sprintf( __( 'This content refers to things that have been deleted on %s, so the reference points at nothing on either site. Fix it there if it matters.', 'sceneshifter' ), $other ) ),
			);
		}
		return array(
			/* translators: %s: environment name. */
			array( $missing, __( 'Missing on the other site', 'sceneshifter' ), sprintf( __( 'Linked content isn\'t pushed with this post. Push it to %s first, or the reference would point at nothing.', 'sceneshifter' ), $other ) ),
			array( $gone, __( 'Already broken on this site', 'sceneshifter' ), __( 'This content refers to things that have been deleted here, so the reference points at nothing on either site. Fix it in the editor if it matters.', 'sceneshifter' ) ),
		);
	}

	private static function shown( Rewrite $rewrite ): bool {
		return Rewrite::URL === $rewrite->kind;
	}

	/**
	 * A status's name as this site shows it, such as Published for publish.
	 * Statuses this site doesn't know are shown as they are.
	 */
	public static function statusLabel( string $status ): string {
		$object = get_post_status_object( $status );
		return null !== $object && is_string( $object->label ) ? $object->label : $status;
	}

	public static function typeLabel( string $type ): string {
		$object = get_post_type_object( $type );
		return $object ? (string) $object->labels->singular_name : $type;
	}
}
