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
	 * @return int
	 */
	public function getTotalSize() {
		return $this->totalSize;
	}

	/**
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
