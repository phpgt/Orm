<?php
namespace GT\Orm\Migration\Query;

use DateTimeInterface;

class SchemaQuerySQLite extends SchemaQuery {
	protected function type(string $type):string {
		if(is_a($type, DateTimeInterface::class, true)) {
			return "text";
		}

		return match($type) {
			"string" => "text",
			"int", "bool" => "integer",
			"float" => "real",
			default => $type,
		};
	}
}
