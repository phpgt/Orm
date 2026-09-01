<?php
namespace GT\Orm\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\Parameter;
use Gt\Cli\Stream;
use GT\Orm\Migration\EntityDetector;
use GT\Orm\Migration\MigrationPlan;
use GT\Orm\Migration\OrmMigrator;
use GT\Orm\Migration\SchemaChange;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MigrateCommand extends Command {
	public function __construct(
		private readonly EntityDetector $entityDetector = new EntityDetector(),
	) {}

	public function run(?ArgumentValueList $arguments = null):int {
		try {
			return $this->runMigration($arguments);
		}
		catch(Throwable $exception) {
			$this->output(
				"ORM migration failed: " . $exception->getMessage(),
				streamName: Stream::ERROR,
			);
			return 1;
		}
	}

	private function runMigration(?ArgumentValueList $arguments):int {
		if($arguments?->contains("no-orm")) {
			return 0;
		}
		if($arguments?->contains("orm-baseline")
		&& $arguments->contains("orm-plan")) {
			throw new InvalidArgumentException(
				"--orm-baseline and --orm-plan cannot be used together",
			);
		}
		$projectRoot = getcwd();
		if($projectRoot === false) {
			throw new RuntimeException("Unable to determine the project directory");
		}
		$config = new ProjectConfiguration($projectRoot);
		if(!$config->isEnabled()) {
			return 0;
		}

		$entityList = $this->entityDetector->getEntityClassList(
			$config->getEntityDirectory(),
		);
		if($entityList === []) {
			$this->output("ORM migrations: no Entity classes found.");
			return 0;
		}

		$migrator = new OrmMigrator($config->createDatabase($arguments));
		if($arguments?->contains("orm-baseline")) {
			$migrator->baseline(...$entityList);
			$this->output("ORM migrations: current Entity schema recorded as the baseline.");
			return 0;
		}
		return $this->executePlan($migrator, $arguments, ...$entityList);
	}

	/** @param class-string ...$entityList */
	private function executePlan(
		OrmMigrator $migrator,
		?ArgumentValueList $arguments,
		string ...$entityList,
	):int {
		$plan = $migrator->plan(...$entityList);
		$this->outputPlan($plan);
		if($plan->isEmpty()) {
			return 0;
		}
		if(!$plan->isExecutable()) {
			$this->output(
				"ORM migration was not applied because it requires explicit data handling.",
				streamName: Stream::ERROR,
			);
			return 2;
		}
		if($arguments?->contains("orm-plan")) {
			$this->output("ORM migrations: plan only; no changes were applied.");
			return 0;
		}

		$count = $migrator->apply($plan);
		$this->output("ORM migrations: applied $count SQL statement(s).");
		return 0;
	}

	public function getName():string {
		return "orm-migrate";
	}

	public function getDescription():string {
		return "Migrate the database schema represented by ORM Entity classes";
	}

	public function getRequiredNamedParameterList():array {
		return [];
	}

	public function getOptionalNamedParameterList():array {
		return [];
	}

	public function getRequiredParameterList():array {
		return [];
	}

	public function getOptionalParameterList():array {
		return [
			new Parameter(false, "no-orm", null, "Skip ORM Entity migrations"),
			new Parameter(false, "orm-baseline", null, "Record the current Entity schema without changing tables"),
			new Parameter(false, "orm-plan", null, "Display ORM changes without applying them"),
		];
	}

	private function outputPlan(MigrationPlan $plan):void {
		if($plan->isEmpty()) {
			$this->output("ORM migrations: schema is up to date.");
			return;
		}
		$this->output("ORM migrations:");
		foreach($plan->diff->getChangeList() as $change) {
			$this->output("  - " . $this->describeChange($change));
		}
	}

	private function describeChange(SchemaChange $change):string {
		$description = str_replace("_", " ", strtolower($change->type->name));
		$description .= " " . $change->tableName;
		if($change->beforeField !== null || $change->afterField !== null) {
			$description .= "." . $change->fieldName();
		}
		return $description . " [" . strtolower($change->safety()->name) . "]";
	}
}
