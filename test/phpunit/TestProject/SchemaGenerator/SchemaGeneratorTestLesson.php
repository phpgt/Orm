<?php
namespace GT\Orm\Test\TestProject\SchemaGenerator;

readonly class SchemaGeneratorTestLesson {
	public function __construct(
		public int $id,
		public string $name,
		/** @var list<SchemaGeneratorTestStudent> */
		public array $students,
	) {}
}
