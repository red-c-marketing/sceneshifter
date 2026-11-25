<?php
/**
 * A "Sister links" column on enabled list screens, showing counts from the
 * last completed site scan.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SiteScan;

use RedC\Sceneshifter\Check\CheckPage;
use RedC\Sceneshifter\Plugin;
use RedC\Sceneshifter\Scanner\Finding;

final class ListColumn {

	private const COLUMN = 'sceneshifter';

	/**
	 * Counts for the posts on the current list page, loaded in one query.
	 *
	 * @var array<int, array{error: int, warning: int}>|null
	 */
	private ?array $counts = null;

	public function __construct(
		private readonly Plugin $plugin,
		private readonly ResultStore $store = new ResultStore()
	) {}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'registerColumns' ) );
	}

	public function registerColumns(): void {
		if ( '' === $this->store->reportScanId() ) {
			return; // Nothing to show until a scan has finished.
		}
		foreach ( $this->plugin->options()->postTypes() as $postType ) {
			add_filter( "manage_{$postType}_posts_columns", array( $this, 'addColumn' ) );
			add_action( "manage_{$postType}_posts_custom_column", array( $this, 'renderColumn' ), 10, 2 );
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function addColumn( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Sister links', 'sceneshifter' );
		return $columns;
	}

	public function renderColumn( string $column, int $postId ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		if ( null === $this->counts ) {
			global $wp_query;
			$ids          = array_map( static fn( $p ) => is_object( $p ) ? (int) $p->ID : (int) $p, (array) ( $wp_query->posts ?? array() ) );
			$this->counts = $this->store->countsForPosts( $this->store->reportScanId(), $ids );
		}

		$counts = $this->counts[ $postId ] ?? null;
		if ( null === $counts ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'None', 'sceneshifter' ) . '</span>';
			return;
		}

		$parts = array();
		if ( $counts[ Finding::ERROR ] > 0 ) {
			/* translators: %d: number of errors. */
			$parts[] = sprintf( '<span style="color:#d63638;font-weight:600;">%s</span>', esc_html( sprintf( _n( '%d error', '%d errors', $counts[ Finding::ERROR ], 'sceneshifter' ), $counts[ Finding::ERROR ] ) ) );
		}
		if ( $counts[ Finding::WARNING ] > 0 ) {
			/* translators: %d: number of warnings. */
			$parts[] = sprintf( '<span style="color:#996800;">%s</span>', esc_html( sprintf( _n( '%d warning', '%d warnings', $counts[ Finding::WARNING ], 'sceneshifter' ), $counts[ Finding::WARNING ] ) ) );
		}
		printf( '<a href="%1$s">%2$s</a>', esc_url( CheckPage::url( array( $postId ) ) ), implode( '<br>', $parts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped above.
	}
}
