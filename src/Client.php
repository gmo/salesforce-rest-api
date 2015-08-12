<?php
namespace Gmo\Salesforce;

use Guzzle\Http;
use Psr\Log\LoggerInterface;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Psr\Log\NullLogger;
use Gmo\Salesforce\Exception;

class Client {

	/** @var string */
	protected $apiBaseUrl;
	/** @var string */
	protected $loginApiUrl;
	/** @var string */
	protected $clientId;
	/** @var string */
	protected $clientSecret;
	/** @var string */
	protected $username;
	/** @var string */
	protected $password;
	/** @var string */
	protected $securityToken;
	/** @var LoggerInterface */
	protected $log;
	/** @var  Http\Client */
	protected $guzzle;
	/** @var string */
	protected $access_token;

	const SALESFORCE_API_URL_PATTERN = 'https://{region}.salesforce.com/services/data/{version}/';

	/**
	 * Creates a Salesforce REST API client that uses username-password authentication
	 * @param string $apiRegion The region to use for the Salesforce API.  i.e. na5 or cs30
	 * @param string $clientId The client id for your Salesforce API access
	 * @param string $clientSecret The client secret for your Salesforce API access
	 * @param string $username The username you want to access the API as
	 * @param string $password The password for the provided username
	 * @param string $securityToken The security token for the provided username
	 * @param string $apiVersion The version of the API to use.  i.e. v31.0
	 * @param string $loginApiUrl The login URL for your Salesforce instance
	 * @param LoggerInterface $log
	 * @throws Exception\Salesforce
	 */
	public function __construct($apiRegion, $clientId, $clientSecret, $username, $password, $securityToken,
		$apiVersion = 'v31.0',
		$loginApiUrl = "https://login.salesforce.com/services/", LoggerInterface $log = null
	) {
		$this->apiBaseUrl = str_replace(array('{region}', '{version}'), array($apiRegion, $apiVersion), static::SALESFORCE_API_URL_PATTERN);
		$this->loginApiUrl = $loginApiUrl;
		$this->clientId = $clientId;
		$this->clientSecret = $clientSecret;
		$this->username = $username;
		$this->password = $password;
		$this->securityToken = $securityToken;
		$this->log = $log ?: new NullLogger();
		$this->login();
		$this->guzzle = new Http\Client($this->apiBaseUrl, array(
			'request.options' => array(
				'headers' => array(
					"Authorization" => "Bearer {$this->access_token}"
				),
			),
		));
	}

	/**
	 * Makes a call to the Query API
	 * @param $queryString
	 * @return mixed The API output, converted from JSON to an associative array
	 * @throws Exception\SalesforceNoResults
	 */
	public function query($queryString) {
		$queryString = urlencode($queryString);
		$response = $this->get("query/?q={$queryString}");
		$jsonResponse = json_decode($response, true);

		if(!isset($jsonResponse['totalSize']) || empty($jsonResponse['totalSize'])) {
			$message = 'No results found';
			$this->log->info($message, array('response' => $response));
			throw new Exception\SalesforceNoResults($message);
		}

		return $jsonResponse['records'];
	}

	/**
	 * Makes a call to the QueryAll API
	 * @param $queryString
	 * @return mixed The API output, converted from JSON to an associative array
	 * @throws Exception\SalesforceNoResults
	 */
	public function queryAll($queryString) {
		$queryString = urlencode($queryString);
		$response = $this->get("queryAll/?q={$queryString}");
		$jsonResponse = json_decode($response, true);

		if(!isset($jsonResponse['totalSize']) || empty($jsonResponse['totalSize'])) {
			$message = 'No results found';
			$this->log->info($message, array('response' => $response));
			throw new Exception\SalesforceNoResults($message);
		}

		return $jsonResponse['records'];
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

	protected function login() {
		$client = new Http\Client($this->loginApiUrl);
		$post_fields = array(
			'grant_type' => 'password',
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
			'username' => $this->username,
			'password' => $this->password . $this->securityToken,
		);
		$request = $client->post('oauth2/token', null, $post_fields);
		$request->setAuth('user', 'pass');
		$response = $request->send();
		$responseBody = $response->getBody();
		$jsonResponse = json_decode($responseBody, true);

		if($response->getStatusCode() !== 200) {
			$message = $responseBody;
			if(isset($jsonResponse['error_description'])) {
				$message = $jsonResponse['error_description'];
			}
			$this->log->error($message, array('response' => $responseBody));
			throw new Exception\Salesforce($message);
		}

		if(!isset($jsonResponse['access_token']) || empty($jsonResponse['access_token'])) {
			$message = 'Access token not found';
			$this->log->error($message, array('response' => $responseBody));
			throw new Exception\Salesforce($message);
		}

		$this->access_token = $jsonResponse['access_token'];

	}
}
