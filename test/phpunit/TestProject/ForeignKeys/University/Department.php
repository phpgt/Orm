<?php
namespace Gt\Orm\Test\TestProject\ForeignKeys\University;

readonly class Department {
	public function __construct(
		public string $id,
		public string $name,
		public Teacher $headOfDepartment,
	) {}
}
