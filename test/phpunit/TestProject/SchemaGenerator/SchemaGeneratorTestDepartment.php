<?php
namespace GT\Orm\Test\TestProject\SchemaGenerator;

use GT\Orm\Attribute\PrimaryKey;

#[PrimaryKey("code", PrimaryKey::AUTOINCREMENT)]
readonly class SchemaGeneratorTestDepartment {
	public function __construct(
		public int $code,
		public string $name,
	) {}
}
