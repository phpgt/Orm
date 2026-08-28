<?php
namespace GT\Orm\Test\Migration;

use DateTime;
use GT\Orm\Migration\Query\SchemaQueryMySQL;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Test\SQLTestCase;
use GT\Orm\Test\TestProject\ForeignKeys\University\Course;
use GT\Orm\Test\TestProject\ForeignKeys\University\Teacher;
use GT\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\PersonEntity;
use GT\Orm\Test\TestProject\Metadata\AutoIncrementEntity;
use GT\Orm\Test\TestProject\Metadata\DefaultValueEntity;
use PDO;

class SchemaGeneratorTest extends SQLTestCase {
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
		self::assertFalse($schemaFields[2]->hasDefaultValue());
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
		$schemaFields = $schemaTable->getFieldList();
		self::assertCount(2, $schemaFields);

		self::assertSame("id", $schemaFields[0]->getName());
		self::assertSame("int", $schemaFields[0]->getType());
		self::assertSame("name", $schemaFields[1]->getName());
		self::assertSame("string", $schemaFields[1]->getType());
	}

	public function testGenerate_phpConstructorDefaultIsNotSqlDefault():void {
		$sut = new SchemaGenerator();
		$schemaTable = $sut->generate(new class(123, "Test Name") {
			public function __construct(
				public int $id,
				public string $name = "UNKNOWN",
			) {}
		});

		$schemaFields = $schemaTable->getFieldList();
		self::assertFalse($schemaFields[1]->hasDefaultValue());
	}

	public function testGenerate_phpPropertyDefaultIsNotSqlDefault():void {
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
		self::assertSame("searchKey", $schemaFields[0]->getName());
		self::assertFalse($schemaFields[0]->hasDefaultValue());
	}

	public function testGenerate_explicitSqlDefaults():void {
		$table = (new SchemaGenerator())->generate(DefaultValueEntity::class);
		$fieldList = $table->getFieldList();

		self::assertFalse($fieldList[0]->hasDefaultValue());
		self::assertSame("O'Reilly", $fieldList[1]->getDefaultValue());
		self::assertTrue($fieldList[2]->hasDefaultValue());
		self::assertNull($fieldList[2]->getDefaultValue());

		$expected = <<<SQL
		create table `DefaultValueEntity` (
			`id` integer not null primary key,
			`name` text not null default 'O''Reilly',
			`description` text null default null
		)
		SQL;

		self::assertSameSQL(
			$expected,
			(new SchemaQuerySQLite($table))->generateSql(),
		);
	}

	public function testGenerate_autoIncrementAndDateTimeAcrossPlatforms():void {
		$table = (new SchemaGenerator())->generate(AutoIncrementEntity::class);
		$fieldList = $table->getFieldList();

		self::assertSame("id", $table->getPrimaryKey()->getName());
		self::assertTrue($table->getPrimaryKey()->isAutoIncrement());
		self::assertSame("pending", $fieldList[2]->getDefaultValue());
		self::assertFalse($fieldList[3]->hasDefaultValue());

		$expectedSqlite = <<<SQL
		create table `AutoIncrementEntity` (
			`id` integer not null primary key autoincrement,
			`name` text not null,
			`status` text not null default 'pending',
			`createdAt` text not null
		)
		SQL;
		self::assertSameSQL(
			$expectedSqlite,
			(new SchemaQuerySQLite($table))->generateSql(),
		);

		$expectedMySql = <<<SQL
		create table `AutoIncrementEntity` (
			`id` int not null primary key auto_increment,
			`name` text not null,
			`status` text not null default 'pending',
			`createdAt` datetime(6) not null
		)
		SQL;
		self::assertSameSQL(
			$expectedMySql,
			(new SchemaQueryMySQL($table))->generateSql(),
		);
	}

	public function testGenerateJunctionTable():void {
		$tableList = (new SchemaGenerator())->generateJunctionTableList(
			Teacher::class,
		);

		self::assertCount(1, $tableList);
		$table = $tableList[0];
		self::assertSame("Teacher_coursesAssigned_Course", $table->getName());
		self::assertSame("id", $table->getPrimaryKey()->getName());
		self::assertTrue($table->getPrimaryKey()->isAutoIncrement());

		$expectedSqlite = <<<SQL
		create table `Teacher_coursesAssigned_Course` (
			`id` integer not null primary key autoincrement,
			`Teacher_id` text not null references `Teacher` (`id`),
			`Course_id` text not null references `Course` (`id`)
		)
		SQL;
		self::assertSameSQL(
			$expectedSqlite,
			(new SchemaQuerySQLite($table))->generateSql(),
		);

		$expectedMySql = <<<SQL
		create table `Teacher_coursesAssigned_Course` (
			`id` int not null primary key auto_increment,
			`Teacher_id` text not null references `Teacher` (`id`),
			`Course_id` text not null references `Course` (`id`)
		)
		SQL;
		self::assertSameSQL(
			$expectedMySql,
			(new SchemaQueryMySQL($table))->generateSql(),
		);
	}

	public function testGenerateAllPlacesJunctionsAfterEntityTables():void {
		$tableList = (new SchemaGenerator())->generateAll(
			Teacher::class,
			Course::class,
		);

		self::assertSame(
			["Teacher", "Course", "Teacher_coursesAssigned_Course"],
			array_map(
				fn($table) => $table->getName(),
				$tableList,
			),
		);
	}

	public function testJunctionTableAllowsRepeatedEntityPairs():void {
		$table = (new SchemaGenerator())->generateJunctionTableList(
			Teacher::class,
		)[0];
		$pdo = new PDO("sqlite::memory:");
		$pdo->exec((new SchemaQuerySQLite($table))->generateSql());
		$pdo->exec(<<<SQL
			insert into `Teacher_coursesAssigned_Course`
				(`Teacher_id`, `Course_id`)
			values
				('TEACHER_JOHN', 'COURSE_FIRST'),
				('TEACHER_JOHN', 'COURSE_FIRST')
		SQL);

		$idList = $pdo->query(<<<SQL
			select `id`
			from `Teacher_coursesAssigned_Course`
			order by `id`
		SQL)->fetchAll(PDO::FETCH_COLUMN);

		self::assertSame([1, 2], $idList);
	}
}
