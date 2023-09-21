<?php
namespace Gt\Orm\Migration;

class SchemaField {
	private string $type;
	private bool $nullable;
	private mixed $defaultValue;

	public function __construct(
		private readonly string $name,
	) {}

	public function getName():string {
		return $this->name;
	}

	public function setType(mixed $typeName):void {
		$this->type = $typeName;
	}

	public function getType():?string {
		return $this->type ?? null;
	}

	public function setNullable(bool $allowsNull):void {
		$this->nullable = $allowsNull;
	}

	public function getNullable():bool {
		return isset($this->nullable) && $this->nullable === true;
	}

	public function hasDefaultValue():bool {
		return isset($this->defaultValue);
	}

	public function setDefaultValue(mixed $defaultValue):void {
		$this->defaultValue = $defaultValue;
	}

	public function getDefaultValue():mixed {
		return $this->defaultValue ?? null;
	}

}
