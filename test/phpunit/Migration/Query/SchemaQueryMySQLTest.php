<?php
namespace GT\Orm\Test\Migration\Query;

use GT\Orm\Migration\Query\SchemaQueryMySQL;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use GT\Orm\Test\SQLTestCase;

class SchemaQueryMySQLTest extends SQLTestCase {
	public function testGenerateSql_autoIncrement():void {
		$field1 = self::createStub(SchemaField::class);
		$field1->method("getName")->willReturn("id");
		$field1->method("requireType")->willReturn("int");
		$field1->method("isNullable")->willReturn(false);
		$field1->method("isAutoIncrement")->willReturn(true);
		$field2 = self::createStub(SchemaField::class);
		$field2->method("getName")->willReturn("name");
		$field2->method("requireType")->willReturn("string");
		$field2->method("isNullable")->willReturn(true);

		$fieldList = [$field1, $field2];

		$schemaTable = self::createStub(SchemaTable::class);
		$schemaTable->method("getName")
			->willReturn("TestTable");
		$schemaTable->method("getPrimaryKey")
			->willReturn($field1);

		$schemaTable->method("getFieldList")
			->willReturn($fieldList);

		$sut = new SchemaQueryMySQL($schemaTable);

		$expectedMySql = <<<SQL
		create table `TestTable` (
			`id` int not null primary key auto_increment,
			`name` text null
		)
		SQL;

		self::assertSameSQL(
			$expectedMySql,
			$sut->generateSql(),
		);
	}
}
