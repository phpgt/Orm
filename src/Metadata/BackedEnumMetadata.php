<?php
namespace GT\Orm\Metadata;

use BackedEnum;
use LogicException;
use ReflectionEnum;

class BackedEnumMetadata {
	public function backingType(string $className):string {
		$className = $this->requireClassName($className);
		$backingType = (new ReflectionEnum($className))->getBackingType();
		if($backingType === null) {
			throw new LogicException("Property type $className is not a backed enumeration");
		}

		return $backingType->getName();
	}

	public function fromDatabase(
		string $className,
		string $value,
	):BackedEnum {
		$className = $this->requireClassName($className);
		$backingValue = $this->backingType($className) === "int"
			? (int)$value
			: $value;

		return $className::from($backingValue);
	}

	/** @return class-string<BackedEnum> */
	private function requireClassName(string $className):string {
		if(!enum_exists($className)
			|| !is_a($className, BackedEnum::class, true)) {
			throw new LogicException("Property type $className is not a backed enumeration");
		}

		return $className;
	}
}
