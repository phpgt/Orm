<?php
namespace GT\Orm\Test\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use GT\Orm\Cli\ProjectConfiguration;
use PHPUnit\Framework\TestCase;

class ProjectConfigurationTest extends TestCase {
	private string $projectRoot;

	protected function setUp():void {
		$this->projectRoot = sys_get_temp_dir() . "/phpgt-orm-config-" . uniqid();
		mkdir($this->projectRoot, recursive: true);
	}

	public function testDefaultsAllowAProjectWithoutConfiguration():void {
		$config = new ProjectConfiguration($this->projectRoot);

		self::assertTrue($config->isEnabled());
		self::assertSame(
			$this->projectRoot . "/class",
			$config->getEntityDirectory(),
		);
	}

	public function testEntityPathAndDisabledStateComeFromConfiguration():void {
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
			[orm]
			migrate=false
			entity_path=model
			INI);

		$config = new ProjectConfiguration($this->projectRoot);

		self::assertFalse($config->isEnabled());
		self::assertSame(
			$this->projectRoot . "/model",
			$config->getEntityDirectory(),
		);
	}

	public function testCommandArgumentsOverrideDatabaseConfiguration():void {
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
			[database]
			driver=mysql
			schema=from-config
			query_path=query
			INI);
		$arguments = new ArgumentValueList();
		$arguments->set("driver", "sqlite");
		$arguments->set("database", ":memory:");
		$arguments->set("base-directory", "sql");

		$settings = (new ProjectConfiguration($this->projectRoot))
			->createSettings($arguments);

		self::assertSame("sqlite", $settings->getDriver());
		self::assertSame(":memory:", $settings->getSchema());
		self::assertSame($this->projectRoot . "/sql", $settings->getBaseDirectory());
	}
}
