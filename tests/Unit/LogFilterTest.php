<?php

declare(strict_types=1);

namespace RedC\Sceneshifter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedC\Sceneshifter\Log\LogCsv;
use RedC\Sceneshifter\Log\LogEntry;
use RedC\Sceneshifter\Log\LogFilter;
use RedC\Sceneshifter\Log\MemoryLogStore;

final class LogFilterTest extends TestCase {

	private const BATCH_A = '33333333-3333-4333-8333-333333333333';
	private const BATCH_B = '55555555-5555-4555-8555-555555555555';

	public function test_filters_by_every_field(): void {
		$log = $this->log();

		$this->assertSame( 4, $log->total() );
		$this->assertSame( array( 3, 1 ), self::ids( $log->recent( 10, 0, new LogFilter( direction: LogEntry::OUT ) ) ) );
		$this->assertSame( array( 2 ), self::ids( $log->recent( 10, 0, new LogFilter( sister: 'live' ) ) ) );
		$this->assertSame( array( 3 ), self::ids( $log->recent( 10, 0, new LogFilter( result: LogEntry::FAILED ) ) ) );
		$this->assertSame( array( 4, 1 ), self::ids( $log->recent( 10, 0, new LogFilter( search: 'about' ) ) ), 'Title, any case.' );
		$this->assertSame( array( 4 ), self::ids( $log->recent( 10, 0, new LogFilter( search: 'CY' ) ) ), 'Or the person.' );
		$this->assertSame( array( 4, 3 ), self::ids( $log->recent( 10, 0, new LogFilter( transfer: self::BATCH_B ) ) ) );
		$this->assertSame( 1, $log->total( new LogFilter( direction: LogEntry::IN, sister: 'staging' ) ) );
	}

	public function test_dates_cover_whole_days_in_the_site_time_zone(): void {
		$log    = $this->log();
		$london = new \DateTimeZone( 'Europe/London' );
		// Entry 3 is at 2026-07-01 23:30 UTC, which is 00:30 on 2 July in London.
		$this->assertSame( array( 3 ), self::ids( $log->recent( 10, 0, LogFilter::fromInput( array( 'from' => '2026-07-02', 'to' => '2026-07-02' ), $london ) ) ) );
		$this->assertSame( array( 3 ), self::ids( $log->recent( 10, 0, LogFilter::fromInput( array( 'from' => '2026-07-01', 'to' => '2026-07-01' ) ) ) ), 'UTC when no zone is given.' );

		$filter = LogFilter::fromInput( array( 'from' => '2026-07-02', 'to' => '2026-07-03' ), $london );
		$this->assertSame( array( 'from' => '2026-07-02', 'to' => '2026-07-03' ), $filter->toQuery( $london ), 'Links keep the same days.' );
	}

	public function test_untrusted_input_is_dropped(): void {
		$filter = LogFilter::fromInput(
			array(
				'direction' => 'sideways',
				'sister'    => 'Live; DROP',
				'result'    => 'done',
				'from'      => '2026-02-30',
				'to'        => 'yesterday',
				'transfer'  => 'not-a-uuid',
				'search'    => '  about  ',
			)
		);
		$this->assertSame( '', $filter->direction );
		$this->assertSame( '', $filter->sister );
		$this->assertSame( LogEntry::DONE, $filter->result );
		$this->assertNull( $filter->from );
		$this->assertNull( $filter->to );
		$this->assertSame( '', $filter->transfer );
		$this->assertSame( 'about', $filter->search );
		$this->assertTrue( LogFilter::fromInput( array() )->isEmpty() );
	}

	public function test_csv_rows_and_formula_guard(): void {
		$entry = new LogEntry( 7, self::BATCH_A, LogEntry::IN, 'staging', '=HYPERLINK("x")', 11, 900, 'About, "us"', LogEntry::DONE, true, null, array( 'pulled' => true, 'media' => 2 ), 1782948600 );
		$row   = LogCsv::row( $entry );

		$this->assertCount( count( LogCsv::header() ), $row );
		$this->assertSame( array( '7', '2026-07-01 23:30:00', 'in', 'staging' ), array_slice( $row, 0, 4 ) );
		$this->assertSame( array( '900', '11' ), array_slice( $row, 5, 2 ), 'Here and there swap for incoming entries.' );
		$this->assertSame( 'yes', $row[10] );
		$this->assertSame( '2', $row[11] );

		$line = LogCsv::line( $row );
		$this->assertStringContainsString( ',"\'=HYPERLINK(""x"")",', $line, 'A formula becomes text.' );
		$this->assertStringContainsString( ',"About, ""us""",', $line );
		$this->assertStringEndsWith( "\r\n", $line );
		$this->assertSame( "\"'-1\",a\r\n", LogCsv::line( array( '-1', 'a' ) ) );
	}

	private function log(): MemoryLogStore {
		$log = new MemoryLogStore();
		$log->add( new LogEntry( 0, self::BATCH_A, LogEntry::OUT, 'staging', 'Ann', 11, 900, 'About us', LogEntry::DONE, true, null, array(), 1782000000 ) );
		$log->add( new LogEntry( 0, self::BATCH_A, LogEntry::IN, 'live', 'Bo', 21, 31, 'Contact', LogEntry::UNDONE, false, 'Bo', array(), 1782100000 ) );
		$log->add( new LogEntry( 0, self::BATCH_B, LogEntry::OUT, 'dev', 'Ann', 12, null, 'News', LogEntry::FAILED, false, 'Lost contact', array(), 1782948600 ) );
		$log->add( new LogEntry( 0, self::BATCH_B, LogEntry::IN, 'staging', 'Cy', 13, 14, 'ABOUT the team', LogEntry::HELD, false, null, array(), 1783100000 ) );
		return $log;
	}

	/**
	 * @param list<LogEntry> $entries
	 * @return list<int>
	 */
	private static function ids( array $entries ): array {
		return array_map( static fn( LogEntry $e ): int => $e->id, $entries );
	}
}
