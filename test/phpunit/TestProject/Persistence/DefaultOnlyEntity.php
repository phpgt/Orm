<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Entity;

class DefaultOnlyEntity implements Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	#[DefaultValue("from database")]
	public string $value;
}
