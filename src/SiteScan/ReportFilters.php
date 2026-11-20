<?php
/**
 * Filters, search and sort for the report, read from the query string.
 *
 * @package RedC\SisterSync
 */

declare(strict_types=1);

namespace RedC\SisterSync\SiteScan;

use RedC\SisterSync\Scanner\Finding;

final class ReportFilters {

	public function __construct(
		public readonly string $sister = '',
		public readonly string $severity = '',
		public readonly string $type = '',
		public readonly string $search = '',
		public readonly string $orderBy = 'item',
		public readonly string $order = 'ASC'
	) {}

	/**
	 * @param array<string, mixed> $query Usually $_GET.
	 */
	public static function fromQuery( array $query ): self {
		$get = static fn( string $key ): string => isset( $query[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $query[ $key ] ) ) : '';

		$severity = $get( 'severity' );
		$orderBy  = $get( 'orderby' );

		return new self(
			sanitize_key( $get( 'sister' ) ),
			in_array( $severity, array( Finding::ERROR, Finding::WARNING ), true ) ? $severity : '',
			(string) preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( $get( 'type' ) ) ),
			$get( 's' ),
			in_array( $orderBy, array( 'item', 'severity', 'sister' ), true ) ? $orderBy : 'item',
			'desc' === strtolower( $get( 'order' ) ) ? 'DESC' : 'ASC'
		);
	}

	/**
	 * @return array<string, string> Non-empty filters, for building links.
	 */
	public function toQuery(): array {
		return array_filter(
			array(
				'sister'   => $this->sister,
				'severity' => $this->severity,
				'type'     => $this->type,
				's'        => $this->search,
			),
			static fn( string $v ): bool => '' !== $v
		);
	}
}
