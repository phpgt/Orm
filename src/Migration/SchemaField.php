<?php
namespace Gt\Orm\Migration;

class SchemaField {
	private string $type;
	private bool $nullable;
	private mixed $defaultValue;

	public function __construct(
		public readonly string $name,
	) {}

	public function setType(mixed $typeName):void {
		$this->type = $typeName;
	}

	public function getType():string {
		return $this->type;
	}


	public function setNullable(bool $allowsNull):void {
		$this->nullable = $allowsNull;
	}

	public function setDefaultValue(mixed $defaultValue):void {
		$this->defaultValue = $defaultValue;
	}

	public function getDefaultValue():mixed {
		return $this->defaultValue ?? null;
	}

}
