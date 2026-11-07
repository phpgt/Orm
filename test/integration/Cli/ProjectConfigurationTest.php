<?php
namespace GT\Orm\Test\Integration\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use GT\Orm\Cli\ProjectConfiguration;
use PHPUnit\Framework\TestCase;

class ProjectConfigurationTest extends TestCase {
	private string $projectRoot;

	protected function setUp():void {
		$this->projectRoot = sys_get_temp_dir() . "/phpgt-orm-config-" . uniqid();
		mkdir($this->projectRoot, recursive: true);
	}

	protected function tearDown():void {
		foreach(glob($this->projectRoot . "/*") as $path) {
			unlink($path);
		}
		rmdir($this->projectRoot);
	}

	public function testConstruct_allowsProjectWithoutConfiguration():void {
		$sut = new ProjectConfiguration($this->projectRoot);

		self::assertTrue($sut->isEnabled());
		self::assertSame(
			$this->projectRoot . "/class",
			$sut->getEntityDirectory(),
		);
	}

	public function testConstruct_readsEntityPathAndDisabledState():void {
		file_put_contents($this->projectRoot . "/config.ini", <<<INI
			[orm]
			migrate=false
			entity_path=model
			INI);

		$sut = new ProjectConfiguration($this->projectRoot);

		self::assertFalse($sut->isEnabled());
		self::assertSame(
			$this->projectRoot . "/model",
			$sut->getEntityDirectory(),
		);
	}

	public function testCreateSettings_commandArgumentsOverrideConfiguration():void {
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

		$sut = new ProjectConfiguration($this->projectRoot);
		$settings = $sut->createSettings($arguments);

		self::assertSame("sqlite", $settings->getDriver());
		self::assertSame(":memory:", $settings->getSchema());
		self::assertSame($this->projectRoot . "/sql", $settings->getBaseDirectory());
	}
}
