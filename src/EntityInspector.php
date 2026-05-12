<?php
namespace GT\Orm;

use DateTimeInterface;
use GT\Orm\Attribute\PrimaryKey;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Stringable;

class EntityInspector {
	public function getTableName(object|string $entityClassName):string {
		$className = is_object($entityClassName)
			? $entityClassName::class
			: $entityClassName;

		return (new ReflectionClass($className))->getShortName();
	}

	public function getPrimaryKeyName(object|string $entityClassName):string {
		return $this->getPrimaryKeyDefinition($entityClassName)->fieldName;
	}

	public function getPrimaryKeyDefinition(object|string $entityClassName):PrimaryKey {
		$refClass = new ReflectionClass($entityClassName);
		$attributeList = $refClass->getAttributes(PrimaryKey::class);

		if($attributeList) {
			return $attributeList[0]->newInstance();
		}

		$idProperty = new ReflectionProperty($entityClassName, "id");
		$idType = $idProperty->getType();

		if($idType instanceof ReflectionNamedType) {
			$typeName = $idType->getName();
			if($typeName === "int") {
				return new PrimaryKey("id", PrimaryKey::AUTOINCREMENT);
			}

			if($typeName === "string") {
				return new PrimaryKey("id", PrimaryKey::ULID);
			}
		}

		return new PrimaryKey("id");
	}

	public function getPrimaryKeyProperty(object|string $entityClassName):ReflectionProperty {
		$primaryKeyName = $this->getPrimaryKeyName($entityClassName);
		return new ReflectionProperty($entityClassName, $primaryKeyName);
	}

	public function isPlainColumnType(string $typeName):bool {
		foreach([
			DateTimeInterface::class,
			Stringable::class,
		] as $plainClass) {
			if(is_subclass_of($typeName, $plainClass) || $typeName === $plainClass) {
				return true;
			}
		}

		return false;
	}

	public function getListItemClassName(ReflectionProperty $refProperty):?string {
		$docComment = $refProperty->getDocComment();
		if(!$docComment) {
			return null;
		}

		if(preg_match('/@var\s+(?:list|array)<([^>]+)>/', $docComment, $matches)) {
			return $this->resolveDocblockClassName($refProperty, trim($matches[1]));
		}

		if(preg_match('/@var\s+([^\s]+)\[\]/', $docComment, $matches)) {
			return $this->resolveDocblockClassName($refProperty, trim($matches[1]));
		}

		return null;
	}

	public function buildForeignKeyName(
		string $propertyName,
		string $foreignTableName,
		string $foreignPrimaryKey,
	):string {
		return implode("_", [
			$propertyName,
			$foreignTableName,
			$foreignPrimaryKey,
		]);
	}

	public function buildJunctionTableName(
		string $ownerTableName,
		string $propertyName,
		string $itemTableName,
	):string {
		return implode("_", [
			$ownerTableName,
			$propertyName,
			$itemTableName,
		]);
	}

	public function buildJunctionKeyName(string $tableName, string $primaryKey):string {
		return implode("_", [
			$tableName,
			$primaryKey,
		]);
	}

	private function resolveDocblockClassName(
		ReflectionProperty $refProperty,
		string $docblockType,
	):string {
		$docblockType = ltrim($docblockType, "\\");
		if(class_exists($docblockType)) {
			return $docblockType;
		}

		$namespace = $refProperty->getDeclaringClass()->getNamespaceName();
		if($namespace) {
			$namespacedType = $namespace . "\\" . $docblockType;
			if(class_exists($namespacedType)) {
				return $namespacedType;
			}
		}

		return $docblockType;
	}
}
