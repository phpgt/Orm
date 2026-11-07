<?php
namespace GT\Orm\Test\Migration\Query;

use GT\Orm\Migration\Query\SchemaDiffQuerySQLite;
use GT\Orm\Migration\Schema;
use GT\Orm\Migration\SchemaDiffer;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use GT\Orm\Test\SQLTestCase;

class SchemaDiffQuerySQLiteTest extends SQLTestCase {
	public function testGenerateSqlList_createTable():void {
		$sut = new SchemaDiffQuerySQLite();
		$table = $this->studentTable(false);
		$diff = (new SchemaDiffer())->diff(new Schema(), new Schema($table));

		$sqlList = $sut->generateSqlList($diff);

		self::assertCount(1, $sqlList);
		$this->assertSameSQL(<<<SQL
		create table `Student` (
			`id` integer not null primary key,
			`name` text not null
		)
		SQL, $sqlList[0]);
	}

	public function testGenerateSqlList_addNullableField():void {
		$sut = new SchemaDiffQuerySQLite();
		$before = $this->studentTable(true);
		$after = $this->studentTable(true);
		$email = new SchemaField("email");
		$email->setType("string");
		$email->setNullable(true);
		$after->addField($email);
		$diff = (new SchemaDiffer())->diff(new Schema($before), new Schema($after));

		self::assertSame(
			["alter table `Student` add column `email` text null"],
			$sut->generateSqlList($diff),
		);
	}

	public function testGenerateSqlList_autoIncrementRebuild():void {
		$sut = new SchemaDiffQuerySQLite();
		$before = $this->studentTable(false);
		$after = $this->studentTable(true);
		$diff = (new SchemaDiffer())->diff(new Schema($before), new Schema($after));

		$sqlList = $sut->generateSqlList($diff);

		self::assertCount(4, $sqlList);
		$this->assertSameSQL(<<<SQL
		create table `_orm_rebuild_Student` (
			`id` integer not null primary key autoincrement,
			`name` text not null
		)
		SQL, $sqlList[0]);
		self::assertSame(
			"insert into `_orm_rebuild_Student` (`id`, `name`) select `id`, `name` from `Student`",
			$sqlList[1],
		);
		self::assertSame("drop table `Student`", $sqlList[2]);
		self::assertSame(
			"alter table `_orm_rebuild_Student` rename to `Student`",
			$sqlList[3],
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
