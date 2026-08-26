<?php
namespace GT\Orm\Migration\Query;

use GT\Orm\Migration\Query\SchemaQuery;
use GT\Orm\Migration\SchemaTable;

class SchemaQuerySQLite extends SchemaQuery {
	protected function type(string $type):string {
		return match($type) {
			"string" => "text",
			default => $type,
		};
	}
}
