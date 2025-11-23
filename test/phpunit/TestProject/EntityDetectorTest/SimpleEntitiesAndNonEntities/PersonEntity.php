<?php
namespace Gt\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities;

use DateTime;
use Gt\Orm\Entity;

readonly class PersonEntity implements Entity {
	public function __construct(
		public string $id,
		public string $name,
		public DateTime $createdAt = new DateTime(),
	) {}
}
