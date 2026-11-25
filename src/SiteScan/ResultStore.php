<?php
/**
 * Reads and writes rows in the scan results table.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SiteScan;

use RedC\Sceneshifter\Scanner\Finding;

final class ResultStore {

	/**
	 * ID of the most recent completed scan, which the report shows.
	 */
	public const REPORT_SCAN_OPTION = 'sceneshifter_report_scan';

	/**
	 * @param list<Finding> $findings
	 */
	public function insert( string $scanId, ScannedObject $object, array $findings ): void {
		global $wpdb;
		foreach ( $findings as $finding ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				Schema::resultsTable(),
				array(
					'scan_id'        => $scanId,
					'object_type'    => $object->type,
					'object_subtype' => substr( $object->subtype, 0, 40 ),
					'object_id'      => $object->id,
					'object_key'     => substr( $object->key, 0, 191 ),
					'object_title'   => $object->title,
					'severity'       => $finding->severity,
					'sister_id'      => $finding->sister->id,
					'url'            => $finding->url,
					'area'           => $finding->area,
					'location'       => $finding->locationText(),
					'hits'           => $finding->count,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	public function reportScanId(): string {
		return (string) get_option( self::REPORT_SCAN_OPTION, '' );
	}

	/**
	 * Makes a finished scan the one the report shows and deletes every other
	 * scan's rows.
	 */
	public function publish( string $scanId ): void {
		global $wpdb;
		update_option( self::REPORT_SCAN_OPTION, $scanId, false );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::resultsTable() . ' WHERE scan_id <> %s', $scanId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Findings in one scan that weren't in another: the same item with the
	 * same URL counts as already known, wherever in the item it is.
	 *
	 * @param string $scanId     The new scan.
	 * @param string $previousId The scan to compare with, or '' for none (everything is new).
	 * @param int    $limit      How many rows to return; errors first.
	 * @return array{count: int, rows: list<array<string, mixed>>}
	 */
	public function newFindings( string $scanId, string $previousId, int $limit ): array {
		global $wpdb;
		$table = Schema::resultsTable();
		$where = $wpdb->prepare(
			"n.scan_id = %s AND NOT EXISTS (SELECT 1 FROM {$table} p WHERE p.scan_id = %s AND p.object_type = n.object_type AND p.object_id = n.object_id AND p.object_key = n.object_key AND p.url = n.url)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$scanId,
			$previousId
		);

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} n WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = 0 === $count ? array() : (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT n.* FROM {$table} n WHERE {$where} ORDER BY CASE WHEN n.severity = 'error' THEN 0 ELSE 1 END, n.object_title, n.id LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'count' => $count,
			'rows'  => array_values( $rows ),
		);
	}

	/**
	 * Deletes one scan's rows (a cancelled or failed scan).
	 */
	public function discard( string $scanId ): void {
		global $wpdb;
		if ( $scanId === $this->reportScanId() ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::resultsTable() . ' WHERE scan_id = %s', $scanId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Removes any partial rows for one object, so a batch that is re-run
	 * after a crash doesn't double-count.
	 */
	public function clearObject( string $scanId, string $type, int $id, string $key = '' ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM ' . Schema::resultsTable() . ' WHERE scan_id = %s AND object_type = %s AND object_id = %d AND object_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$scanId,
				$type,
				$id,
				$key
			)
		);
	}

	/**
	 * Removes a scan's menu, widget, option and theme setting rows before
	 * that phase runs (again).
	 */
	public function clearNonPosts( string $scanId ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::resultsTable() . ' WHERE scan_id = %s AND object_type <> %s', $scanId, ScannedObject::POST ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function query( string $scanId, ReportFilters $filters, int $page = 1, int $perPage = 50 ): array {
		global $wpdb;
		[ $where, $args ] = $this->where( $scanId, $filters );
		$table            = Schema::resultsTable();

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery

		$orderBy = match ( $filters->orderBy ) {
			'severity' => 'severity ' . $filters->order . ', object_title ASC, id ASC',
			'sister'   => 'sister_id ' . $filters->order . ', object_title ASC, id ASC',
			default    => 'object_title ' . $filters->order . ', object_type ASC, object_id ASC, id ASC',
		};

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderBy} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $args, array( $perPage, max( 0, ( $page - 1 ) * $perPage ) ) )
			),
			ARRAY_A
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Every matching row, for CSV export.
	 *
	 * @return \Generator<array<string, mixed>>
	 */
	public function all( string $scanId, ReportFilters $filters ): \Generator {
		$page = 1;
		do {
			$result = $this->query( $scanId, $filters, $page, 500 );
			yield from $result['rows'];
			++$page;
		} while ( count( $result['rows'] ) === 500 );
	}

	/**
	 * @return array{error: int, warning: int, objects: int}
	 */
	public function summary( string $scanId ): array {
		global $wpdb;
		$table = Schema::resultsTable();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN severity = %s THEN hits ELSE 0 END), 0) AS error,
					COALESCE(SUM(CASE WHEN severity = %s THEN hits ELSE 0 END), 0) AS warning,
					COUNT(DISTINCT CONCAT(object_type, ':', object_id, ':', object_key)) AS objects
				FROM {$table} WHERE scan_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				Finding::ERROR,
				Finding::WARNING,
				$scanId
			),
			ARRAY_A
		);
		return array(
			'error'   => (int) ( $row['error'] ?? 0 ),
			'warning' => (int) ( $row['warning'] ?? 0 ),
			'objects' => (int) ( $row['objects'] ?? 0 ),
		);
	}

	/**
	 * Distinct values of a column in one scan, for filter dropdowns.
	 *
	 * @return list<array{value: string, subtype: string}>
	 */
	public function distinctTypes( string $scanId ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT DISTINCT object_type AS value, object_subtype AS subtype FROM ' . Schema::resultsTable() . ' WHERE scan_id = %s ORDER BY object_type, object_subtype', $scanId ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<string>
	 */
	public function distinctSisters( string $scanId ): array {
		global $wpdb;
		return array_map(
			'strval',
			(array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT sister_id FROM ' . Schema::resultsTable() . ' WHERE scan_id = %s ORDER BY sister_id', $scanId ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Error and warning counts per post, for the list-screen column.
	 *
	 * @param list<int> $postIds
	 * @return array<int, array{error: int, warning: int}>
	 */
	public function countsForPosts( string $scanId, array $postIds ): array {
		global $wpdb;
		$postIds = array_values( array_filter( array_map( 'intval', $postIds ) ) );
		if ( '' === $scanId || array() === $postIds ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $postIds ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT object_id, severity, SUM(hits) AS n FROM ' . Schema::resultsTable() . " WHERE scan_id = %s AND object_type = %s AND object_id IN ({$placeholders}) GROUP BY object_id, severity", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $scanId, ScannedObject::POST ), $postIds )
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$id                                  = (int) $row['object_id'];
			$counts[ $id ]                     ??= array(
				Finding::ERROR   => 0,
				Finding::WARNING => 0,
			);
			$counts[ $id ][ (string) $row['severity'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function where( string $scanId, ReportFilters $filters ): array {
		global $wpdb;
		$where = array( 'scan_id = %s' );
		$args  = array( $scanId );

		if ( '' !== $filters->sister ) {
			$where[] = 'sister_id = %s';
			$args[]  = $filters->sister;
		}
		if ( '' !== $filters->severity ) {
			$where[] = 'severity = %s';
			$args[]  = $filters->severity;
		}
		if ( '' !== $filters->type ) {
			[ $type, $subtype ] = array_pad( explode( ':', $filters->type, 2 ), 2, null );
			$where[]            = 'object_type = %s';
			$args[]             = $type;
			if ( null !== $subtype && '' !== $subtype ) {
				$where[] = 'object_subtype = %s';
				$args[]  = $subtype;
			}
		}
		if ( '' !== $filters->search ) {
			$like    = '%' . $wpdb->esc_like( $filters->search ) . '%';
			$where[] = '(url LIKE %s OR object_title LIKE %s OR location LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		return array( implode( ' AND ', $where ), $args );
	}
}
