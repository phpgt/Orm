<?php
namespace GT\Orm\Metadata;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

readonly class PropertyMetadata {
	/** @param ?class-string $collectionItemClassName */
	public function __construct(
		private ReflectionProperty $property,
		private ReflectionNamedType $type,
		private PropertyKind $kind,
		private ?string $collectionItemClassName,
		private bool $primaryKey,
		private bool $autoIncrement,
		private bool $hasDefaultValue,
		private bool|int|float|string|null $defaultValue,
		private BackedEnumMetadata $backedEnumMetadata,
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
			return $this->backedEnumMetadata->backingType($this->getTypeName());
		}

		return $this->getTypeName();
	}

	public function getKind():PropertyKind {
		return $this->kind;
	}

	public function isCollection():bool {
		return $this->kind === PropertyKind::COLLECTION;
	}

	public function isEntity():bool {
		return $this->kind === PropertyKind::ENTITY;
	}

	/** @param callable(object):void $initializer */
	public function newTypeLazyGhost(callable $initializer):object {
		return (new ReflectionClass($this->classTypeName()))
			->newLazyGhost($initializer);
	}

	/** @param array<object> $itemList */
	public function initialiseCollectionGhost(
		object $ghost,
		array $itemList,
	):void {
		$className = $this->classTypeName();
		$collection = new $className($itemList);
		$reflection = new ReflectionClass($className);
		foreach($reflection->getProperties() as $property) {
			if($property->isInitialized($collection)) {
				$property->setValue($ghost, $property->getValue($collection));
			}
		}
	}

	/** @return class-string */
	public function getCollectionItemClassName():string {
		if($this->collectionItemClassName === null) {
			throw new LogicException(
				"Property {$this->getName()} is not a collection",
			);
		}

		return $this->collectionItemClassName;
	}

	public function isNullable():bool {
		return $this->type->allowsNull();
	}

	public function acceptsValue(mixed $value):bool {
		if($value === null) {
			return $this->isNullable();
		}

		$typeName = $this->getTypeName();
		return match($typeName) {
			"string" => is_string($value),
			"int" => is_int($value),
			"float" => is_float($value) || is_int($value),
			"bool" => is_bool($value),
			default => $value instanceof $typeName,
		};
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
			PropertyKind::BACKED_ENUM => $this->backedEnumMetadata->fromDatabase(
				$this->getTypeName(),
				$value,
			),
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
		$value = $this->dateTimeWithTimezone($value);
		return match($typeName) {
			DateTimeInterface::class,
			DateTimeImmutable::class => new DateTimeImmutable($value),
			DateTime::class => new DateTime($value),
			default => $this->newDateTime($typeName, $value),
		};
	}

	private function newDateTime(
		string $className,
		string $value,
	):DateTimeInterface {
		if(!is_a($className, DateTimeInterface::class, true)) {
			throw new LogicException("$className is not a date and time class");
		}

		return new $className($value);
	}

	private function dateTimeWithTimezone(string $value):string {
		if(preg_match("/(?:Z|[+-]\\d{2}:?\\d{2})$/", $value)) {
			return $value;
		}

		return "$value+00:00";
	}

	/** @return class-string */
	private function classTypeName():string {
		$className = $this->getTypeName();
		if(!class_exists($className) && !enum_exists($className)) {
			throw new LogicException("Property type $className is not a class");
		}

		return $className;
	}
}
