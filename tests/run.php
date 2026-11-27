<?php
/**
 * Runs the WooCommerce plugin unit tests without Composer.
 *
 *   php tests/php/woocommerce/run.php                    all tests
 *   php tests/php/woocommerce/run.php --filter=ShipTo    tests whose Class::method contains the text
 *
 * Uses the small PHPUnit subset in lib/phpunit-shim.php. With PHPUnit 11
 * installed, `phpunit -c tests/php/woocommerce/phpunit.xml.dist` runs the same
 * tests. Tests marked #[RunInSeparateProcess] run in a child
 * PHP process with a fresh bootstrap, as PHPUnit does.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;

error_reporting( E_ALL );
set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

$options = getopt( '', array( 'filter:', 'single:' ) );
$filter  = is_string( $options['filter'] ?? null ) ? $options['filter'] : '';
$single  = is_string( $options['single'] ?? null ) ? $options['single'] : '';

require __DIR__ . '/lib/phpunit-shim.php';
require __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/*Test.php' ) ?: array();
sort( $files );
foreach ( $files as $file ) {
	require_once $file;
}

/**
 * Every test case as [label, class, method, arguments, separate process].
 *
 * @return array<int, array{0: string, 1: string, 2: string, 3: array<int, mixed>, 4: bool}>
 */
function ffl_test_cases(): array {
	$cases = array();
	foreach ( get_declared_classes() as $class ) {
		$reflection = new ReflectionClass( $class );
		if ( $reflection->isAbstract() || ! $reflection->isSubclassOf( TestCase::class ) ) {
			continue;
		}

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( ! str_starts_with( $method->getName(), 'test' ) || $method->getDeclaringClass()->getName() !== $class ) {
				continue;
			}

			$separate  = array() !== $method->getAttributes( RunInSeparateProcess::class );
			$providers = $method->getAttributes( DataProvider::class );
			$label     = $class . '::' . $method->getName();
			if ( array() === $providers ) {
				$cases[] = array( $label, $class, $method->getName(), array(), $separate );
				continue;
			}

			foreach ( $providers as $provider ) {
				$name = $provider->newInstance()->methodName;
				foreach ( $class::$name() as $key => $arguments ) {
					$cases[] = array( $label . '#' . $key, $class, $method->getName(), array_values( (array) $arguments ), $separate );
				}
			}
		}
	}

	return $cases;
}

/**
 * Run one test in this process. Returns null on success, 'skip', or a failure message.
 *
 * @param array<int, mixed> $arguments Data set.
 */
function ffl_run_case( string $class, string $method, array $arguments ): ?string {
	$test  = new $class();
	$error = null;
	try {
		$test->ffl_shim_set_up();
		try {
			$test->$method( ...$arguments );
			list( $expected ) = $test->ffl_shim_expectations();
			if ( null !== $expected ) {
				$error = "Failed asserting that exception of type \"{$expected}\" is thrown.";
			}
		} catch ( SkippedTest $skipped ) {
			$error = 'skip';
		} catch ( AssertionFailedError $failure ) {
			$error = $failure->getMessage();
		} catch ( Throwable $thrown ) {
			list( $expected, $message ) = $test->ffl_shim_expectations();
			if ( null === $expected || ! $thrown instanceof $expected ) {
				$error = get_class( $thrown ) . ': ' . $thrown->getMessage() . ' at ' . $thrown->getFile() . ':' . $thrown->getLine();
			} elseif ( null !== $message && ! str_contains( $thrown->getMessage(), $message ) ) {
				$error = "Failed asserting that exception message '{$thrown->getMessage()}' contains '{$message}'.";
			}
		} finally {
			$test->ffl_shim_tear_down();
		}
	} catch ( Throwable $thrown ) {
		$error = $error ?? ( get_class( $thrown ) . ' in setUp/tearDown: ' . $thrown->getMessage() );
	}

	return $error;
}

$cases = ffl_test_cases();

if ( '' !== $single ) {
	foreach ( $cases as $case ) {
		if ( $case[0] === $single ) {
			$error = ffl_run_case( $case[1], $case[2], $case[3] );
			echo null === $error ? 'ok' : $error;
			exit( null === $error || 'skip' === $error ? 0 : 1 );
		}
	}
	echo "No test named {$single}.";
	exit( 1 );
}

$failures = array();
$passed   = 0;
$skipped  = 0;
foreach ( $cases as $case ) {
	list( $label, $class, $method, $arguments, $separate ) = $case;
	if ( '' !== $filter && ! str_contains( $label, $filter ) ) {
		continue;
	}

	if ( $separate ) {
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --single=' . escapeshellarg( $label ) . ' 2>&1', $output, $code );
		$error = 0 === $code ? null : implode( "\n", $output );
	} else {
		$error = ffl_run_case( $class, $method, $arguments );
	}

	if ( null === $error ) {
		++$passed;
	} elseif ( 'skip' === $error ) {
		++$skipped;
	} else {
		$failures[] = "FAIL {$label}\n  " . str_replace( "\n", "\n  ", $error );
	}
}

echo implode( "\n", $failures ) . ( $failures ? "\n\n" : '' );
printf( "%d passed, %d failed, %d skipped, %d assertions\n", $passed, count( $failures ), $skipped, TestCase::$assertions );
exit( $failures ? 1 : 0 );
