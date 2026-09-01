<?php
namespace GT\Orm\Migration;

enum SchemaChangeType {
	case CREATE_TABLE;
	case DROP_TABLE;
	case ADD_FIELD;
	case DROP_FIELD;
	case ALTER_FIELD;
}
