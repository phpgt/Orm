<?php
namespace GT\Orm\Test\TestProject\Persistence;

use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Entity;

readonly class DefaultOnlyEntity extends Entity {
	#[AutoIncrementPrimaryKey]
	public int $id;

	#[DefaultValue("from database")]
	public string $value;

	public function __construct(string ...$valueList) {
		if(isset($valueList[0])) {
			$this->value = $valueList[0];
		}
	}
}
