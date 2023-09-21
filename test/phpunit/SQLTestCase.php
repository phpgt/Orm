<?php
namespace Gt\Orm\Test;

use PHPUnit\Framework\TestCase;

class SQLTestCase extends TestCase {
	protected function assertSameSQL(
		string $expected,
		string $actual,
	):void {
		self::assertSame($expected, $actual);
	}
}
