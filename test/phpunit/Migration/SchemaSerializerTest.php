<?php
namespace GT\Orm\Test\Migration;

use GT\Orm\Migration\Exception\InvalidSchemaSnapshotException;
use GT\Orm\Migration\Schema;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaSerializer;
use GT\Orm\Migration\SchemaTable;
use PHPUnit\Framework\TestCase;

class SchemaSerializerTest extends TestCase {
	public function testSerialize_roundTrip():void {
		$schema = new Schema($this->studentTable());
		$sut = new SchemaSerializer();

		$copy = $sut->deserialize($sut->serialize($schema));

		self::assertTrue($schema->equals($copy));
		self::assertSame($sut->hash($schema), $sut->hash($copy));
	}

	public function testSerialize_canonicalOrder():void {
		$student = $this->studentTable();
		$lesson = $this->table("Lesson", "title");
		$sut = new SchemaSerializer();

		self::assertSame(
			$sut->serialize(new Schema($student, $lesson)),
			$sut->serialize(new Schema($lesson, $student)),
		);
	}

	public function testHash_distinguishesMissingAndNullDefaults():void {
		$withoutDefault = $this->table("Student", "email");
		$withDefault = $this->table("Student", "email");
		$withDefault->getField("email")?->setDefaultValue(null);
		$sut = new SchemaSerializer();

		self::assertNotSame(
			$sut->hash(new Schema($withoutDefault)),
			$sut->hash(new Schema($withDefault)),
		);
	}

	public function testDeserialize_invalidJson():void {
		$sut = new SchemaSerializer();
		$this->expectException(InvalidSchemaSnapshotException::class);
		$sut->deserialize("not json");
	}

	public function testDeserialize_unknownFormat():void {
		$sut = new SchemaSerializer();
		$this->expectException(InvalidSchemaSnapshotException::class);
		$this->expectExceptionMessage("Unsupported schema snapshot format 2");
		$sut->deserialize('{"formatVersion":2,"tables":[]}');
	}

	public function testSerialize_preservesFieldCharacteristics():void {
		$sut = new SchemaSerializer();
		$table = new SchemaTable("Course");
		$field = new SchemaField("teacherId");
		$field->setType("string");
		$field->setNullable(true);
		$field->setDefaultValue(null);
		$field->setUnique(true);
		$field->setForeignKeyReference("Teacher", "id");
		$table->addField($field);

		$copy = $sut->deserialize(
			$sut->serialize(new Schema($table)),
		)->getTable("Course")?->getField("teacherId");

		self::assertNotNull($copy);
		self::assertSame("string", $copy->getType());
		self::assertTrue($copy->isNullable());
		self::assertTrue($copy->hasDefaultValue());
		self::assertNull($copy->getDefaultValue());
		self::assertTrue($copy->isUnique());
		self::assertSame("Teacher", $copy->getForeignKeyReferenceTable());
		self::assertSame("id", $copy->getForeignKeyReferenceField());
	}

	private function studentTable():SchemaTable {
		$table = $this->table("Student", "name");
		$id = new SchemaField("id");
		$id->setType("int");
		$id->setAutoIncrement(true);
		$table->addField($id);
		$table->setPrimaryKey($id);
		return $table;
	}

	private function table(string $name, string $fieldName):SchemaTable {
		$field = new SchemaField($fieldName);
		$field->setType("string");
		$table = new SchemaTable($name);
		$table->addField($field);
		return $table;
	}
}
