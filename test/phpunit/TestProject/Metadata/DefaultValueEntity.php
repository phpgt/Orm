<?php
namespace GT\Orm\Test\TestProject\Metadata;

use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Entity;

class DefaultValueEntity implements Entity {
	public int $id;

	#[DefaultValue("O'Reilly")]
	public string $name;

	#[DefaultValue(null)]
	public ?string $description;
}
