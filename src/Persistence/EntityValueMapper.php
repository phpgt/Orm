<?php
namespace GT\Orm\Persistence;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GT\Orm\Exception\InvalidEntityStateException;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyKind;
use GT\Orm\Metadata\PropertyMetadata;

class EntityValueMapper {
	public function __construct(
		private readonly EntityMetadataFactory $metadataFactory,
	) {}

	public function propertyValue(
		object $entity,
		PropertyMetadata $metadata,
	):bool|int|float|string|null {
		$property = $metadata->getProperty();
		if(!$property->isInitialized($entity)) {
			$className = $entity::class;
			throw new InvalidEntityStateException(
				"Entity property $className::\${$metadata->getName()} is not initialised",
			);
		}

		return $this->value($metadata, $property->getValue($entity));
	}

	public function value(
		PropertyMetadata $metadata,
		mixed $value,
	):bool|int|float|string|null {
		if($value === null) {
			return null;
		}

		return match($metadata->getKind()) {
			PropertyKind::SCALAR => $this->scalarValue($value),
			PropertyKind::DATE_TIME => $this->dateTimeValue($value),
			PropertyKind::BACKED_ENUM => $this->enumValue($value),
			PropertyKind::ENTITY => $this->entityPrimaryKeyValue($value),
			PropertyKind::COLLECTION => throw new InvalidEntityStateException(
				"Collection property {$metadata->getName()} is stored in a junction table",
			),
		};
	}

	private function scalarValue(mixed $value):int|float|string {
		if(is_bool($value)) {
			return $value ? 1 : 0;
		}
		if(is_int($value) || is_float($value) || is_string($value)) {
			return $value;
		}

		throw new InvalidEntityStateException("Unsupported scalar value");
	}

	private function dateTimeValue(mixed $value):string {
		if(!$value instanceof DateTimeInterface) {
			throw new InvalidEntityStateException("Date and time property has an invalid value");
		}

		return DateTimeImmutable::createFromInterface($value)
			->setTimezone(new DateTimeZone("UTC"))
			->format("Y-m-d H:i:s.u");
	}

	private function enumValue(mixed $value):int|string {
		if(!$value instanceof BackedEnum) {
			throw new InvalidEntityStateException("Enumeration property has an invalid value");
		}

		return $value->value;
	}

	private function entityPrimaryKeyValue(mixed $value):int|string {
		if(!is_object($value)) {
			throw new InvalidEntityStateException("Related entity property has an invalid value");
		}

		$className = $value::class;
		$metadata = $this->metadataFactory->get($value);
		$primaryKey = $metadata->requirePrimaryKey();
		$primaryKeyValue = $this->propertyValue($value, $primaryKey);
		if(!is_int($primaryKeyValue) && !is_string($primaryKeyValue)) {
			throw new InvalidEntityStateException(
				"Related entity $className does not have an initialised primary key",
			);
		}

		return $primaryKeyValue;
	}
}
