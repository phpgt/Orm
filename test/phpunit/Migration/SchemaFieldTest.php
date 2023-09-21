<?php
namespace Gt\Orm\Test\Migration;

use Gt\Orm\Migration\SchemaField;
use PHPUnit\Framework\TestCase;
use stdClass;

class SchemaFieldTest extends TestCase {
	public function testConstruct():void {
		$sut = new SchemaField("test");
		self::assertSame("test", $sut->getName());
	}

	public function testType_noTypeByDefault():void {
		$sut = new SchemaField("test");
		self::assertNull($sut->getType());
	}

	public function testType():void {
		$sut = new SchemaField("test");
		$sut->setType("string");
		self::assertSame("string", $sut->getType());
	}

	public function testType_class():void {
		$sut = new SchemaField("test");
		$sut->setType(stdClass::class);
		self::assertSame(stdClass::class, $sut->getType());
	}

	public function testNullable_notNullableByDefault():void {
		$sut = new SchemaField("test");
		self::assertFalse($sut->getNullable());
	}

	public function testNullable():void {
		$sut = new SchemaField("test");
		$sut->setNullable(true);
		self::assertTrue($sut->getNullable());
	}

	public function testHasDefaultValue():void {
		$sut = new SchemaField("test");
		self::assertFalse($sut->hasDefaultValue());
		$sut->setDefaultValue(123);
		self::assertTrue($sut->hasDefaultValue());
	}

	public function testGetDefaultValue_defaultNull():void {
		$sut = new SchemaField("test");
		self::assertNull($sut->getDefaultValue());
	}

	public function testGetDefaultValue():void {
		$sut = new SchemaField("test");
		$sut->setDefaultValue(123);
		self::assertSame(123, $sut->getDefaultValue());
	}
}
