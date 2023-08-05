<?php
namespace Gt\Orm\Test;

use Gt\Orm\Repository;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase {
	public function testTest():void {
		$sut = new Repository();
		self::assertInstanceOf(Repository::class, $sut);
	}
}
