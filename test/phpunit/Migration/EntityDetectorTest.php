<?php
namespace GT\Orm\Test\Migration;

use GT\Orm\Migration\EntityDetector;
use GT\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\NestedNamespace\OrderEntity;
use GT\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\NotAnEntity;
use GT\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\PersonEntity;
use PHPUnit\Framework\TestCase;

class EntityDetectorTest extends TestCase {
	public function testGetEntityClassList_noFiles():void {
		$tmpDir = sys_get_temp_dir() . "/phpgt/orm/test/" . uniqid();
		mkdir($tmpDir, recursive: true);
		$sut = new EntityDetector();
		self::assertEmpty($sut->getEntityClassList($tmpDir));
	}

	public function testGetEntityClassList_missingDirectory():void {
		$sut = new EntityDetector();
		self::assertSame([], $sut->getEntityClassList("/path/that/does/not/exist"));
	}

	public function testGetEntityClassList():void {
		$dir = "test/phpunit/TestProject/EntityDetectorTest";
		$sut = new EntityDetector();
		$detected = $sut->getEntityClassList($dir);
		self::assertCount(2, $detected);
		self::assertContains(OrderEntity::class, $detected);
		self::assertContains(PersonEntity::class, $detected);
		self::assertNotContains(NotAnEntity::class, $detected);
		self::assertSame($detected, array_values(array_unique($detected)));
		$sorted = $detected;
		sort($sorted);
		self::assertSame($sorted, $detected);
	}
}
