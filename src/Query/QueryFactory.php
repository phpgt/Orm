<?php
namespace GT\Orm\Query;

class QueryFactory {
	public function select():FetchQuery {
		return new FetchQuery();
	}

	public function delete():DeleteQuery {
		return new DeleteQuery();
	}
}
