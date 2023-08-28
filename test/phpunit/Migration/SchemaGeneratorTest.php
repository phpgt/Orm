<?php
namespace Gt\Orm\Test\Migration;

use DateTime;
use Gt\Orm\Migration\SchemaGenerator;
use Gt\Orm\Test\TestProjectRoot\SimpleEntitiesAndNonEntities\PersonEntity;
use PHPUnit\Framework\TestCase;

class SchemaGeneratorTest extends TestCase {
	public function testGenerate():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(PersonEntity::class);
		self::assertSame("PersonEntity", $schemaTable->name);
		self::assertSame("id", $schemaTable->getPrimaryKey()->name);
		$schemaFields = $schemaTable->getFieldList();
		self::assertCount(3, $schemaFields);

		self::assertSame("id", $schemaFields[0]->name);
		self::assertSame("string", $schemaFields[0]->getType());
		self::assertSame("name", $schemaFields[1]->name);
		self::assertSame("string", $schemaFields[1]->getType());
		self::assertSame("createdAt", $schemaFields[2]->name);
		self::assertSame(DateTime::class, $schemaFields[2]->getType());
		self::assertInstanceOf(DateTime::class, $schemaFields[2]->getDefaultValue());
	}

	public function testGenerate_object():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(new class(123, "Test Name") {
			public function __construct(
				public int $id,
				public string $name,
			) {}
		});

		self::assertSame("id", $schemaTable->getPrimaryKey()->name);
		self::assertSame("int", $schemaTable->getPrimaryKey()->getType());
		$schemaFields = $schemaTable->getFieldList();
		self::assertCount(2, $schemaFields);

		self::assertSame("id", $schemaFields[0]->name);
		self::assertSame("int", $schemaFields[0]->getType());
		self::assertSame("name", $schemaFields[1]->name);
		self::assertSame("string", $schemaFields[1]->getType());
	}

	public function testGenerate_objectWithDefaultConstructorParams():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(new class(123, "Test Name") {
			public function __construct(
				public int $id,
				public string $name = "UNKNOWN",
			) {}
		});

		$schemaFields = $schemaTable->getFieldList();
		self::assertSame("UNKNOWN", $schemaFields[1]->getDefaultValue());
	}

	public function testGenerate_objectWithDefaultProperty():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(new class(123, "Test Name") {
			public string $searchKey = "TEST_KEY";

			public function __construct(
				public int $id,
				public string $name = "UNKNOWN",
			) {}
		});

		$schemaFields = $schemaTable->getFieldList();
		self::assertCount(3, $schemaFields);
		self::assertSame("searchKey", $schemaFields[2]->name);
		self::assertSame("TEST_KEY", $schemaFields[2]->getDefaultValue());
	}
}
