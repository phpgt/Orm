<?php
namespace GT\Orm\Metadata;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;

readonly class PropertyMetadata {
	public function __construct(
		private ReflectionProperty $property,
		private ReflectionNamedType $type,
		private PropertyKind $kind,
		private bool $primaryKey,
		private bool $autoIncrement,
		private bool $hasDefaultValue,
		private bool|int|float|string|null $defaultValue,
	) {}

	public function getProperty():ReflectionProperty {
		return $this->property;
	}

	public function getName():string {
		return $this->property->getName();
	}

	public function getTypeName():string {
		return $this->type->getName();
	}

	public function getStorageType():string {
		if($this->kind === PropertyKind::BACKED_ENUM) {
			$refEnum = new ReflectionEnum($this->getTypeName());
			return $refEnum->getBackingType()->getName();
		}

		return $this->getTypeName();
	}

	public function getKind():PropertyKind {
		return $this->kind;
	}

	public function isNullable():bool {
		return $this->type->allowsNull();
	}

	public function isPrimaryKey():bool {
		return $this->primaryKey;
	}

	public function isAutoIncrement():bool {
		return $this->autoIncrement;
	}

	public function hasDefaultValue():bool {
		return $this->hasDefaultValue;
	}

	public function getDefaultValue():bool|int|float|string|null {
		return $this->defaultValue;
	}

	public function fromDatabase(?string $value):mixed {
		if($value === null) {
			return null;
		}

		return match($this->kind) {
			PropertyKind::SCALAR => $this->scalarFromDatabase($value),
			PropertyKind::DATE_TIME => $this->dateTimeFromDatabase($value),
			PropertyKind::BACKED_ENUM => $this->enumFromDatabase($value),
			default => $value,
		};
	}

	private function scalarFromDatabase(string $value):bool|int|float|string {
		return match($this->getTypeName()) {
			"int" => (int)$value,
			"float" => (float)$value,
			"bool" => filter_var($value, FILTER_VALIDATE_BOOL),
			default => $value,
		};
	}

	private function dateTimeFromDatabase(string $value):DateTimeInterface {
		$typeName = $this->getTypeName();
		return match($typeName) {
			DateTimeInterface::class,
			DateTimeImmutable::class => new DateTimeImmutable($value),
			DateTime::class => new DateTime($value),
			default => new $typeName($value),
		};
	}

	private function enumFromDatabase(string $value):BackedEnum {
		/** @var class-string<BackedEnum> $typeName */
		$typeName = $this->getTypeName();
		$backingType = (new ReflectionEnum($typeName))->getBackingType()->getName();
		$backingValue = $backingType === "int" ? (int)$value : $value;

		return $typeName::from($backingValue);
	}
}
