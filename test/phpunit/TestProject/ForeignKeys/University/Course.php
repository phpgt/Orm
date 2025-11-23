<?php
namespace Gt\Orm\Test\TestProject\ForeignKeys\University;

use Gt\Orm\Entity;

readonly class Course extends Entity {
	public function __construct(
		public string $id,
		public string $title,
		public Department $department,
		public ?Course $parent = null,
	) {}
}
