<?php
namespace GT\Orm\Migration\Query;

use DateTimeInterface;

class SchemaQueryMySQL extends SchemaQuery {
	protected string $columnDefPartAutoIncrement = "auto_increment";

	protected function type(string $type):string {
		if(is_a($type, DateTimeInterface::class, true)) {
			return "datetime(6)";
		}

		return match($type) {
			"string" => "text",
			"float" => "double",
			"bool" => "boolean",
			default => $type,
		};
	}
}
