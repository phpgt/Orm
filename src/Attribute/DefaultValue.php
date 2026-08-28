<?php
namespace GT\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class DefaultValue {
	public function __construct(
		public bool|int|float|string|null $value,
	) {}
}
