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
	public function testIdenticalSchemasHaveNoChanges():void {
		$schema = new Schema($this->studentTable());
		self::assertTrue((new SchemaDiffer())->diff($schema, $schema)->isEmpty());
	}

	public function testCreateAndDropTable():void {
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

	public function testAddNullableFieldIsSafe():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$email = new SchemaField("email");
		$email->setType("string");
		$email->setNullable(true);
		$after->addField($email);

		$change = (new SchemaDiffer())
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeType::ADD_FIELD, $change->type);
		self::assertSame("email", $change->fieldName());
		self::assertSame(SchemaChangeSafety::SAFE, $change->safety());
	}

	public function testAddRequiredFieldWithoutDefaultRequiresData():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$email = new SchemaField("email");
		$email->setType("string");
		$after->addField($email);

		$change = (new SchemaDiffer())
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeSafety::REQUIRES_DATA, $change->safety());
	}

	public function testAutoIncrementChangeIsAnAlteration():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->getField("id")?->setAutoIncrement(true);

		$change = (new SchemaDiffer())
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeType::ALTER_FIELD, $change->type);
		self::assertSame("id", $change->fieldName());
		self::assertSame(SchemaChangeSafety::SAFE, $change->safety());
	}

	public function testRemoveFieldIsDestructive():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$before->addField($this->field("email", true));

		$change = (new SchemaDiffer())
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame(SchemaChangeType::DROP_FIELD, $change->type);
		self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $change->safety());
	}

	public function testPrimaryKeyChangeIsDestructive():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->setPrimaryKey($after->getField("name"));

		$changeList = (new SchemaDiffer())
			->diff(new Schema($before), new Schema($after))
			->getChangeList();

		self::assertCount(2, $changeList);
		foreach($changeList as $change) {
			self::assertSame(SchemaChangeType::ALTER_FIELD, $change->type);
			self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $change->safety());
		}
	}

	public function testDefaultChangeIsDetected():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->getField("name")?->setDefaultValue("");

		$change = (new SchemaDiffer())
			->diff(new Schema($before), new Schema($after))
			->getChangeList()[0];

		self::assertSame("name", $change->fieldName());
		self::assertSame(SchemaChangeSafety::DESTRUCTIVE, $change->safety());
	}

	public function testChangeOrderIsDeterministic():void {
		$before = $this->studentTable();
		$after = $this->studentTable();
		$after->addField($this->field("zebra", true));
		$after->addField($this->field("email", true));

		$changeList = (new SchemaDiffer())
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
