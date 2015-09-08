<?php
namespace Gmo\Salesforce;

class QueryResults {
	/** @var array */
	protected $results;
	protected $totalSize;
	protected $isDone;
	protected $nextQuery;

	public function __construct(array $results, $totalSize, $isDone, $nextQuery) {
		$this->results = $results;
		$this->totalSize = $totalSize;
		$this->isDone = $isDone;
		$this->nextQuery = $nextQuery;
	}

	/**
	 * The Query API output, converted from JSON to an associative array
	 * @return array
	 */
	public function getResults() {
		return $this->results;
	}

	/**
	 * Returns the total number of records that the query matched
	 * @return int
	 */
	public function getTotalSize() {
		return $this->totalSize;
	}

	/**
	 * Returns whether or not there are more query results that haven't been returned in this results set
	 * @return bool
	 */
	public function isDone() {
		return $this->isDone;
	}

	/**
	 * Get the query string to grab the next results set
	 * @return string
	 */
	public function getNextQuery() {
		return $this->nextQuery;
	}

}
