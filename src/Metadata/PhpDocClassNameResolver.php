<?php
namespace GT\Orm\Metadata;

use ReflectionClass;

class PhpDocClassNameResolver {
	/**
	 * @template T of object
	 * @param ReflectionClass<T> $context
	 * @return ?class-string
	 */
	public function resolve(ReflectionClass $context, string $typeName):?string {
		if(str_starts_with($typeName, "\\")) {
			return $this->existingClass(substr($typeName, 1));
		}

		$importedClassName = $this->importedClassName($context, $typeName);
		if($importedClassName !== null) {
			return $this->existingClass($importedClassName);
		}

		$namespace = $context->getNamespaceName();
		$namespacedClassName = $namespace === ""
			? $typeName
			: "$namespace\\$typeName";
		return $this->existingClass($namespacedClassName)
			?? $this->existingClass($typeName);
	}

	/**
	 * @template T of object
	 * @param ReflectionClass<T> $context
	 */
	private function importedClassName(
		ReflectionClass $context,
		string $typeName,
	):?string {
		$typePartList = explode("\\", $typeName);
		$alias = $typePartList[0];
		$importList = $this->importList($context);
		if(!isset($importList[$alias])) {
			return null;
		}

		array_shift($typePartList);
		return implode("\\", [
			$importList[$alias],
			...$typePartList,
		]);
	}

	/**
	 * @template T of object
	 * @param ReflectionClass<T> $context
	 * @return array<string, string>
	 */
	private function importList(ReflectionClass $context):array {
		$fileName = $context->getFileName();
		if($fileName === false) {
			return [];
		}

		$source = file_get_contents($fileName);
		if($source === false) {
			return [];
		}

		$sourceBeforeClass = implode("\n", array_slice(
			explode("\n", $source),
			0,
			$context->getStartLine() - 1,
		));
		preg_match_all(
			'/^\s*use\s+(?!function\s|const\s)([^;]+);/mi',
			$sourceBeforeClass,
			$matchList,
		);

		$importList = [];
		foreach($matchList[1] as $declaration) {
			$this->addImportDeclaration($importList, trim($declaration));
		}

		return $importList;
	}

	/** @param array<string, string> $importList */
	private function addImportDeclaration(
		array &$importList,
		string $declaration,
	):void {
		if(preg_match('/^([^{}]+)\{([^{}]+)}$/', $declaration, $match)) {
			$prefix = rtrim(trim($match[1]), "\\") . "\\";
			foreach(explode(",", $match[2]) as $groupedImport) {
				$this->addImport($importList, $prefix . trim($groupedImport));
			}
			return;
		}

		foreach(explode(",", $declaration) as $import) {
			$this->addImport($importList, trim($import));
		}
	}

	/** @param array<string, string> $importList */
	private function addImport(array &$importList, string $import):void {
		$partList = preg_split('/\s+as\s+/i', $import);
		$className = ltrim($partList[0], "\\");
		$alias = $partList[1] ?? substr(
			$className,
			(int)strrpos("\\$className", "\\"),
		);
		$importList[$alias] = $className;
	}

	/** @return ?class-string */
	private function existingClass(string $className):?string {
		return class_exists($className) ? $className : null;
	}
}
