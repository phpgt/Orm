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
}
