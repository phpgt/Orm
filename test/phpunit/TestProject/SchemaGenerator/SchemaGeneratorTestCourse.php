<?php
namespace GT\Orm\Test\TestProject\SchemaGenerator;

readonly class SchemaGeneratorTestCourse {
	public function __construct(
		public int $id,
		public SchemaGeneratorTestDepartment $department,
	) {}
}
