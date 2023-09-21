<?php
namespace Gt\Orm\Test;

use PHPUnit\Framework\TestCase;

class SQLTestCase extends TestCase {
	protected function assertSameSQL(
		string $expected,
		string $actual,
	):void {
		$expected = str_replace(["\n", "\t"], " ", $expected);
		$expected = str_replace("  ", " ", $expected);
		$actual = str_replace(["\n", "\t"], " ", $actual);
		$actual = str_replace("  ", " ", $actual);
		self::assertSame($expected, $actual);
	}
}
