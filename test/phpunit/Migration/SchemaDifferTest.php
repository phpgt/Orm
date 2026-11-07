<?php
namespace GT\Orm\Test\Migration;

use GT\Orm\Migration\Schema;
use GT\Orm\Migration\SchemaChangeSafety;
use GT\Orm\Migration\SchemaChangeType;
use GT\Orm\Migration\SchemaDiffer;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use PHPUnit\Framework\TestCase;

class SchemaDifferTest extends TestCase {
	public function testDiff_IdenticalSchemasHaveNoChanges():void {
		$sut = new SchemaDiffer();
		$schema = new Schema($this->studentTable());
		self::assertTrue($sut->diff($schema, $schema)->isEmpty());
	}

	public function testDiff_CreateAndDropTable():void {
		$table = $this->studentTable();
		$sut = new SchemaDiffer();

		$create = $sut->diff(new Schema(), new Schema($table))->getChangeList();
		self::assertCount(1, $create);
		self::assertSame(SchemaChangeType::CREATE_TABLE, $create[0]->type);
		self::assertSame(SchemaChangeSafety::SAFE, $create[0]->safety());

		$drop = $sut->diff(new Schema($table), new Schema())->getChangeList();
		self::assertCount(1, $drop);
		self::assertSame(SchemaChangeType::DROP_TABLE, $drop[0]->type);
		self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $drop[0]->safety());
	}

	public function testDiff_AddNullableFieldIsSafe():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$email = new SchemaField("email");
		$email->setType("string");
		$email->setNullable(true);
		$after->addField($email);

		$change = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeType::ADD_FIELD, $change->type);
		self::assertSame("email", $change->fieldName());
		self::assertSame(SchemaChangeSafety::SAFE, $change->safety());
	}

	public function testDiff_AddRequiredFieldWithoutDefaultRequiresData():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$email = new SchemaField("email");
		$email->setType("string");
		$after->addField($email);

		$change = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeSafety::REQUIRES_DATA, $change->safety());
	}

	public function testDiff_AutoIncrementChangeIsAnAlteration():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->getField("id")?->setAutoIncrement(true);

		$change = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeType::ALTER_FIELD, $change->type);
		self::assertSame("id", $change->fieldName());
		self::assertSame(SchemaChangeSafety::SAFE, $change->safety());
	}

	public function testDiff_RemoveFieldIsDestructive():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$before->addField($this->field("email", true));

		$change = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeType::DROP_FIELD, $change->type);
		self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $change->safety());
	}

	public function testDiff_PrimaryKeyChangeIsDestructive():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->setPrimaryKey($after->getField("name"));

		$changeList = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList();

		self::assertCount(2, $changeList);
		foreach($changeList as $change) {
			self::assertSame(SchemaChangeType::ALTER_FIELD, $change->type);
			self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $change->safety());
		}
	}

	public function testDiff_DefaultChangeIsDetected():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->getField("name")?->setDefaultValue("");

		$change = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame("name", $change->fieldName());
		self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $change->safety());
	}

	public function testDiff_ChangeOrderIsDeterministic():void {
		$sut = new SchemaDiffer();
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->addField($this->field("zebra", true));
		$after->addField($this->field("email", true));

		$changeList = $sut
			->diff(new Schema($before), new Schema($after))
			->getChangeList();

		self::assertSame(
			["email", "zebra"],
			array_map(fn($change) => $change->fieldName(), $changeList),
		);
	}

	private function studentTable():SchemaTable {
		$table = new SchemaTable("Student");
		$id = $this->field("id");
		$id->setType("int");
		$table->addField($id);
		$table->setPrimaryKey($id);
		$table->addField($this->field("name"));
		return $table;
	}

	private function field(string $name, bool $nullable = false):SchemaField {
		$field = new SchemaField($name);
		$field->setType("string");
		$field->setNullable($nullable);
		return $field;
	}
}
