<?php
namespace Gt\Orm\Attribute;

use Attribute;

#[Attribute]
readonly class PrimaryKey {
	const AUTOINCREMENT = "autoincrement";

	public bool $autoIncrement;

	public function __construct(
		public string $fieldName,
		string...$modifiers
	) {
		$this->autoIncrement = in_array(self::AUTOINCREMENT, $modifiers);
	}
}
