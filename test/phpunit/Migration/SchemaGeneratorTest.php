<?php
namespace GT\Orm\Test\Migration;

use DateTime;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\PersonEntity;
use GT\Orm\Test\TestProject\SchemaGenerator\SchemaGeneratorTestCourse;
use GT\Orm\Test\TestProject\SchemaGenerator\SchemaGeneratorTestDepartment;
use GT\Orm\Test\TestProject\SchemaGenerator\SchemaGeneratorTestLesson;
use GT\Orm\Test\TestProject\SchemaGenerator\SchemaGeneratorTestStudent;
use PHPUnit\Framework\TestCase;

class SchemaGeneratorTest extends TestCase {
	public function testGenerate():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(PersonEntity::class);
		self::assertSame("PersonEntity", $schemaTable->getName());
		self::assertSame("id", $schemaTable->getPrimaryKey()->getName());
		$schemaFields = $schemaTable->getFieldList();
		self::assertCount(3, $schemaFields);

		self::assertSame("id", $schemaFields[0]->getName());
		self::assertSame("string", $schemaFields[0]->getType());
		self::assertSame("name", $schemaFields[1]->getName());
		self::assertSame("string", $schemaFields[1]->getType());
		self::assertSame("createdAt", $schemaFields[2]->getName());
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

		self::assertSame("id", $schemaTable->getPrimaryKey()->getName());
		self::assertSame("int", $schemaTable->getPrimaryKey()->getType());
		self::assertTrue($schemaTable->getPrimaryKey()->isAutoIncrement());
		self::assertFalse($schemaTable->getPrimaryKey()->isUlid());
		$schemaFields = $schemaTable->getFieldList();
		self::assertCount(2, $schemaFields);

		self::assertSame("id", $schemaFields[0]->getName());
		self::assertSame("int", $schemaFields[0]->getType());
		self::assertSame("name", $schemaFields[1]->getName());
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
		self::assertSame("searchKey", $schemaFields[2]->getName());
		self::assertSame("TEST_KEY", $schemaFields[2]->getDefaultValue());
	}

	public function testGenerate_primaryKeyAndForeignKey():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(SchemaGeneratorTestCourse::class);
		$schemaFields = $schemaTable->getFieldList();

		self::assertCount(2, $schemaFields);
		self::assertSame("department_SchemaGeneratorTestDepartment_code", $schemaFields[1]->getName());
		self::assertSame("int", $schemaFields[1]->getType());
		self::assertTrue($schemaFields[1]->isForeignKey());
		self::assertSame("SchemaGeneratorTestDepartment", $schemaFields[1]->getForeignKeyReferenceTable());
		self::assertSame("code", $schemaFields[1]->getForeignKeyReferenceField());
	}

	public function testGenerate_stringIdDefaultsToUlid():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(PersonEntity::class);
		$primaryKey = $schemaTable->getPrimaryKey();

		self::assertSame("id", $primaryKey->getName());
		self::assertSame("string", $primaryKey->getType());
		self::assertFalse($primaryKey->isAutoIncrement());
		self::assertTrue($primaryKey->isUlid());
	}

	public function testGenerateAll_createsJunctionTables():void {
		$sut = new SchemaGenerator();
		$schemaTableList = $sut->generateAll([
			SchemaGeneratorTestLesson::class,
		]);

		self::assertCount(3, $schemaTableList);
		self::assertSame("SchemaGeneratorTestLesson", $schemaTableList[0]->getName());
		self::assertCount(2, $schemaTableList[0]->getFieldList());
		self::assertSame("SchemaGeneratorTestStudent", $schemaTableList[1]->getName());
		self::assertSame("SchemaGeneratorTestLesson_students_SchemaGeneratorTestStudent", $schemaTableList[2]->getName());
		self::assertCount(2, $schemaTableList[2]->getFieldList());
		self::assertSame("SchemaGeneratorTestLesson_id", $schemaTableList[2]->getFieldList()[0]->getName());
		self::assertSame("SchemaGeneratorTestStudent_id", $schemaTableList[2]->getFieldList()[1]->getName());
	}
}
