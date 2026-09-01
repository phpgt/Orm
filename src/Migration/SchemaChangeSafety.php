<?php
namespace GT\Orm\Migration;

enum SchemaChangeSafety {
	case SAFE;
	case DESTRUCTIVE;
	case REQUIRES_DATA;
}
