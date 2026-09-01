<?php
namespace GT\Orm\Test\Migration;

use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Database\StatementExecutionException;
use GT\Database\StatementPreparationException;
use GT\Orm\Migration\Exception\ExistingSchemaHistoryException;
use GT\Orm\Migration\Exception\StaleMigrationPlanException;
use GT\Orm\Migration\Exception\UnsafeMigrationException;
use GT\Orm\Migration\OrmMigrator;
use GT\Orm\Migration\Query\SchemaDiffSql;
use GT\Orm\Migration\Query\SchemaQuerySQLite;
use GT\Orm\Migration\SchemaDiff;
use GT\Orm\Migration\SchemaGenerator;
use GT\Orm\Test\TestProject\Migration\DefaultEmail\Student as DefaultEmailStudent;
use GT\Orm\Test\TestProject\Migration\RequiredEmail\Student as RequiredEmailStudent;
use GT\Orm\Test\TestProject\Migration\Version1\Student as StudentV1;
use GT\Orm\Test\TestProject\Migration\Version2\Student as StudentV2;
use GT\Orm\Test\TestProject\Migration\Version3\Student as StudentV3;
use PHPUnit\Framework\TestCase;

class OrmMigratorTest extends TestCase {
	private Database $database;
	private OrmMigrator $migrator;

	protected function setUp():void {
		$this->database = new Database(new Settings(
			__DIR__,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		));
		$this->migrator = new OrmMigrator($this->database);
	}

	public function testInitialMigrationAndRepeatIsNoOp():void {
		$plan = $this->migrator->plan(StudentV1::class);
		self::assertFalse($plan->isEmpty());
		self::assertTrue($plan->isExecutable());
		self::assertFalse($this->tableExists("_orm"));
		self::assertSame(1, $this->migrator->apply($plan));
		self::assertSame(1, $this->historyCount());

		$repeat = $this->migrator->plan(StudentV1::class);
		self::assertTrue($repeat->isEmpty());
		self::assertSame(0, $this->migrator->apply($repeat));
		self::assertSame(1, $this->historyCount());
	}

	public function testMigratePlansAndAppliesInOneCall():void {
		self::assertSame(1, $this->migrator->migrate(StudentV1::class));
		self::assertSame(0, $this->migrator->migrate(StudentV1::class));
		self::assertSame(1, $this->historyCount());
	}

	public function testBaselineAdoptsExistingSchemaWithoutRecreatingIt():void {
		$table = (new SchemaGenerator())->generate(StudentV1::class);
		$this->database->executeSql((new SchemaQuerySQLite($table))->generateSql());
		$this->database->executeSql(<<<SQL
			insert into `Student` (`id`, `name`, `dob`)
			values (7, 'Ada', '1815-12-10 00:00:00.000000')
		SQL);

		$this->migrator->baseline(StudentV1::class);
		$this->migrator->migrate(StudentV2::class);

		$row = $this->database->executeSql(
			"select `id`, `name` from `Student`",
		)->fetch();
		self::assertNotNull($row);
		self::assertSame(7, $row->getInt("id"));
		self::assertSame("Ada", $row->getString("name"));
		self::assertSame(2, $this->historyCount());
	}

	public function testBaselineCannotReplaceExistingHistory():void {
		$this->migrator->baseline(StudentV1::class);

		$this->expectException(ExistingSchemaHistoryException::class);
		$this->migrator->baseline(StudentV1::class);
	}

	public function testAutoIncrementMigrationPreservesRows():void {
		$this->migrator->apply($this->migrator->plan(StudentV1::class));
		$this->database->executeSql(<<<SQL
			insert into `Student` (`id`, `name`, `dob`) values
				(4, 'Ada', '1815-12-10 00:00:00.000000'),
				(9, 'Grace', '1906-12-09 00:00:00.000000')
		SQL);

		$plan = $this->migrator->plan(StudentV2::class);
		self::assertTrue($plan->isExecutable());
		$this->migrator->apply($plan);
		$this->database->executeSql(
			"insert into `Student` (`name`, `dob`) values ('Alan', '1912-06-23 00:00:00.000000')",
		);

		$rowList = $this->database->executeSql(
			"select `id`, `name` from `Student` order by `id`",
		)->asArray();
		self::assertSame([
			["id" => 4, "name" => "Ada"],
			["id" => 9, "name" => "Grace"],
			["id" => 10, "name" => "Alan"],
		], $rowList);
	}

	public function testAddNullableFieldPreservesRows():void {
		$this->migrator->apply($this->migrator->plan(StudentV2::class));
		$this->database->executeSql(
			"insert into `Student` (`name`, `dob`) values ('Ada', '1815-12-10 00:00:00.000000')",
		);

		$this->migrator->apply($this->migrator->plan(StudentV3::class));

		$row = $this->database->executeSql(
			"select `name`, `email` from `Student`",
		)->fetch();
		self::assertNotNull($row);
		self::assertSame("Ada", $row->getString("name"));
		self::assertNull($row->getString("email"));
	}

	public function testRequiredFieldWithoutDefaultIsNotExecutable():void {
		$this->migrator->apply($this->migrator->plan(StudentV2::class));
		$plan = $this->migrator->plan(RequiredEmailStudent::class);
		self::assertFalse($plan->isExecutable());

		$this->expectException(UnsafeMigrationException::class);
		$this->migrator->apply($plan);
	}

	public function testRequiredFieldWithDefaultBackfillsExistingRows():void {
		$this->migrator->apply($this->migrator->plan(StudentV2::class));
		$this->database->executeSql(
			"insert into `Student` (`name`, `dob`) values ('Ada', '1815-12-10 00:00:00.000000')",
		);

		$plan = $this->migrator->plan(DefaultEmailStudent::class);
		self::assertTrue($plan->isExecutable());
		$this->migrator->apply($plan);

		$row = $this->database->executeSql(
			"select `email` from `Student`",
		)->fetch();
		self::assertNotNull($row);
		self::assertSame("unknown@example.invalid", $row->getString("email"));
	}

	public function testStalePlanIsRejectedBeforeExecutingSql():void {
		$first = $this->migrator->plan(StudentV1::class);
		$stale = $this->migrator->plan(StudentV1::class);
		$this->migrator->apply($first);

		$this->expectException(StaleMigrationPlanException::class);
		$this->migrator->apply($stale);
	}

	public function testFailedSqlDoesNotRecordSchema():void {
		$sqlGenerator = new class implements SchemaDiffSql {
			public function generateSqlList(SchemaDiff $diff):array {
				return [
					"create table `partial_migration` (`id` integer)",
					"this is not valid SQL",
				];
			}
		};
		$migrator = new OrmMigrator(
			$this->database,
			sqlGenerator: $sqlGenerator,
		);
		$plan = $migrator->plan(StudentV1::class);

		try {
			$migrator->apply($plan);
			self::fail("Invalid migration SQL should fail");
		}
		catch(StatementExecutionException|StatementPreparationException) {
			self::assertFalse($this->tableExists("_orm"));
			$row = $this->database->executeSql(<<<SQL
				select count(*) as count
				from sqlite_master
				where type = 'table' and name = 'partial_migration'
			SQL)->fetch();
			self::assertNotNull($row);
			self::assertSame(0, $row->getInt("count"));
		}
	}

	private function historyCount():int {
		$row = $this->database->executeSql("select count(*) as count from `_orm`")->fetch();
		self::assertNotNull($row);
		return $row->getInt("count") ?? 0;
	}

	private function tableExists(string $table):bool {
		$row = $this->database->executeSql(
			"select count(*) as count from sqlite_master where type='table' and name=:name",
			["name" => $table],
		)->fetch();
		return ($row?->getInt("count") ?? 0) > 0;
	}
}
