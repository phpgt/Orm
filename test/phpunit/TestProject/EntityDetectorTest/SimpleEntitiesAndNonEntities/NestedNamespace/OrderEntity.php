<?php
namespace Gt\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\NestedNamespace;

use DateTime;
use Gt\Orm\Entity;
use Gt\Orm\Test\TestProject\EntityDetectorTest\SimpleEntitiesAndNonEntities\PersonEntity;

readonly class OrderEntity extends Entity {
	public function __construct(
		public string $id,
		public PersonEntity $person,
		public DateTime $orderedAt,
		public int $value,
	) {}
}
