<?php
namespace Gmo\Salesforce;

use Gmo\Salesforce\Authentication\AuthenticationInterface;
use Gmo\Salesforce\Exception;
use Guzzle\Http;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Client implements LoggerAwareInterface {

	/** @var string */
	protected $apiBaseUrl;
	/** @var LoggerInterface */
	protected $log;
	/** @var  Http\Client */
	protected $guzzle;
	/** @var AuthenticationInterface */
	protected $authentication;

	const SALESFORCE_API_URL_PATTERN = 'https://{region}.salesforce.com/services/data/{version}/';

	/**
	 * Creates a Salesforce REST API client that uses username-password authentication
	 * @param AuthenticationInterface $authentication
	 * @param Http\Client $guzzle
	 * @param string $apiRegion The region to use for the Salesforce API.  i.e. na5 or cs30
	 * @param string $apiVersion The version of the API to use.  i.e. v31.0
	 * @param LoggerInterface $log
	 */
	public function __construct(AuthenticationInterface $authentication, Http\Client $guzzle, $apiRegion, $apiVersion = 'v31.0', LoggerInterface $log = null) {
		$this->apiBaseUrl = str_replace(array('{region}', '{version}'), array($apiRegion, $apiVersion), static::SALESFORCE_API_URL_PATTERN);
		$this->log = $log ?: new NullLogger();
		$this->authentication = $authentication;
		$this->guzzle = $guzzle;
		$this->guzzle->setBaseUrl($this->apiBaseUrl);
	}

	/**
	 * Makes a call to the Query API
	 * @param string $queryToRun
	 * @param array $parameters Parameters to bind
	 * @return QueryIterator
	 * @throws Exception\SalesforceNoResults
	 */
	public function query($queryToRun, $parameters = array()) {
		$apiPath = $this->buildApiPathForQuery('query', $queryToRun, $parameters);
		$queryResults = $this->callQueryApiAndGetQueryResults($apiPath);
		return new QueryIterator($this, $queryResults);
	}

	/**
	 * Makes a call to the QueryAll API
	 * @param string $queryToRun
	 * @param array $parameters Parameters to bind
	 * @return QueryIterator
	 * @throws Exception\SalesforceNoResults
	 */
	public function queryAll($queryToRun, $parameters = array()) {
		$apiPath = $this->buildApiPathForQuery('queryAll', $queryToRun, $parameters);
		$queryResults = $this->callQueryApiAndGetQueryResults($apiPath);
		return new QueryIterator($this, $queryResults);
	}

	/**
	 * Fetch the next QueryResults for a query that has multiple pages worth of returned records
	 * @param QueryResults $queryResults
	 * @return QueryResults
	 * @throws Exception\SalesforceNoResults
	 */
	public function getNextQueryResults(QueryResults $queryResults) {
		$basePath = $this->getPathFromUrl($this->apiBaseUrl);
		$nextRecordsRelativePath = str_replace($basePath, '', $queryResults->getNextQuery());
		return $this->callQueryApiAndGetQueryResults($nextRecordsRelativePath);
	}

	/**
	 * Get a record Id via a call to the Query API
	 * @param $queryString
	 * @return string The Id field of the first result of the query
	 * @throws Exception\SalesforceNoResults
	 */
	public function queryForId($queryString) {
		$jsonResponse = $this->query($queryString);
		return $jsonResponse['records'][0]['Id'];
	}

	/**
	 * Get an Account by Account Id
	 * @param string $accountId
	 * @param string[]|null $fields The Account fields to return. Default Name & BillingCountry
	 * @return mixed The API output, converted from JSON to an associative array
	 * @throws Exception\SalesforceNoResults
	 */
	public function getAccount($accountId, $fields = null) {
		$accountId = urlencode($accountId);
		$defaultFields = array('Name', 'BillingCountry');
		if(empty($fields)) {
			$fields = $defaultFields;
		}
		$fields = implode(',', $fields);
		$response = $this->get("sobjects/Account/{$accountId}?fields={$fields}");
		$jsonResponse = json_decode($response, true);

		if(!isset($jsonResponse['attributes']) || empty($jsonResponse['attributes'])) {
			$message = 'No results found';
			$this->log->info($message, array('response' => $response));
			throw new Exception\SalesforceNoResults($message);
		}

		return $jsonResponse;
	}

	/**
	 * Get a Contact by Account Id
	 * @param string $accountId
	 * @param string[]|null $fields The Contact fields to return. Default FirstName, LastName and MailingCountry
	 * @return mixed The API output, converted from JSON to an associative array
	 * @throws Exception\SalesforceNoResults
	 */
	public function getContact($accountId, $fields = null) {
		$accountId = urlencode($accountId);
		$defaultFields = array('FirstName', 'LastName', 'MailingCountry');
		if(empty($fields)) {
			$fields = $defaultFields;
		}
		$fields = implode(',', $fields);
		$response = $this->get("sobjects/Contact/{$accountId}?fields={$fields}");
		$jsonResponse = json_decode($response, true);

		if(!isset($jsonResponse['attributes']) || empty($jsonResponse['attributes'])) {
			$message = 'No results found';
			$this->log->info($message, array('response' => $response));
			throw new Exception\SalesforceNoResults($message);
		}

		return $jsonResponse;
	}

	/**
	 * Creates a new Account using the provided field values
	 * @param string[] $fields The field values to set on the new Account
	 * @return string The Id of the newly created Account
	 * @throws Exception\Salesforce
	 */
	public function newAccount($fields) {
		return $this->newSalesforceObject("Account", $fields);
	}

	/**
	 * Creates a new Contact using the provided field values
	 * @param string[] $fields The field values to set on the new Contact
	 * @return string The Id of the newly created Contact
	 * @throws Exception\Salesforce
	 */
	public function newContact($fields) {
		return $this->newSalesforceObject("Contact", $fields);
	}

	/**
	 * Updates an Account using the provided field values
	 * @param string $id The Account Id of the Account to update
	 * @param string[] $fields The fields to update
	 * @return bool
	 */
	public function updateAccount($id, $fields) {
		return $this->updateSalesforceObject("Account", $id, $fields);
	}

	/**
	 * Updates an Contact using the provided field values
	 * @param string $id The Contact Id of the Contact to update
	 * @param string[] $fields The fields to update
	 * @return bool
	 */
	public function updateContact($id, $fields) {
		return $this->updateSalesforceObject("Contact", $id, $fields);
	}

	/**
	 * Gets the valid fields for Accounts via the describe API
	 * @return mixed The API output, converted from JSON to an associative array
	 */
	public function getAccountFields() {
		return $this->getFields('Account');
	}

	/**
	 * Gets the valid fields for Contacts via the describe API
	 * @return mixed The API output, converted from JSON to an associative array
	 */
	public function getContactFields() {
		return $this->getFields('Contact');
	}

	/**
	 * Gets the valid fields for a given Salesforce Object via the describe API
	 * @param string $object The name of the salesforce object.  i.e. Account or Contact
	 * @return mixed The API output, converted from JSON to an associative array
	 */
	public function getFields($object) {
		$response = $this->get("sobjects/{$object}/describe");
		$jsonResponse = json_decode($response, true);
		$fields = array();
		foreach($jsonResponse['fields'] as $row) {
			$fields[] = array('label' => $row['label'], 'name' => $row['name']);
		}
		return $fields;
	}

	/**
	 * Creates a new Salesforce Object using the provided field values
	 * @param string $object The name of the salesforce object.  i.e. Account or Contact
	 * @param string[] $fields The field values to set on the new Salesforce Object
	 * @return string The Id of the newly created Salesforce Object
	 * @throws Exception\Salesforce
	 */
	public function newSalesforceObject($object, $fields) {
		$this->log->info('Creating Salesforce object', array(
			'object' => $object,
			'fields' => $fields,
		));
		$fields = json_encode($fields);
		$headers = array(
			'Content-Type' => 'application/json'
		);

		$response = $this->post("sobjects/{$object}/", $headers, $fields);
		$jsonResponse = json_decode($response, true);

		if(!isset($jsonResponse['id']) || empty($jsonResponse['id'])) {
			$message = 'Error while creating account';
			$this->log->info($message, array('response' => $response));
			throw new Exception\Salesforce($message);
		}

		return $jsonResponse['id'];
	}

	/**
	 * Updates an Salesforce Object using the provided field values
	 * @param string $object The name of the salesforce object.  i.e. Account or Contact
	 * @param string $id The Id of the Salesforce Object to update
	 * @param string[] $fields The fields to update
	 * @return bool
	 */
	public function updateSalesforceObject($object, $id, $fields) {
		$this->log->info('Updating Salesforce object', array(
			'id' => $id,
			'object' => $object,
		));
		$id = urlencode($id);
		$fields = json_encode($fields);
		$headers = array(
			'Content-Type' => 'application/json'
		);

		$this->patch("sobjects/{$object}/{$id}", $headers, $fields);
		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function setLogger(LoggerInterface $logger) {
		$this->log = $logger;
	}

	protected function get($path, $headers = array(), $body = null, $options = array()) {
		return $this->request('GET', $path, $headers, $body, $options);
	}

	protected function post($path, $headers = array(), $body = null, $options = array()) {
		return $this->request('POST', $path, $headers, $body, $options);
	}

	protected function patch($path, $headers = array(), $body = null, $options = array()) {
		return $this->request('PATCH', $path, $headers, $body, $options);
	}

	protected function request($type, $path, $headers = array(), $body = null, $options = array()) {
		$this->initializeGuzzle();
		$request = $this->guzzle->createRequest($type, $path, $headers, $body, $options);
		try {
			$response = $request->send();
			$responseBody = $response->getBody();

		} catch (ClientErrorResponseException $e) {
			$response = $e->getResponse();
			$responseBody = $response->getBody();
			$message = $responseBody;

			$jsonResponse = json_decode($responseBody, true);
			if(isset($jsonResponse[0]) && isset($jsonResponse[0]['message'])) {
				$message = $jsonResponse[0]['message'];
			}

			$fields = array();
			if(isset($jsonResponse[0]) && isset($jsonResponse[0]['fields'])) {
				$fields = $jsonResponse[0]['fields'];
			}
			$this->log->error($message, array(
				'response' => $responseBody,
				'fields' => $fields,
			));

			if($fields) {
				throw new Exception\SalesforceFields($message, 0, $fields);
			}

			throw new Exception\Salesforce($message);
		}

		return $responseBody;
	}

	/**
	 * @param string $queryMethod
	 * @param string $queryToRun
	 * @param array $parameters
	 * @return string
	 */
	protected function buildApiPathForQuery($queryMethod, $queryToRun, $parameters = array()) {
		if(!empty($parameters)) {
			$queryToRun = $this->bindParameters($queryToRun, $parameters);
		}

		$queryToRun = urlencode($queryToRun);
		return "{$queryMethod}/?q={$queryToRun}";
	}

	/**
	 * Call the API for the provided query API path, handle No Results, and return a QueryResults object
	 * @param $apiPath
	 * @return QueryResults
	 * @throws Exception\SalesforceNoResults
	 */
	protected function callQueryApiAndGetQueryResults($apiPath) {
		$response = $this->get($apiPath);
		$jsonResponse = json_decode($response, true);

		if(!isset($jsonResponse['totalSize']) || empty($jsonResponse['totalSize'])) {
			$message = 'No results found';
			$this->log->info($message, array('response' => $response));
			throw new Exception\SalesforceNoResults($message);
		}

		return new QueryResults(
			$jsonResponse['records'],
			$jsonResponse['totalSize'],
			$jsonResponse['done'],
			isset($jsonResponse['nextRecordsUrl']) ? $jsonResponse['nextRecordsUrl'] : null
		);
	}

	/**
	 * @param string $queryString
	 * @param array $parameters
	 * @return string
	 */
	protected function bindParameters($queryString, $parameters) {
		$paramKeys = array_keys($parameters);
		$isNumericIndexes = array_reduce(array_map('is_int', $paramKeys), function($carry, $item) { return $carry && $item; }, true);

		if($isNumericIndexes) {
			$searchArray = array_fill(0, count($paramKeys), '?');
			$replaceArray = array_values($parameters);
		} else {
			// NOTE: krsort here will prevent the scenario of a replacement of array('foo' => 1, 'foobar' => 2) on string "Hi :foobar" resulting in "Hi 1bar"
			krsort($parameters);
			$searchArray = array_map(function($string) { return ':' . $string; }, array_keys($parameters));
			$replaceArray = array_values($parameters);
		}

		$replaceArray = $this->addQuotesToStringReplacements($replaceArray);
		$replaceArray = $this->replaceBooleansWithStringLiterals($replaceArray);
		return str_replace($searchArray, $replaceArray, $queryString);
	}

	protected function addQuotesToStringReplacements($replacements) {
		foreach($replacements as $key => $val) {
			if(is_string($val) && !$this->isSalesforceDateFormat($val)) {
				$val = str_replace("'", "\'", $val);
				$replacements[$key] = "'{$val}'";
			}
		}

		return $replacements;
	}

	protected function replaceBooleansWithStringLiterals($replacements) {
		return array_map(function($val) {
			if(!is_bool($val)) {
				return $val;
			}

			$retval = $val ? 'true' : 'false';
			return $retval;
		}, $replacements);
	}

	protected function isSalesforceDateFormat($string) {
		return preg_match('/\d+[-]\d+[-]\d+[T]\d+[:]\d+[:]\d+[Z]/', $string) === 1;
	}

	/**
	 * Lazy loads the access token by running authentication and setting the access token into the $this->guzzle headers
	 */
	protected function initializeGuzzle() {
		if($this->guzzle->getDefaultOption('headers/Authorization')) {
			return;
		}

		$accessToken = $this->authentication->getAccessToken();
		$this->guzzle->setDefaultOption('headers/Authorization', "Bearer {$accessToken}");
	}

	protected function getPathFromUrl($url) {
		$parts = parse_url($url);
		return $parts['path'];
	}
}
