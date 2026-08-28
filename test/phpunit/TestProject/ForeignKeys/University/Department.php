<?php
namespace GT\Orm\Test\TestProject\ForeignKeys\University;

use GT\Orm\Entity;

readonly class Department implements Entity {
	public function __construct(
		public string $id,
		public string $name,
		public Teacher $headOfDepartment,
	) {}
}
