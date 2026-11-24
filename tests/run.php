<?php
/**
 * Lihtne testijooksja juhuks, kui PHPUnitit pole paigaldatud:  php tests/run.php
 * PHPUnitiga:  composer install && composer test
 */
namespace PHPUnit\Framework {
	if ( ! class_exists( TestCase::class ) ) {
		class AssertionFailed extends \Exception {}
		abstract class TestCase {
			public static int $assertions = 0;
			private static function check( bool $ok, string $msg ): void {
				++self::$assertions;
				if ( ! $ok ) {
					throw new AssertionFailed( $msg );
				}
			}
			public function assertSame( $e, $a ): void { self::check( $e === $a, 'Ootasin ' . var_export( $e, true ) . ', sain ' . var_export( $a, true ) ); }
			public function assertCount( int $n, $a ): void { self::check( count( $a ) === $n, "Ootasin $n elementi, sain " . count( $a ) ); }
			public function assertContains( $x, array $a ): void { self::check( in_array( $x, $a, true ), var_export( $x, true ) . ' puudub: ' . implode( ',', $a ) ); }
			public function assertNotContains( $x, array $a ): void { self::check( ! in_array( $x, $a, true ), var_export( $x, true ) . ' ei tohiks olla: ' . implode( ',', $a ) ); }
			public function assertNotEmpty( $a ): void { self::check( ! empty( $a ), 'Ootasin mittetühja väärtust' ); }
		}
	}
}
namespace {
	require __DIR__ . '/bootstrap.php';
	$fail = 0;
	$pass = 0;
	foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
		require $file;
		$class = basename( $file, '.php' );
		$t     = new $class( $class );
		foreach ( get_class_methods( $t ) as $m ) {
			if ( ! str_starts_with( $m, 'test' ) ) {
				continue;
			}
			try {
				$t->$m();
				++$pass;
				echo "  ✔ $class::$m\n";
			} catch ( \Throwable $e ) {
				++$fail;
				echo "  ✘ $class::$m — " . $e->getMessage() . "\n";
			}
		}
	}
	echo "\n$pass läbitud, $fail ebaõnnestus, " . \PHPUnit\Framework\TestCase::$assertions . " kontrolli\n";
	exit( $fail ? 1 : 0 );
}
