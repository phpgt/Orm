<?php
namespace GT\Orm\Migration\Query;

use GT\Database\Database;
use GT\Orm\Exception\InvalidEntityStateException;
use PDO;

class SchemaDiffSqlFactory {
	public function create(Database $database):SchemaDiffSql {
		$driverName = $database->getDriver()->getConnection()
			->getAttribute(PDO::ATTR_DRIVER_NAME);
		return match($driverName) {
			"sqlite" => new SchemaDiffQuerySQLite(),
			"mysql" => new SchemaDiffQueryMySQL(),
			default => throw new InvalidEntityStateException(
				"ORM migrations do not support database driver $driverName",
			),
		};
	}
}
