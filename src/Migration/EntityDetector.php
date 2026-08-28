<?php
namespace GT\Orm\Migration;

use GT\Orm\Entity;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use ReflectionClass;
use RegexIterator;

class EntityDetector {
	public function __construct() {}

	/** @return array<class-string> */
	public function getEntityClassList(
		string $dir,
		string $tableClass = Entity::class,
	):array {
		$realDirectory = realpath($dir);
		$phpFileIterator = new RegexIterator(
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir)
			),
			'/^.+\.php$/i',
			RegexIterator::GET_MATCH
		);

		foreach($phpFileIterator as $fileList) {
			require_once $fileList[0];
		}

		$declaredTableClassList = [];

		foreach(get_declared_classes() as $className) {
			if($className === $tableClass) {
				continue;
			}

			if(!is_a($className, $tableClass, true)) {
				continue;
			}

			$classFile = (new ReflectionClass($className))->getFileName();
			if(!$this->isWithinDirectory($classFile, $realDirectory)) {
				continue;
			}

			array_push($declaredTableClassList, $className);
		}

		return $declaredTableClassList;
	}

	private function isWithinDirectory(
		string|false $file,
		string|false $directory,
	):bool {
		if($file === false || $directory === false) {
			return false;
		}

		$realFile = realpath($file);
		return $realFile !== false
			&& str_starts_with($realFile, $directory . DIRECTORY_SEPARATOR);
	}

}
