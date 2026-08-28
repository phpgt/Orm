<?php
namespace GT\Orm\Test\Metadata;

use DateTimeImmutable;
use GT\Orm\Attribute\AutoIncrementPrimaryKey;
use GT\Orm\Attribute\DefaultValue;
use GT\Orm\Attribute\PrimaryKey;
use GT\Orm\Exception\InvalidAutoIncrementException;
use GT\Orm\Exception\InvalidDefaultValueException;
use GT\Orm\Exception\InvalidEntityPropertyException;
use GT\Orm\Exception\InvalidPrimaryKeyException;
use GT\Orm\Metadata\EntityMetadataFactory;
use GT\Orm\Metadata\PropertyKind;
use GT\Orm\Test\TestProject\Metadata\EnumEntity;
use PHPUnit\Framework\TestCase;

class EntityMetadataFactoryTest extends TestCase {
	public function testFirstIdPropertyIsInferredAsPrimaryKey():void {
		$entity = new class() {
			public int $id;
			public string $name;
		};

		$metadata = (new EntityMetadataFactory())->get($entity);

		self::assertSame("id", $metadata->requirePrimaryKey()->getName());
	}

	public function testIdIsNotInferredWhenItIsNotFirst():void {
		$entity = new class() {
			public string $name;
			public int $id;
		};

		$metadata = (new EntityMetadataFactory())->get($entity);

		self::assertNull($metadata->getPrimaryKey());
	}

	public function testExplicitPrimaryKeyOverridesConvention():void {
		$entity = new class() {
			public int $id;
			#[PrimaryKey]
			public string $code;
		};

		$metadata = (new EntityMetadataFactory())->get($entity);

		self::assertSame("code", $metadata->requirePrimaryKey()->getName());
	}

	public function testAutoIncrementIntegerPrimaryKey():void {
		$entity = new class() {
			#[AutoIncrementPrimaryKey]
			public int $number;
		};

		$primaryKey = (new EntityMetadataFactory())->get($entity)
			->requirePrimaryKey();

		self::assertSame("number", $primaryKey->getName());
		self::assertTrue($primaryKey->isAutoIncrement());
		self::assertFalse($primaryKey->getProperty()->isInitialized($entity));
	}

	public function testAutoIncrementPrimaryKeyDoesNotRequireIdConvention():void {
		$entity = new class() {
			public string $name;
			#[AutoIncrementPrimaryKey]
			public int $number;
		};

		$primaryKey = (new EntityMetadataFactory())->get($entity)
			->requirePrimaryKey();

		self::assertSame("number", $primaryKey->getName());
		self::assertTrue($primaryKey->isAutoIncrement());
	}

	public function testDateTimeIsAValueRatherThanAnEntity():void {
		$entity = new class() {
			public int $id;
			public DateTimeImmutable $createdAt;
		};

		$propertyList = (new EntityMetadataFactory())->get($entity)
			->getPropertyList();

		self::assertSame(PropertyKind::DATE_TIME, $propertyList[1]->getKind());
	}

	public function testBackedEnumUsesItsBackingType():void {
		$propertyList = (new EntityMetadataFactory())->get(EnumEntity::class)
			->getPropertyList();

		self::assertSame(PropertyKind::BACKED_ENUM, $propertyList[1]->getKind());
		self::assertSame("string", $propertyList[1]->getStorageType());
	}

	public function testMetadataIsCachedByClass():void {
		$entity = new class() {
			public int $id;
		};
		$factory = new EntityMetadataFactory();

		self::assertSame($factory->get($entity), $factory->get($entity::class));
	}

	public function testMultipleExplicitPrimaryKeysAreRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			public int $first;
			#[PrimaryKey]
			public string $second;
		};

		$this->expectException(InvalidPrimaryKeyException::class);
		$this->expectExceptionMessage("defines more than one primary key");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testRepeatedPrimaryKeyAttributeIsRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			#[PrimaryKey]
			public int $id;
		};

		$this->expectException(InvalidPrimaryKeyException::class);
		$this->expectExceptionMessage("PrimaryKey cannot be repeated");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testNullablePrimaryKeyIsRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			public ?int $id;
		};

		$this->expectException(InvalidPrimaryKeyException::class);
		$this->expectExceptionMessage("must be a non-nullable int or string");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testUnsupportedPrimaryKeyTypeIsRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			public bool $id;
		};

		$this->expectException(InvalidPrimaryKeyException::class);
		$this->expectExceptionMessage("must be a non-nullable int or string");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testPrivateAttributedPropertyIsRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			private int $id;
		};

		$this->expectException(InvalidEntityPropertyException::class);
		$this->expectExceptionMessage("must be public and non-static");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testStaticAttributedPropertyIsRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			public static int $id;
		};

		$this->expectException(InvalidEntityPropertyException::class);
		$this->expectExceptionMessage("must be public and non-static");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testAutoIncrementPrimaryKeyOverridesIdConvention():void {
		$entity = new class() {
			public int $id;
			#[AutoIncrementPrimaryKey]
			public int $sequence;
		};

		$primaryKey = (new EntityMetadataFactory())->get($entity)
			->requirePrimaryKey();

		self::assertSame("sequence", $primaryKey->getName());
		self::assertTrue($primaryKey->isAutoIncrement());
	}

	public function testAutoIncrementNonIntegerIsRejected():void {
		$entity = new class() {
			#[AutoIncrementPrimaryKey]
			public string $id;
		};

		$this->expectException(InvalidAutoIncrementException::class);
		$this->expectExceptionMessage("must have type int");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testNullableAutoIncrementPrimaryKeyIsRejected():void {
		$entity = new class() {
			#[AutoIncrementPrimaryKey]
			public ?int $id;
		};

		$this->expectException(InvalidPrimaryKeyException::class);
		$this->expectExceptionMessage("must be a non-nullable int or string");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testPromotedAutoIncrementPropertyIsRejected():void {
		$entity = new class(123) {
			public function __construct(
				#[AutoIncrementPrimaryKey]
				public int $id,
			) {}
		};

		$this->expectException(InvalidAutoIncrementException::class);
		$this->expectExceptionMessage("must be declared outside the constructor");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testPhpDefaultOnAutoIncrementPropertyIsRejected():void {
		$entity = new class() {
			#[AutoIncrementPrimaryKey]
			public int $id = 0;
		};

		$this->expectException(InvalidAutoIncrementException::class);
		$this->expectExceptionMessage("without a PHP default");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testSqlDefaultOnAutoIncrementPropertyIsRejected():void {
		$entity = new class() {
			#[AutoIncrementPrimaryKey]
			#[DefaultValue(123)]
			public int $id;
		};

		$this->expectException(InvalidDefaultValueException::class);
		$this->expectExceptionMessage("cannot define a SQL default");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testPrimaryKeyAndAutoIncrementPrimaryKeyOnDifferentPropertiesAreRejected():void {
		$entity = new class() {
			#[PrimaryKey]
			public string $code;
			#[AutoIncrementPrimaryKey]
			public int $id;
		};

		$this->expectException(InvalidPrimaryKeyException::class);
		$this->expectExceptionMessage("defines more than one primary key");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testRepeatedAutoIncrementPrimaryKeyAttributeIsRejected():void {
		$entity = new class() {
			#[AutoIncrementPrimaryKey]
			#[AutoIncrementPrimaryKey]
			public int $id;
		};

		$this->expectException(InvalidAutoIncrementException::class);
		$this->expectExceptionMessage("AutoIncrementPrimaryKey cannot be repeated");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testNullSqlDefaultOnNonNullablePropertyIsRejected():void {
		$entity = new class() {
			public int $id;
			#[DefaultValue(null)]
			public string $name;
		};

		$this->expectException(InvalidDefaultValueException::class);
		$this->expectExceptionMessage("cannot default to null");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testSqlDefaultMustMatchPropertyType():void {
		$entity = new class() {
			public int $id;
			#[DefaultValue("not-an-integer")]
			public int $position;
		};

		$this->expectException(InvalidDefaultValueException::class);
		$this->expectExceptionMessage("does not match its PHP type");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testUnsupportedClassPropertyIsRejected():void {
		$entity = new class() {
			public int $id;
			public \stdClass $value;
		};

		$this->expectException(InvalidEntityPropertyException::class);
		$this->expectExceptionMessage("must be a supported value type");
		(new EntityMetadataFactory())->get($entity);
	}

	public function testUnionTypedPropertyIsRejected():void {
		$entity = new class() {
			public int|string $id;
		};

		$this->expectException(InvalidEntityPropertyException::class);
		$this->expectExceptionMessage("must have one named type");
		(new EntityMetadataFactory())->get($entity);
	}
}
