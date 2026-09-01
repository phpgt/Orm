<?php
namespace GT\Orm\Migration;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GT\Database\Database;
use GT\Orm\Migration\Exception\ExistingSchemaHistoryException;
use GT\Orm\Migration\Exception\InvalidSchemaSnapshotException;
use GT\Orm\Migration\Exception\StaleMigrationPlanException;
use PDO;

class OrmSchemaStore {
	/** @var Closure():DateTimeInterface */
	private Closure $clock;

	/** @param null|Closure():DateTimeInterface $clock */
	public function __construct(
		private readonly Database $database,
		private readonly SchemaSerializer $serializer = new SchemaSerializer(),
		?Closure $clock = null,
	) {
		$this->clock = $clock === null
			? fn() => new DateTimeImmutable("now", new DateTimeZone("UTC"))
			: $clock;
	}

	public function ensureTable():void {
		$this->database->executeSql(<<<SQL
			create table if not exists `_orm` (
				`migrationId` varchar(100) not null primary key,
				`migratedAt` varchar(32) not null,
				`schemaHash` varchar(64) not null,
				`previousHash` varchar(64) null,
				`schema` text not null
			)
		SQL);
	}

	public function latest():?OrmSchemaRecord {
		if(!$this->hasTable()) {
			return null;
		}
		$row = $this->database->executeSql(<<<SQL
			select `migratedAt`, `schemaHash`, `previousHash`, `schema`
			from `_orm`
			order by `migratedAt` desc, `migrationId` desc
			limit 1
		SQL)->fetch();
		if($row === null) {
			return null;
		}

		$migratedAt = $row->getString("migratedAt");
		$schemaHash = $row->getString("schemaHash");
		$snapshot = $row->getString("schema");
		if($migratedAt === null || $schemaHash === null || $snapshot === null) {
			throw new InvalidSchemaSnapshotException("Stored ORM schema record is incomplete");
		}

		return new OrmSchemaRecord(
			$migratedAt,
			$schemaHash,
			$row->getString("previousHash"),
			$this->serializer->deserialize($snapshot),
		);
	}

	private function hasTable():bool {
		$driverName = $this->database->getDriver()->getConnection()
			->getAttribute(PDO::ATTR_DRIVER_NAME);
		$result = match($driverName) {
			"sqlite" => $this->database->executeSql(<<<SQL
				select count(*) as `count`
				from sqlite_master
				where type = 'table' and name = '_orm'
				SQL),
			"mysql" => $this->database->executeSql("show tables like '_orm'"),
			default => throw new InvalidSchemaSnapshotException(
				"ORM schema history does not support database driver $driverName",
			),
		};
		$row = $result->fetch();
		if($driverName === "mysql") {
			return $row !== null;
		}
		return ($row?->getInt("count") ?? 0) > 0;
	}

	public function append(
		Schema $schema,
		?string $previousHash = null,
	):OrmSchemaRecord {
		$this->ensureTable();
		$latest = $this->latest();
		if($latest?->schemaHash !== $previousHash) {
			throw new StaleMigrationPlanException(
				"ORM schema history has changed before it could be recorded",
			);
		}
		$migratedAt = DateTimeImmutable::createFromInterface(($this->clock)())
			->setTimezone(new DateTimeZone("UTC"))
			->format("Y-m-d\\TH:i:s.u\\Z");
		if($latest !== null && $migratedAt <= $latest->migratedAt) {
			$migratedAt = (new DateTimeImmutable($latest->migratedAt))
				->modify("+1 microsecond")
				->format("Y-m-d\\TH:i:s.u\\Z");
		}
		$schemaHash = $this->serializer->hash($schema);
		$snapshot = $this->serializer->serialize($schema);
		$migrationId = "$migratedAt-$schemaHash";
		$this->database->executeSql(
			<<<SQL
			insert into `_orm` (`migrationId`, `migratedAt`, `schemaHash`, `previousHash`, `schema`)
			values (:migrationId, :migratedAt, :schemaHash, :previousHash, :schema)
			SQL,
			compact("migrationId", "migratedAt", "schemaHash", "previousHash") + [
				"schema" => $snapshot,
			],
		);

		return new OrmSchemaRecord(
			$migratedAt,
			$schemaHash,
			$previousHash,
			$schema,
		);
	}

	public function baseline(Schema $schema):OrmSchemaRecord {
		if($this->latest() !== null) {
			throw new ExistingSchemaHistoryException(
				"ORM schema history has already been established",
			);
		}
		return $this->append($schema);
	}

	public function requireCurrent(?string $schemaHash):void {
		if($this->latest()?->schemaHash !== $schemaHash) {
			throw new StaleMigrationPlanException(
				"ORM migration plan is based on an outdated schema",
			);
		}
	}
}
