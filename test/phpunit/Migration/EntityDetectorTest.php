<?php
namespace Gt\Orm\Test\Migration;

use Gt\Orm\Migration\EntityDetector;
use Gt\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\NestedNamespace\OrderEntity;
use Gt\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\NotAnEntity;
use Gt\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\PersonEntity;
use PHPUnit\Framework\TestCase;

class EntityDetectorTest extends TestCase {
	public function testGetEntityClassList_noFiles():void {
		$tmpDir = sys_get_temp_dir() . "/phpgt/orm/test/" . uniqid();
		mkdir($tmpDir, recursive: true);
		$sut = new EntityDetector();
		self::assertEmpty($sut->getEntityClassList($tmpDir));
	}

	public function testGetEntityClassList():void {
		$dir = "test/phpunit/TestProject/EntityDetectorTest";
		$sut = new EntityDetector();
		$detected = $sut->getEntityClassList($dir);
		self::assertCount(2, $detected);
		self::assertContains(OrderEntity::class, $detected);
		self::assertContains(PersonEntity::class, $detected);
		self::assertNotContains(NotAnEntity::class, $detected);
	}
}
