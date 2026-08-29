<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Entity;

class UninitialisedEntity implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;
	public string $value;
}
