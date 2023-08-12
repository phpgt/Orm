<?php
namespace Gt\Orm\Migration;

use Gt\Orm\Entity;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

class EntityDetector {
	public function __construct() {}

	/** @return array<class-string> */
	public function getEntityClassList(
		string $dir,
		string $tableClass = Entity::class,
	):array {
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

			if(is_a($className, $tableClass, true)) {
				array_push(
					$declaredTableClassList,
					$className,
				);
			}
		}

		return $declaredTableClassList;
	}

}
