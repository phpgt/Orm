<?php
namespace GT\Orm\Test\TestProject\SchemaGenerator;

readonly class SchemaGeneratorTestStudent {
	public function __construct(
		public int $id,
		public string $name,
	) {}
}
