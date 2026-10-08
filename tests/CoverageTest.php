<?php
/**
 * Coverage gap log and admin notice decision tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

final class CoverageTest extends FFL_Bridge_TestCase {
	private const NOW = 1790000000;

	public function test_gap_is_recorded_by_three_digit_zip_area_only(): void {
		FFL_Bridge_Coverage::record_gap( '48047', 50, false, '', self::NOW );
		FFL_Bridge_Coverage::record_gap( '48093', 100, true, 'NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS', self::NOW + 10 );

		$log = FFL_Bridge_Coverage::get_log( self::NOW + 20 );

		$this->assertSame( array( '480' ), array_map( 'strval', array_keys( $log['areas'] ) ) );
		$this->assertSame( 2, $log['areas']['480']['count'] );
		$this->assertSame( 1, $log['areas']['480']['fallback'] );
		$this->assertSame( 100, $log['areas']['480']['max_radius'] );
		$this->assertSame( 'NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS', $log['areas']['480']['reason'] );
		$this->assertSame( 2, FFL_Bridge_Coverage::total( $log ) );
		$this->assertStringNotContainsString( '48047', (string) wp_json_encode( $GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Coverage::OPTION ] ) );
	}

	public function test_invalid_zip_is_ignored(): void {
		FFL_Bridge_Coverage::record_gap( '4804', 50, false, '', self::NOW );

		$this->assertSame( 0, FFL_Bridge_Coverage::total( FFL_Bridge_Coverage::get_log( self::NOW ) ) );
	}

	public function test_entries_outside_the_window_are_dropped(): void {
		FFL_Bridge_Coverage::record_gap( '48047', 50, false, '', self::NOW );

		$this->assertSame( 0, FFL_Bridge_Coverage::total( FFL_Bridge_Coverage::get_log( self::NOW + FFL_Bridge_Coverage::WINDOW + 1 ) ) );
	}

	public function test_log_is_bounded_to_most_recent_areas(): void {
		for ( $i = 0; $i < FFL_Bridge_Coverage::MAX_AREAS + 5; $i++ ) {
			FFL_Bridge_Coverage::record_gap( sprintf( '%03d00', $i ), 25, false, '', self::NOW + $i );
		}

		$log = FFL_Bridge_Coverage::get_log( self::NOW + 100 );
		$this->assertCount( FFL_Bridge_Coverage::MAX_AREAS, $log['areas'] );
		$this->assertArrayNotHasKey( '000', $log['areas'] );
		$this->assertArrayHasKey( sprintf( '%03d', FFL_Bridge_Coverage::MAX_AREAS + 4 ), $log['areas'] );
	}

	public function test_malformed_stored_log_is_ignored(): void {
		$GLOBALS['ffl_bridge_test_options'][ FFL_Bridge_Coverage::OPTION ] = array( 'areas' => array( 'abc' => array( 'count' => 3, 'last' => self::NOW ), '481' => 'bad' ) );

		$this->assertSame( array(), FFL_Bridge_Coverage::get_log( self::NOW )['areas'] );
	}

	public function test_notice_shows_until_dismissed_and_returns_only_after_new_gaps_and_a_week(): void {
		$this->assertFalse( FFL_Bridge_Coverage::should_notify( FFL_Bridge_Coverage::get_log( self::NOW ), 0, self::NOW ) );

		FFL_Bridge_Coverage::record_gap( '48047', 100, false, '', self::NOW );
		$log = FFL_Bridge_Coverage::get_log( self::NOW );

		$this->assertTrue( FFL_Bridge_Coverage::should_notify( $log, 0, self::NOW ) );

		$dismissed = self::NOW + 60;
		$this->assertFalse( FFL_Bridge_Coverage::should_notify( $log, $dismissed, $dismissed + 8 * 86400 ) );

		FFL_Bridge_Coverage::record_gap( '48047', 100, false, '', $dismissed + 3600 );
		$log = FFL_Bridge_Coverage::get_log( $dismissed + 3600 );
		$this->assertFalse( FFL_Bridge_Coverage::should_notify( $log, $dismissed, $dismissed + 3600 ) );
		$this->assertTrue( FFL_Bridge_Coverage::should_notify( $log, $dismissed, $dismissed + 7 * 86400 ) );
	}
}
