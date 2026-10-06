<?php
namespace GT\Orm\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use GT\Config\Config;
use GT\Config\ConfigFactory;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Database\Migration\Migrator as DatabaseMigrator;

readonly class ProjectConfiguration {
	private Config $config;

	/** @SuppressWarnings("PHPMD.StaticAccess") */
	public function __construct(private string $projectRoot) {
		$defaultPath = $this->findDefaultConfig();
		$this->config = $defaultPath !== null || $this->hasProjectConfig()
			? ConfigFactory::createForProject($projectRoot, $defaultPath)
			: new Config();
	}

	public function isEnabled():bool {
		$value = $this->config->get("orm.migrate");
		return $value === null
			|| filter_var($value, FILTER_VALIDATE_BOOL);
	}

	public function getEntityDirectory():string {
		$path = $this->config->get("orm.entity_path")
			?? $this->config->get("app.class_dir")
			?? "class";
		return $this->resolvePath($path);
	}

	public function createSettings(?ArgumentValueList $arguments = null):Settings {
		$queryPath = $this->getStringValue(
			$arguments,
			"base-directory",
			"database.query_path",
			"query",
		);
		return new Settings(
			$this->resolvePath($queryPath),
			$this->getStringValue(
				$arguments,
				"driver",
				"database.driver",
				"mysql",
			),
			$this->getOverrideOrConfigValue(
				$arguments,
				"database",
				"database.schema",
			),
			$this->getStringValue(
				$arguments,
				"host",
				"database.host",
				"localhost",
			),
			(int)$this->getStringValue(
				$arguments,
				"port",
				"database.port",
				"3306",
			),
			$this->getStringValue(
				$arguments,
				"username",
				"database.username",
				"",
			),
			$this->getStringValue(
				$arguments,
				"password",
				"database.password",
				"",
			),
		);
	}

	public function createDatabase(?ArgumentValueList $arguments = null):Database {
		$settings = $this->createSettings($arguments);
		(new DatabaseMigrator($settings, $settings->getBaseDirectory()))
			->selectSchema();
		return new Database($settings);
	}

	private function findDefaultConfig():?string {
		$directory = $this->resolvePath("vendor/phpgt/webengine");
		foreach(["config.default.ini", "default.ini"] as $fileName) {
			$path = "$directory/$fileName";
			if(is_file($path)) {
				return $path;
			}
		}
		return null;
	}

	private function hasProjectConfig():bool {
		foreach(["config.default.ini", "config.ini", "config.dev.ini", "config.deploy.ini", "config.production.ini"] as $fileName) {
			if(is_file($this->resolvePath($fileName))) {
				return true;
			}
		}
		return false;
	}

	private function getOverrideOrConfigValue(
		?ArgumentValueList $arguments,
		string $argumentKey,
		string $configKey,
		?string $default = null,
	):?string {
		if($arguments?->contains($argumentKey)) {
			return $arguments->get($argumentKey)->get() ?? $default;
		}
		return $this->config->get($configKey) ?? $default;
	}

	private function getStringValue(
		?ArgumentValueList $arguments,
		string $argumentKey,
		string $configKey,
		string $default,
	):string {
		return $this->getOverrideOrConfigValue(
			$arguments,
			$argumentKey,
			$configKey,
			$default,
		) ?? $default;
	}

	private function resolvePath(string $path):string {
		if(str_starts_with($path, DIRECTORY_SEPARATOR)) {
			return $path;
		}
		return $this->projectRoot . DIRECTORY_SEPARATOR . $path;
	}
}
