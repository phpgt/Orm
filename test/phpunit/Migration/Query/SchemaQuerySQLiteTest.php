<?php
namespace GT\Orm\Test\Migration\Query;

use GT\Orm\Migration\Query\SchemaQueryMySQL;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use GT\Orm\Test\SQLTestCase;
use PHPUnit\Framework\TestCase;

class SchemaQuerySQLiteTest extends SQLTestCase {
	public function testGenerateSql():void {
		$field1 = self::createMock(SchemaField::class);
		$field1->method("getName")->willReturn("id");
		$field1->method("getType")->willReturn("int");
		$field1->method("isNullable")->willReturn(false);
		$field2 = self::createMock(SchemaField::class);
		$field2->method("getName")->willReturn("name");
		$field2->method("getType")->willReturn("string");
		$field2->method("isNullable")->willReturn(true);

		$fieldList = [$field1, $field2];

		$schemaTable = self::createMock(SchemaTable::class);
		$schemaTable->method("getName")
			->willReturn("TestTable");
		$schemaTable->method("getPrimaryKey")
			->willReturn($field1);

		$schemaTable->method("getFieldList")
			->willReturn($fieldList);

		$sut = new SchemaQuerySQLite($schemaTable);

		$expected = <<<SQL
		create table `TestTable` (
			`id` integer not null primary key,
			`name` text null
		)
		SQL;

		self::assertSameSql(
			$expected,
			$sut->generateSql(),
		);
	}

	public function testGenerateSql_autoIncrement():void {
		$field1 = self::createMock(SchemaField::class);
		$field1->method("getName")->willReturn("id");
		$field1->method("getType")->willReturn("int");
		$field1->method("isNullable")->willReturn(false);
		$field1->method("isAutoIncrement")->willReturn(true);
		$field2 = self::createMock(SchemaField::class);
		$field2->method("getName")->willReturn("name");
		$field2->method("getType")->willReturn("string");
		$field2->method("isNullable")->willReturn(true);

		$fieldList = [$field1, $field2];

		$schemaTable = self::createMock(SchemaTable::class);
		$schemaTable->method("getName")
			->willReturn("TestTable");
		$schemaTable->method("getPrimaryKey")
			->willReturn($field1);

		$schemaTable->method("getFieldList")
			->willReturn($fieldList);

		$sutSqlite = new SchemaQuerySQLite($schemaTable);
		$sutMySql = new SchemaQueryMySQL($schemaTable);

		$expectedSqlite = <<<SQL
		create table `TestTable` (
			`id` integer not null primary key autoincrement,
			`name` text null
		)
		SQL;

		self::assertSameSql(
			$expectedSqlite,
			$sutSqlite->generateSql(),
		);

		$expectedMySql = <<<SQL
		create table `TestTable` (
			`id` int not null primary key auto_increment,
			`name` text null
		)
		SQL;

		self::assertSameSql(
			$expectedMySql,
			$sutMySql->generateSql(),
		);
	}

	public function testGenerateSql_foreignKey():void {
		$field = new SchemaField("teacher_Teacher_id");
		$field->setType("int");
		$field->setNullable(false);
		$field->setForeignKeyReference("Teacher", "id");

		$schemaTable = self::createMock(SchemaTable::class);
		$schemaTable->method("getName")
			->willReturn("Lesson");
		$schemaTable->method("getPrimaryKey")
			->willReturn(null);
		$schemaTable->method("getFieldList")
			->willReturn([$field]);

		$sut = new SchemaQuerySQLite($schemaTable);

		$expected = <<<SQL
		create table `Lesson` (
			`teacher_Teacher_id` integer not null references `Teacher` (`id`)
		)
		SQL;

		self::assertSameSql($expected, $sut->generateSql());
	}

	public function testGenerateSql_ulidDoesNotAffectColumnSqlYet():void {
		$field = new SchemaField("id");
		$field->setType("string");
		$field->setNullable(false);
		$field->setUlid(true);

		$schemaTable = self::createMock(SchemaTable::class);
		$schemaTable->method("getName")
			->willReturn("Thing");
		$schemaTable->method("getPrimaryKey")
			->willReturn($field);
		$schemaTable->method("getFieldList")
			->willReturn([$field]);

		$sut = new SchemaQuerySQLite($schemaTable);

		$expected = <<<SQL
		create table `Thing` (
			`id` text not null primary key
		)
		SQL;

		self::assertSameSql($expected, $sut->generateSql());
	}
}
