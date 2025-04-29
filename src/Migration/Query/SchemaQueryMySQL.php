<?php
namespace Gt\Orm\Migration\Query;

use Gt\Orm\Migration\Query\SchemaQuery;

class SchemaQueryMySQL extends SchemaQuery {
	protected string $columnDefPartAutoIncrement = "auto_increment";

	protected function type(string $type):string {
		return match($type) {
			"string" => "text",
			default => $type,
		};
	}
}
