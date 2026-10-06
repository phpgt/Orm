<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Entity;

readonly class UninitialisedEntity extends Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;
	public string $value;
}
