<?php
namespace Gt\Orm\Migration\Query;

use Gt\Orm\Migration\Query\SchemaQuery;
use Gt\Orm\Migration\SchemaTable;

class SchemaQuerySQLite extends SchemaQuery {
	protected function type(string $type):string {
		return match($type) {
			"string" => "text",
			default => $type,
		};
	}
}
