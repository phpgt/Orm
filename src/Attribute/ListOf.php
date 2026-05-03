<?php
namespace GT\Orm\Attribute;

use Attribute;

#[Attribute]
class ListOf {
	/** @param class-string $className */
	public function __construct(
		public string $className
	) {}
}
