<?php
namespace GT\Orm\Test\Integration\Migration;

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

	protected function setUp():void {
		$this->database = new Database(new Settings(
			__DIR__,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		));
	}

	public function testApply_initialMigrationAndRepeat():void {
		$sut = new OrmMigrator($this->database);
		$plan = $sut->plan(StudentV1::class);
		self::assertFalse($plan->isEmpty());
		self::assertTrue($plan->isExecutable());
		self::assertFalse($this->tableExists("_orm"));
		self::assertSame(1, $sut->apply($plan));
		self::assertSame(1, $this->historyCount());

		$repeat = $sut->plan(StudentV1::class);
		self::assertTrue($repeat->isEmpty());
		self::assertSame(0, $sut->apply($repeat));
		self::assertSame(1, $this->historyCount());
	}

	public function testMigrate_plansAndApplies():void {
		$sut = new OrmMigrator($this->database);
		self::assertSame(1, $sut->migrate(StudentV1::class));
		self::assertSame(0, $sut->migrate(StudentV1::class));
		self::assertSame(1, $this->historyCount());
	}

	public function testBaseline_preservesExistingData():void {
		$sut = new OrmMigrator($this->database);
		$table = (new SchemaGenerator())->generate(StudentV1::class);
		$this->database->executeSql((new SchemaQuerySQLite($table))->generateSql());
		$this->database->executeSql(<<<SQL
			insert into `Student` (`id`, `name`, `dob`)
			values (7, 'Ada', '1815-12-10 00:00:00.000000')
		SQL);

		$sut->baseline(StudentV1::class);
		$sut->migrate(StudentV2::class);

		$row = $this->database->executeSql(
			"select `id`, `name` from `Student`",
		)->fetch();
		self::assertNotNull($row);
		self::assertSame(7, $row->getInt("id"));
		self::assertSame("Ada", $row->getString("name"));
		self::assertSame(2, $this->historyCount());
	}

	public function testBaseline_rejectsExistingHistory():void {
		$sut = new OrmMigrator($this->database);
		$sut->baseline(StudentV1::class);

		$this->expectException(ExistingSchemaHistoryException::class);
		$sut->baseline(StudentV1::class);
	}

	public function testApply_autoIncrementPreservesRows():void {
		$sut = new OrmMigrator($this->database);
		$sut->apply($sut->plan(StudentV1::class));
		$this->database->executeSql(<<<SQL
			insert into `Student` (`id`, `name`, `dob`) values
				(4, 'Ada', '1815-12-10 00:00:00.000000'),
				(9, 'Grace', '1906-12-09 00:00:00.000000')
		SQL);

		$plan = $sut->plan(StudentV2::class);
		self::assertTrue($plan->isExecutable());
		$sut->apply($plan);
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

	public function testApply_nullableFieldPreservesRows():void {
		$sut = new OrmMigrator($this->database);
		$sut->apply($sut->plan(StudentV2::class));
		$this->database->executeSql(
			"insert into `Student` (`name`, `dob`) values ('Ada', '1815-12-10 00:00:00.000000')",
		);

		$sut->apply($sut->plan(StudentV3::class));

		$row = $this->database->executeSql(
			"select `name`, `email` from `Student`",
		)->fetch();
		self::assertNotNull($row);
		self::assertSame("Ada", $row->getString("name"));
		self::assertNull($row->getString("email"));
	}

	public function testApply_rejectsRequiredFieldWithoutDefault():void {
		$sut = new OrmMigrator($this->database);
		$sut->apply($sut->plan(StudentV2::class));
		$plan = $sut->plan(RequiredEmailStudent::class);
		self::assertFalse($plan->isExecutable());

		$this->expectException(UnsafeMigrationException::class);
		$sut->apply($plan);
	}

	public function testApply_requiredFieldBackfillsRows():void {
		$sut = new OrmMigrator($this->database);
		$sut->apply($sut->plan(StudentV2::class));
		$this->database->executeSql(
			"insert into `Student` (`name`, `dob`) values ('Ada', '1815-12-10 00:00:00.000000')",
		);

		$plan = $sut->plan(DefaultEmailStudent::class);
		self::assertTrue($plan->isExecutable());
		$sut->apply($plan);

		$row = $this->database->executeSql(
			"select `email` from `Student`",
		)->fetch();
		self::assertNotNull($row);
		self::assertSame("unknown@example.invalid", $row->getString("email"));
	}

	public function testApply_rejectsStalePlan():void {
		$sut = new OrmMigrator($this->database);
		$first = $sut->plan(StudentV1::class);
		$stale = $sut->plan(StudentV1::class);
		$sut->apply($first);

		$this->expectException(StaleMigrationPlanException::class);
		$sut->apply($stale);
	}

	public function testApply_failedSqlDoesNotRecordSchema():void {
		$sqlGenerator = new class implements SchemaDiffSql {
			public function generateSqlList(SchemaDiff $diff):array {
				return [
					"create table `partial_migration` (`id` integer)",
					"this is not valid SQL",
				];
			}
		};
		$sut = new OrmMigrator(
			$this->database,
			sqlGenerator: $sqlGenerator,
		);
		$plan = $sut->plan(StudentV1::class);

		try {
			$sut->apply($plan);
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
