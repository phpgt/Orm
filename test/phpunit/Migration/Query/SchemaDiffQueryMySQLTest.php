<?php
namespace GT\Orm\Test\Migration\Query;

use GT\Orm\Migration\Query\SchemaDiffQueryMySQL;
use GT\Orm\Migration\Schema;
use GT\Orm\Migration\SchemaDiffer;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use GT\Orm\Test\SQLTestCase;

class SchemaDiffQueryMySQLTest extends SQLTestCase {
	public function testGenerateSqlList_autoIncrementAlter():void {
		$sut = new SchemaDiffQueryMySQL();
		$before = $this->studentTable(false);
		$after = $this->studentTable(true);
		$diff = (new SchemaDiffer())->diff(new Schema($before), new Schema($after));

		self::assertSame(
			["alter table `Student` modify column `id` int not null auto_increment"],
			$sut->generateSqlList($diff),
		);
	}

	private function studentTable(bool $autoIncrement):SchemaTable {
		$table = new SchemaTable("Student");
		$id = new SchemaField("id");
		$id->setType("int");
		$id->setAutoIncrement($autoIncrement);
		$table->addField($id);
		$table->setPrimaryKey($id);
		$name = new SchemaField("name");
		$name->setType("string");
		$table->addField($name);
		return $table;
	}
}
