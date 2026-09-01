<?php
namespace GT\Orm\Test\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Stream;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Orm\Cli\MigrateCommand;
use GT\Orm\Test\TestProject\Migration\Version1\Student as StudentV1;
use PHPUnit\Framework\TestCase;

class MigrateCommandTest extends TestCase {
	private string $projectRoot;
	private string $databasePath;
	private string $previousDirectory;

	protected function setUp():void {
		$this->projectRoot = sys_get_temp_dir() . "/phpgt-orm-command-" . uniqid();
		mkdir($this->projectRoot, recursive: true);
		$this->databasePath = $this->projectRoot . "/database.sqlite";
		$this->previousDirectory = getcwd() ?: __DIR__;
		chdir($this->projectRoot);
	}

	protected function tearDown():void {
		chdir($this->previousDirectory);
	}

	public function testNoEntityDirectoryIsSuccessfulWithoutOpeningDatabase():void {
		$this->writeConfig($this->projectRoot . "/missing");

		$status = (new MigrateCommand())->run(new ArgumentValueList());

		self::assertSame(0, $status);
		self::assertFileDoesNotExist($this->databasePath);
	}

	public function testMigratesEntitiesAndThenReportsNoChanges():void {
		$this->writeConfig($this->fixtureDirectory("Version1"));
		$first = $this->runCommand();
		$second = $this->runCommand();

		self::assertSame(0, $first["status"]);
		self::assertStringContainsString("create table Student", $first["out"]);
		self::assertStringContainsString("applied 1 SQL statement", $first["out"]);
		self::assertSame(0, $second["status"]);
		self::assertStringContainsString("schema is up to date", $second["out"]);
		self::assertSame(1, $this->historyCount());
	}

	public function testPlanDoesNotCreateAnEntityTableOrHistoryRecord():void {
		$this->writeConfig($this->fixtureDirectory("Version1"));
		$arguments = new ArgumentValueList();
		$arguments->set("orm-plan");

		$result = $this->runCommand($arguments);

		self::assertSame(0, $result["status"]);
		self::assertStringContainsString("plan only", $result["out"]);
		self::assertFalse($this->tableExists("Student"));
		self::assertFalse($this->tableExists("_orm"));
	}

	public function testUnsafeChangeReturnsDistinctFailureAndPreservesHistory():void {
		$this->writeConfig($this->fixtureDirectory("Version2"));
		self::assertSame(0, $this->runCommand()["status"]);
		$this->writeConfig($this->fixtureDirectory("RequiredEmail"));

		$result = $this->runCommand();

		self::assertSame(2, $result["status"]);
		self::assertStringContainsString("requires explicit data handling", $result["error"]);
		self::assertSame(1, $this->historyCount());
	}

	public function testBaselineRecordsExistingEntitySchema():void {
		$this->writeConfig($this->fixtureDirectory("Version1"));
		$database = $this->database();
		$database->executeSql(<<<SQL
			create table `Student` (
				`id` integer primary key,
				`name` text not null,
				`dob` text not null
			)
			SQL);
		$arguments = new ArgumentValueList();
		$arguments->set("orm-baseline");

		$result = $this->runCommand($arguments);

		self::assertSame(0, $result["status"]);
		self::assertStringContainsString("recorded as the baseline", $result["out"]);
		self::assertSame(1, $this->historyCount());
	}

	public function testBaselineAndPlanCannotBeCombined():void {
		$this->writeConfig($this->fixtureDirectory("Version1"));
		$arguments = new ArgumentValueList();
		$arguments->set("orm-baseline");
		$arguments->set("orm-plan");

		$result = $this->runCommand($arguments);

		self::assertSame(1, $result["status"]);
		self::assertStringContainsString("cannot be used together", $result["error"]);
		self::assertFileDoesNotExist($this->databasePath);
	}

	private function fixtureDirectory(string $version):string {
		return dirname(__DIR__) . "/TestProject/Migration/$version";
	}

	private function writeConfig(string $entityDirectory):void {
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
			[orm]
			entity_path="$entityDirectory"

			[database]
			driver=sqlite
			schema="{$this->databasePath}"
			query_path=query
			INI);
	}

	/** @return array{status:int, out:string, error:string} */
	private function runCommand(?ArgumentValueList $arguments = null):array {
		$out = tempnam($this->projectRoot, "out-");
		$error = tempnam($this->projectRoot, "error-");
		$in = tempnam($this->projectRoot, "in-");
		self::assertIsString($out);
		self::assertIsString($error);
		self::assertIsString($in);
		$command = new MigrateCommand();
		$command->setStream(new Stream($in, $out, $error));
		$status = $command->run($arguments ?? new ArgumentValueList());

		return [
			"status" => $status,
			"out" => file_get_contents($out) ?: "",
			"error" => file_get_contents($error) ?: "",
		];
	}

	private function database():Database {
		return new Database(new Settings(
			$this->projectRoot . "/query",
			Settings::DRIVER_SQLITE,
			$this->databasePath,
		));
	}

	private function tableExists(string $table):bool {
		$row = $this->database()->executeSql(
			"select count(*) as count from sqlite_master where type='table' and name=:name",
			["name" => $table],
		)->fetch();
		return ($row?->getInt("count") ?? 0) > 0;
	}

	private function historyCount():int {
		$row = $this->database()->executeSql(
			"select count(*) as count from `_orm`",
		)->fetch();
		return $row?->getInt("count") ?? 0;
	}
}
