<?php
namespace GT\Orm\Test\Migration;

use DateTimeImmutable;
use DateTimeZone;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Orm\Migration\OrmSchemaStore;
use GT\Orm\Migration\Schema;
use GT\Orm\Migration\SchemaField;
use GT\Orm\Migration\SchemaTable;
use PHPUnit\Framework\TestCase;

class OrmSchemaStoreTest extends TestCase {
	private Database $database;

	protected function setUp():void {
		$this->database = new Database(new Settings(
			__DIR__,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		));
	}

	public function testEnsureTable_isIdempotentAndIndependent():void {
		$sut = new OrmSchemaStore($this->database);
		$sut->ensureTable();
		$sut->ensureTable();

		$tableList = array_map(
			fn($row) => $row->getString("name"),
			$this->database->executeSql(
				"select name from sqlite_master where type = 'table' order by name",
			)->fetchAll(),
		);
		self::assertContains("_orm", $tableList);
		self::assertNotContains("_migration", $tableList);
	}

	public function testLatest_noHistory():void {
		$sut = new OrmSchemaStore($this->database);
		self::assertNull($sut->latest());
	}

	public function testAppendAndRetrieveLatestSnapshot():void {
		$timeList = [
			new DateTimeImmutable("2026-08-30T10:00:00Z"),
			new DateTimeImmutable("2026-08-30T11:00:00Z"),
		];
		$sut = new OrmSchemaStore(
			$this->database,
			clock: function() use (&$timeList) {
				return array_shift($timeList);
			},
		);
		$first = new Schema($this->table("Student", "name"));
		$second = new Schema($this->table("Student", "email"));

		$firstRecord = $sut->append($first);
		$secondRecord = $sut->append($second, $firstRecord->schemaHash);
		$latest = $sut->latest();

		self::assertNotNull($latest);
		self::assertSame($secondRecord->schemaHash, $latest->schemaHash);
		self::assertSame($firstRecord->schemaHash, $latest->previousHash);
		self::assertTrue($second->equals($latest->schema));
		self::assertSame(2, $this->historyCount());
	}

	public function testTimestampIsStoredInUtc():void {
		$sut = new OrmSchemaStore(
			$this->database,
			clock: fn() => new DateTimeImmutable(
				"2026-08-30 12:34:56.123456",
				new DateTimeZone("Europe/London"),
			),
		);

		$record = $sut->append(new Schema());

		self::assertSame("2026-08-30T11:34:56.123456Z", $record->migratedAt);
	}

	public function testDifferentSchemasCanShareATimestamp():void {
		$sut = new OrmSchemaStore(
			$this->database,
			clock: fn() => new DateTimeImmutable("2026-08-30T12:00:00Z"),
		);
		$first = $sut->append(new Schema($this->table("Student", "name")));
		$sut->append(
			new Schema($this->table("Student", "email")),
			$first->schemaHash,
		);

		self::assertSame(2, $this->historyCount());
		self::assertTrue($sut->latest()?->schema->equals(
			new Schema($this->table("Student", "email")),
		));
	}

	public function testLatest_rejectsCorruptSnapshot():void {
		$sut = new OrmSchemaStore($this->database);
		$sut->ensureTable();
		$this->database->executeSql(<<<SQL
			insert into `_orm`
				(`migrationId`, `migratedAt`, `schemaHash`, `previousHash`, `schema`)
			values
				('broken', '2026-08-30T12:00:00.000000Z', 'hash', null, 'not-json')
		SQL);

		$this->expectException(\GT\Orm\Migration\Exception\InvalidSchemaSnapshotException::class);
		$sut->latest();
	}

	private function table(string $tableName, string $fieldName):SchemaTable {
		$field = new SchemaField($fieldName);
		$field->setType("string");
		$table = new SchemaTable($tableName);
		$table->addField($field);
		return $table;
	}

	private function historyCount():int {
		$row = $this->database->executeSql("select count(*) as count from `_orm`")->fetch();
		self::assertNotNull($row);
		return $row->getInt("count") ?? 0;
	}
}
