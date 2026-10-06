<?php
namespace GT\Orm\Test;

use DateTimeImmutable;
use GT\Orm\Exception\InvalidEntityChangeException;
use GT\Orm\Test\TestProject\Metadata\CustomPrimaryKeyEntity;
use GT\Orm\Test\TestProject\Persistence\Student;
use Error;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use TypeError;

class EntityTest extends TestCase {
	public function testWithReturnsImmutableCopyWithChangedProperties():void {
		$dateOfBirth = new DateTimeImmutable("1815-12-10");
		$student = new Student("Ada", $dateOfBirth);

		$updated = $student->with([
			"name" => "Ada King",
			"active" => false,
		]);

		self::assertNotSame($student, $updated);
		self::assertSame("Ada", $student->name);
		self::assertSame("Ada King", $updated->name);
		self::assertFalse($updated->active);
		self::assertSame($dateOfBirth, $updated->dateOfBirth);
		self::assertFalse(isset($student->id));
		self::assertFalse(isset($updated->id));
	}

	public function testWithPreservesInitialisedPrimaryKey():void {
		$student = new Student("Ada", new DateTimeImmutable("1815-12-10"));
		$reflection = new ReflectionProperty($student, "id");
		$reflection->setValue($student, 42);

		$updated = $student->with(["name" => "Ada King"]);

		self::assertSame(42, $updated->id);
	}

	public function testWithNoChangesReturnsClone():void {
		$student = new Student("Ada", new DateTimeImmutable("1815-12-10"));
		$copy = $student->with([]);

		self::assertNotSame($student, $copy);
		self::assertEquals($student, $copy);
	}

	public function testWithRejectsUnknownProperty():void {
		$student = new Student("Ada", new DateTimeImmutable("1815-12-10"));

		$this->expectException(Error::class);
		$this->expectExceptionMessage("Cannot create dynamic property");
		$student->with(["missing" => "value"]);
	}

	public function testWithRejectsIncorrectPropertyType():void {
		$student = new Student("Ada", new DateTimeImmutable("1815-12-10"));

		$this->expectException(TypeError::class);
		$student->with(["dateOfBirth" => new stdClass()]);
	}

	public function testWithRejectsPrimaryKeyChangeByDefault():void {
		$student = new Student("Ada", new DateTimeImmutable("1815-12-10"));

		$this->expectException(InvalidEntityChangeException::class);
		$this->expectExceptionMessage("Primary key");
		$student->with(["id" => 42]);
	}

	public function testWithCanChangePrimaryKeyWhenExplicitlyAllowed():void {
		$student = new Student("Ada", new DateTimeImmutable("1815-12-10"));
		$copy = $student->with(
			["id" => 42],
			allowPrimaryKey: true,
		);

		self::assertFalse(isset($student->id));
		self::assertSame(42, $copy->id);
	}

	public function testWithRecognisesCustomNamedPrimaryKey():void {
		$entity = new CustomPrimaryKeyEntity("original", "Name");

		$this->expectException(InvalidEntityChangeException::class);
		$this->expectExceptionMessage("::\$code cannot be changed");
		$entity->with(["code" => "changed"]);
	}
}
