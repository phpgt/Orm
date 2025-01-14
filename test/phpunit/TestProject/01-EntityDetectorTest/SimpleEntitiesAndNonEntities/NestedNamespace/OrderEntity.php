<?php
namespace Gt\Orm\Test\TestProjectRoot\SimpleEntitiesAndNonEntities\NestedNamespace;

use DateTime;
use Gt\Orm\Entity;
use Gt\Orm\Test\TestProjectRoot\SimpleEntitiesAndNonEntities\PersonEntity;

readonly class OrderEntity extends Entity {
	public function __construct(
		public string $id,
		public PersonEntity $person,
		public DateTime $orderedAt,
		public int $value,
	) {}
}
