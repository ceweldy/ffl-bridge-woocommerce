<?php
/**
 * A small subset of the PHPUnit 11 API, used only when PHPUnit itself is not
 * installed (CI runs the suite on a plain php:8.3-cli image with no Composer).
 * The tests are ordinary PHPUnit tests and also run under real PHPUnit with
 * tests/php/woocommerce/phpunit.xml.dist.
 *
 * Only the assertions and attributes the plugin tests use are implemented.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

namespace PHPUnit\Framework\Attributes {
	use Attribute;

	#[Attribute( Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
	final class DataProvider {
		public function __construct( public string $methodName ) {}
	}

	#[Attribute( Attribute::TARGET_METHOD | Attribute::TARGET_CLASS )]
	final class RunInSeparateProcess {}

	#[Attribute( Attribute::TARGET_METHOD | Attribute::TARGET_CLASS )]
	final class PreserveGlobalState {
		public function __construct( public bool $enabled = true ) {}
	}
}

namespace PHPUnit\Framework {
	use Exception;
	use Throwable;

	class AssertionFailedError extends Exception {}

	final class SkippedTest extends Exception {}

	abstract class TestCase {
		public static int $assertions = 0;

		/** @var class-string<Throwable>|null */
		private ?string $expected_exception = null;

		private ?string $expected_message = null;

		protected function setUp(): void {}

		protected function tearDown(): void {}

		/** @return array{0: ?string, 1: ?string} */
		public function ffl_shim_expectations(): array {
			return array( $this->expected_exception, $this->expected_message );
		}

		public function ffl_shim_set_up(): void {
			$this->setUp();
		}

		public function ffl_shim_tear_down(): void {
			$this->tearDown();
		}

		/** @param class-string<Throwable> $class */
		public function expectException( string $class ): void {
			$this->expected_exception = $class;
		}

		public function expectExceptionMessage( string $message ): void {
			$this->expected_message = $message;
		}

		public static function fail( string $message = '' ): never {
			throw new AssertionFailedError( '' !== $message ? $message : 'Failed.' );
		}

		public static function markTestSkipped( string $message = '' ): never {
			throw new SkippedTest( $message );
		}

		private static function check( bool $ok, string $message, string $default ): void {
			++self::$assertions;
			if ( ! $ok ) {
				throw new AssertionFailedError( '' !== $message ? $message . "\n" . $default : $default );
			}
		}

		private static function show( mixed $value ): string {
			$text = var_export( $value, true );
			return strlen( $text ) > 600 ? substr( $text, 0, 600 ) . '...' : $text;
		}

		public static function assertSame( mixed $expected, mixed $actual, string $message = '' ): void {
			self::check( $expected === $actual, $message, 'Failed asserting that ' . self::show( $actual ) . ' is identical to ' . self::show( $expected ) . '.' );
		}

		public static function assertNotSame( mixed $expected, mixed $actual, string $message = '' ): void {
			self::check( $expected !== $actual, $message, 'Failed asserting that ' . self::show( $actual ) . ' is not identical to ' . self::show( $expected ) . '.' );
		}

		public static function assertEquals( mixed $expected, mixed $actual, string $message = '' ): void {
			self::check( $expected == $actual, $message, 'Failed asserting that ' . self::show( $actual ) . ' equals ' . self::show( $expected ) . '.' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
		}

		public static function assertTrue( mixed $condition, string $message = '' ): void {
			self::check( true === $condition, $message, 'Failed asserting that ' . self::show( $condition ) . ' is true.' );
		}

		public static function assertFalse( mixed $condition, string $message = '' ): void {
			self::check( false === $condition, $message, 'Failed asserting that ' . self::show( $condition ) . ' is false.' );
		}

		public static function assertNull( mixed $actual, string $message = '' ): void {
			self::check( null === $actual, $message, 'Failed asserting that ' . self::show( $actual ) . ' is null.' );
		}

		public static function assertNotNull( mixed $actual, string $message = '' ): void {
			self::check( null !== $actual, $message, 'Failed asserting that a value is not null.' );
		}

		public static function assertCount( int $expected, mixed $haystack, string $message = '' ): void {
			$count = is_countable( $haystack ) ? count( $haystack ) : -1;
			self::check( $expected === $count, $message, "Failed asserting that actual size {$count} matches expected size {$expected}." );
		}

		public static function assertEmpty( mixed $actual, string $message = '' ): void {
			self::check( empty( $actual ), $message, 'Failed asserting that ' . self::show( $actual ) . ' is empty.' );
		}

		public static function assertNotEmpty( mixed $actual, string $message = '' ): void {
			self::check( ! empty( $actual ), $message, 'Failed asserting that a value is not empty.' );
		}

		public static function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {
			self::check( str_contains( $haystack, $needle ), $message, 'Failed asserting that ' . self::show( $haystack ) . ' contains ' . self::show( $needle ) . '.' );
		}

		public static function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ): void {
			self::check( ! str_contains( $haystack, $needle ), $message, 'Failed asserting that ' . self::show( $haystack ) . ' does not contain ' . self::show( $needle ) . '.' );
		}

		public static function assertStringContainsStringIgnoringCase( string $needle, string $haystack, string $message = '' ): void {
			self::check( false !== stripos( $haystack, $needle ), $message, 'Failed asserting that ' . self::show( $haystack ) . ' contains ' . self::show( $needle ) . ' (ignoring case).' );
		}

		public static function assertStringNotContainsStringIgnoringCase( string $needle, string $haystack, string $message = '' ): void {
			self::check( false === stripos( $haystack, $needle ), $message, 'Failed asserting that ' . self::show( $haystack ) . ' does not contain ' . self::show( $needle ) . ' (ignoring case).' );
		}

		public static function assertStringStartsWith( string $prefix, string $string, string $message = '' ): void {
			self::check( str_starts_with( $string, $prefix ), $message, 'Failed asserting that ' . self::show( $string ) . ' starts with ' . self::show( $prefix ) . '.' );
		}

		public static function assertMatchesRegularExpression( string $pattern, string $string, string $message = '' ): void {
			self::check( 1 === preg_match( $pattern, $string ), $message, 'Failed asserting that ' . self::show( $string ) . ' matches ' . $pattern . '.' );
		}

		public static function assertArrayHasKey( int|string $key, array $array, string $message = '' ): void {
			self::check( array_key_exists( $key, $array ), $message, 'Failed asserting that an array has the key ' . self::show( $key ) . '.' );
		}

		public static function assertArrayNotHasKey( int|string $key, array $array, string $message = '' ): void {
			self::check( ! array_key_exists( $key, $array ), $message, 'Failed asserting that an array does not have the key ' . self::show( $key ) . '.' );
		}

		public static function assertContains( mixed $needle, iterable $haystack, string $message = '' ): void {
			$found = false;
			foreach ( $haystack as $item ) {
				if ( $item === $needle ) {
					$found = true;
					break;
				}
			}
			self::check( $found, $message, 'Failed asserting that an iterable contains ' . self::show( $needle ) . '.' );
		}

		public static function assertNotContains( mixed $needle, iterable $haystack, string $message = '' ): void {
			foreach ( $haystack as $item ) {
				if ( $item === $needle ) {
					self::check( false, $message, 'Failed asserting that an iterable does not contain ' . self::show( $needle ) . '.' );
				}
			}
			self::check( true, $message, '' );
		}

		public static function assertInstanceOf( string $expected, mixed $actual, string $message = '' ): void {
			self::check( $actual instanceof $expected, $message, 'Failed asserting that ' . get_debug_type( $actual ) . " is an instance of {$expected}." );
		}

		public static function assertIsArray( mixed $actual, string $message = '' ): void {
			self::check( is_array( $actual ), $message, 'Failed asserting that ' . get_debug_type( $actual ) . ' is of type array.' );
		}

		public static function assertIsString( mixed $actual, string $message = '' ): void {
			self::check( is_string( $actual ), $message, 'Failed asserting that ' . get_debug_type( $actual ) . ' is of type string.' );
		}

		public static function assertGreaterThan( mixed $expected, mixed $actual, string $message = '' ): void {
			self::check( $actual > $expected, $message, 'Failed asserting that ' . self::show( $actual ) . ' is greater than ' . self::show( $expected ) . '.' );
		}

		public static function assertFileExists( string $filename, string $message = '' ): void {
			self::check( file_exists( $filename ), $message, "Failed asserting that file \"{$filename}\" exists." );
		}

		public static function assertFileDoesNotExist( string $filename, string $message = '' ): void {
			self::check( ! file_exists( $filename ), $message, "Failed asserting that file \"{$filename}\" does not exist." );
		}
	}
}
