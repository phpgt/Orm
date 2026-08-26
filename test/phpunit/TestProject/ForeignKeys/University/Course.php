<?php
namespace GT\Orm\Test\TestProject\ForeignKeys\University;

use GT\Orm\Entity;

readonly class Course implements Entity {
	public function __construct(
		public string $id,
		public string $title,
		public Department $department,
		public ?Course $parent = null,
	) {}
}
