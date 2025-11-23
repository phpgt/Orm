<?php
namespace Gt\Orm\Test;

use Gt\Database\Database;
use Gt\Database\Result\ResultSet;
use Gt\Database\Result\Row;
use Gt\Orm\Repository;
use Gt\Orm\Test\TestProject\ForeignKeys\University\Department;
use Gt\Orm\Test\TestProject\ForeignKeys\University\Student;
use Gt\Orm\Test\TestProject\ForeignKeys\University\UniversityRepository;
use Gt\SqlBuilder\SelectBuilder;
use PHPUnit\Framework\TestCase;

class RepositoryTest extends TestCase {
	public function testFetch_byId():void {
		$row = self::createMock(Row::class);
		$row->method("contains")
			->willReturnMap([
				["id", true],
				["name", true],
				["headOfDepartment", false],
			]);
		$row->method("get")
			->willReturnMap([
				["id", "12345"],
				["name", "Computing"],
			]);
//
		$resultSet = self::createMock(ResultSet::class);
		$resultSet->expects(self::once())
			->method("fetch")
			->willReturn($row);

		$database = self::createMock(Database::class);
		$database->expects(self::once())
			->method("executeSql")
			->willReturnCallback(function(string $query, array $args)use($resultSet) {
				$query = str_replace(["\n", "\t", "  "], " ", trim($query));
				self::assertSame("select id, name from Department where id = :id", $query);
				self::assertSame(["id" => 12345], $args);
				return $resultSet;
			});

		$sut = new UniversityRepository($database);
		$department = $sut->fetch(Department::class, 12345);
		self::assertSame(12345, $department->id);
		self::assertSame("Computing", $department->name);
	}
}
