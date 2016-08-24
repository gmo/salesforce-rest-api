<?php
namespace Gmo\Salesforce;

use Gmo\Salesforce\Authentication\AuthenticationInterface;
use Gmo\Salesforce\Exception;
use Guzzle\Http;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Client implements LoggerAwareInterface
{

    const SALESFORCE_API_URL_PATTERN = 'https://{region}.salesforce.com/services/data/{version}/';
    /** @var string */
    protected $apiBaseUrl;
    /** @var LoggerInterface */
    protected $log;
    /** @var Http\Client */
    protected $guzzle;
    /** @var AuthenticationInterface */
    protected $authentication;

    /**
     * Creates a Salesforce REST API client that uses username-password authentication
     *
     * @param AuthenticationInterface $authentication
     * @param Http\Client             $guzzle
     * @param string                  $apiRegion  The region to use for the Salesforce API.  i.e. na5 or cs30
     * @param string                  $apiVersion The version of the API to use.  i.e. v31.0
     * @param LoggerInterface         $log
     */
    public function __construct(
        AuthenticationInterface $authentication,
        Http\Client $guzzle,
        $apiRegion,
        $apiVersion = 'v31.0',
        LoggerInterface $log = null
    ) {
        $this->apiBaseUrl = str_replace(
            ['{region}', '{version}'],
            [$apiRegion, $apiVersion],
            static::SALESFORCE_API_URL_PATTERN
        );
        $this->log = $log ?: new NullLogger();
        $this->authentication = $authentication;
        $this->guzzle = $guzzle;
        $this->guzzle->setBaseUrl($this->apiBaseUrl);
    }

    /**
     * Makes a call to the QueryAll API
     *
     * @param string $queryToRun
     * @param array  $parameters Parameters to bind
     *
     * @return QueryIterator
     * @throws Exception\SalesforceNoResults
     */
    public function queryAll($queryToRun, $parameters = [])
    {
        $apiPath = $this->buildApiPathForQuery('queryAll', $queryToRun, $parameters);
        $queryResults = $this->callQueryApiAndGetQueryResults($apiPath);

        return new QueryIterator($this, $queryResults);
    }

    /**
     * @param string $queryMethod
     * @param string $queryToRun
     * @param array  $parameters
     *
     * @return string
     */
    protected function buildApiPathForQuery($queryMethod, $queryToRun, $parameters = [])
    {
        if (!empty($parameters)) {
            $queryToRun = $this->bindParameters($queryToRun, $parameters);
        }

        $queryToRun = urlencode($queryToRun);

        return "{$queryMethod}/?q={$queryToRun}";
    }

    /**
     * @param string $queryString
     * @param array  $parameters
     *
     * @return string
     */
    protected function bindParameters($queryString, $parameters)
    {
        $paramKeys = array_keys($parameters);
        $isNumericIndexes = array_reduce(
            array_map('is_int', $paramKeys),
            function ($carry, $item) {
                return $carry && $item;
            },
            true
        );

        if ($isNumericIndexes) {
            $searchArray = array_fill(0, count($paramKeys), '?');
            $replaceArray = array_values($parameters);
        } else {
            // NOTE: krsort here will prevent the scenario of a replacement of array('foo' => 1, 'foobar' => 2) on string "Hi :foobar" resulting in "Hi 1bar"
            krsort($parameters);
            $searchArray = array_map(
                function ($string) {
                    return ':' . $string;
                },
                array_keys($parameters)
            );
            $replaceArray = array_values($parameters);
        }

        $replaceArray = $this->addQuotesToStringReplacements($replaceArray);
        $replaceArray = $this->replaceBooleansWithStringLiterals($replaceArray);

        return str_replace($searchArray, $replaceArray, $queryString);
    }

    protected function addQuotesToStringReplacements($replacements)
    {
        foreach ($replacements as $key => $val) {
            if (is_string($val) && !$this->isSalesforceDateFormat($val)) {
                $val = str_replace("'", "\'", $val);
                $replacements[$key] = "'{$val}'";
            }
        }

        return $replacements;
    }

    protected function isSalesforceDateFormat($string)
    {
        return preg_match('/\d+[-]\d+[-]\d+[T]\d+[:]\d+[:]\d+[Z]/', $string) === 1;
    }

    protected function replaceBooleansWithStringLiterals($replacements)
    {
        return array_map(
            function ($val) {
                if (!is_bool($val)) {
                    return $val;
                }

                $retval = $val ? 'true' : 'false';

                return $retval;
            },
            $replacements
        );
    }

    /**
     * Call the API for the provided query API path, handle No Results, and return a QueryResults object
     *
     * @param $apiPath
     *
     * @return QueryResults
     * @throws Exception\SalesforceNoResults
     */
    protected function callQueryApiAndGetQueryResults($apiPath)
    {
        $response = $this->get($apiPath);
        $jsonResponse = json_decode($response, true);

        if (!isset($jsonResponse['totalSize']) || empty($jsonResponse['totalSize'])) {
            $message = 'No results found';
            $this->log->info($message, ['response' => $response]);
            throw new Exception\SalesforceNoResults($message);
        }

        return new QueryResults(
            $jsonResponse['records'],
            $jsonResponse['totalSize'],
            $jsonResponse['done'],
            isset($jsonResponse['nextRecordsUrl']) ? $jsonResponse['nextRecordsUrl'] : null
        );
    }

    protected function get($path, $headers = [], $body = null, $options = [])
    {
        return $this->requestWithAutomaticReauthorize('GET', $path, $headers, $body, $options);
    }

    protected function requestWithAutomaticReauthorize(
        $type,
        $path,
        $headers = [],
        $body = null,
        $options = []
    ) {
        try {
            return $this->request($type, $path, $headers, $body, $options);
        } catch (Exception\SessionExpired $e) {
            $this->authentication->invalidateAccessToken();
            $this->setAccessTokenInGuzzleFromAuthentication();

            return $this->request($type, $path, $headers, $body, $options);
        }
    }

    protected function request($type, $path, $headers = [], $body = null, $options = [])
    {
        $this->initializeGuzzle();
        $request = $this->guzzle->createRequest($type, $path, $headers, $body, $options);
        try {
            $response = $request->send();
            $responseBody = $response->getBody();

        } catch (ClientErrorResponseException $e) {
            $response = $e->getResponse();
            $responseBody = $response->getBody();
            $message = $responseBody;
            $errorCode = $response->getStatusCode();

            $jsonResponse = json_decode($responseBody, true);
            if (isset($jsonResponse[0]) && isset($jsonResponse[0]['message'])) {
                $message = $jsonResponse[0]['message'];
            }

            $fields = [];
            if (isset($jsonResponse[0]) && isset($jsonResponse[0]['fields'])) {
                $fields = $jsonResponse[0]['fields'];
            }
            $this->log->error($message, [
                'response' => $responseBody,
                'fields'   => $fields,
            ]);

            throw $this->getExceptionForSalesforceError($message, $errorCode, $fields);
        }

        return $responseBody;
    }

    /**
     * Lazy loads the access token by running authentication and setting the access token into the $this->guzzle headers
     */
    protected function initializeGuzzle()
    {
        if ($this->guzzle->getDefaultOption('headers/Authorization')) {
            return;
        }

        $this->setAccessTokenInGuzzleFromAuthentication();
    }

    protected function setAccessTokenInGuzzleFromAuthentication()
    {
        $accessToken = $this->authentication->getAccessToken();
        $this->guzzle->setDefaultOption('headers/Authorization', "Bearer {$accessToken}");
    }

    /**
     * @param string $message
     * @param int    $code
     * @param array  $fields
     *
     * @return Exception\Salesforce
     */
    protected function getExceptionForSalesforceError($message, $code, $fields)
    {
        if (!empty($fields)) {
            return new Exception\SalesforceFields($message, $code, $fields);
        }

        if ($code === Exception\SessionExpired::ERROR_CODE) {
            return new Exception\SessionExpired($message, $code);
        }

        if ($code === Exception\RequestRefused::ERROR_CODE) {
            return new Exception\RequestRefused($message, $code);
        }

        if ($code === Exception\ResourceNotFound::ERROR_CODE) {
            return new Exception\ResourceNotFound($message, $code);
        }

        if ($code === Exception\UnsupportedFormat::ERROR_CODE) {
            return new Exception\UnsupportedFormat($message, $code);
        }

        return new Exception\Salesforce($message, $code);
    }

    /**
     * Fetch the next QueryResults for a query that has multiple pages worth of returned records
     *
     * @param QueryResults $queryResults
     *
     * @return QueryResults
     * @throws Exception\SalesforceNoResults
     */
    public function getNextQueryResults(QueryResults $queryResults)
    {
        $basePath = $this->getPathFromUrl($this->apiBaseUrl);
        $nextRecordsRelativePath = str_replace($basePath, '', $queryResults->getNextQuery());

        return $this->callQueryApiAndGetQueryResults($nextRecordsRelativePath);
    }

    protected function getPathFromUrl($url)
    {
        $parts = parse_url($url);

        return $parts['path'];
    }

    /**
     * Get a record Id via a call to the Query API
     *
     * @param $queryString
     *
     * @return string The Id field of the first result of the query
     * @throws Exception\SalesforceNoResults
     */
    public function queryForId($queryString)
    {
        $jsonResponse = $this->query($queryString);

        return $jsonResponse['records'][0]['Id'];
    }

    /**
     * Makes a call to the Query API
     *
     * @param string $queryToRun
     * @param array  $parameters Parameters to bind
     *
     * @return QueryIterator
     * @throws Exception\SalesforceNoResults
     */
    public function query($queryToRun, $parameters = [])
    {
        $apiPath = $this->buildApiPathForQuery('query', $queryToRun, $parameters);
        $queryResults = $this->callQueryApiAndGetQueryResults($apiPath);

        return new QueryIterator($this, $queryResults);
    }

    /**
     * Get an Account by Account Id
     *
     * @param string        $accountId
     * @param string[]|null $fields The Account fields to return. Default Name & BillingCountry
     *
     * @return mixed The API output, converted from JSON to an associative array
     * @throws Exception\SalesforceNoResults
     */
    public function getAccount($accountId, $fields = null)
    {
        $accountId = urlencode($accountId);
        $defaultFields = ['Name', 'BillingCountry'];
        if (empty($fields)) {
            $fields = $defaultFields;
        }
        $fields = implode(',', $fields);
        $response = $this->get("sobjects/Account/{$accountId}?fields={$fields}");
        $jsonResponse = json_decode($response, true);

        if (!isset($jsonResponse['attributes']) || empty($jsonResponse['attributes'])) {
            $message = 'No results found';
            $this->log->info($message, ['response' => $response]);
            throw new Exception\SalesforceNoResults($message);
        }

        return $jsonResponse;
    }

    /**
     * Get a Contact by Account Id
     *
     * @param string        $accountId
     * @param string[]|null $fields The Contact fields to return. Default FirstName, LastName and MailingCountry
     *
     * @return mixed The API output, converted from JSON to an associative array
     * @throws Exception\SalesforceNoResults
     */
    public function getContact($accountId, $fields = null)
    {
        $accountId = urlencode($accountId);
        $defaultFields = ['FirstName', 'LastName', 'MailingCountry'];
        if (empty($fields)) {
            $fields = $defaultFields;
        }
        $fields = implode(',', $fields);
        $response = $this->get("sobjects/Contact/{$accountId}?fields={$fields}");
        $jsonResponse = json_decode($response, true);

        if (!isset($jsonResponse['attributes']) || empty($jsonResponse['attributes'])) {
            $message = 'No results found';
            $this->log->info($message, ['response' => $response]);
            throw new Exception\SalesforceNoResults($message);
        }

        return $jsonResponse;
    }

    /**
     * Creates a new Account using the provided field values
     *
     * @param string[] $fields The field values to set on the new Account
     *
     * @return string The Id of the newly created Account
     * @throws Exception\Salesforce
     */
    public function newAccount($fields)
    {
        return $this->newSalesforceObject("Account", $fields);
    }

    /**
     * Creates a new Salesforce Object using the provided field values
     *
     * @param string   $object The name of the salesforce object.  i.e. Account or Contact
     * @param string[] $fields The field values to set on the new Salesforce Object
     *
     * @return string The Id of the newly created Salesforce Object
     * @throws Exception\Salesforce
     */
    public function newSalesforceObject($object, $fields)
    {
        $this->log->info('Creating Salesforce object', [
            'object' => $object,
            'fields' => $fields,
        ]);
        $fields = json_encode($fields);
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $response = $this->post("sobjects/{$object}/", $headers, $fields);
        $jsonResponse = json_decode($response, true);

        if (!isset($jsonResponse['id']) || empty($jsonResponse['id'])) {
            $message = 'Error while creating account';
            $this->log->info($message, ['response' => $response]);
            throw new Exception\Salesforce($message);
        }

        return $jsonResponse['id'];
    }

    protected function post($path, $headers = [], $body = null, $options = [])
    {
        return $this->requestWithAutomaticReauthorize('POST', $path, $headers, $body, $options);
    }

    /**
     * Creates a new Contact using the provided field values
     *
     * @param string[] $fields The field values to set on the new Contact
     *
     * @return string The Id of the newly created Contact
     * @throws Exception\Salesforce
     */
    public function newContact($fields)
    {
        return $this->newSalesforceObject("Contact", $fields);
    }

    /**
     * Updates an Account using the provided field values
     *
     * @param string   $id     The Account Id of the Account to update
     * @param string[] $fields The fields to update
     *
     * @return bool
     */
    public function updateAccount($id, $fields)
    {
        return $this->updateSalesforceObject("Account", $id, $fields);
    }

    /**
     * Updates an Salesforce Object using the provided field values
     *
     * @param string   $object The name of the salesforce object.  i.e. Account or Contact
     * @param string   $id     The Id of the Salesforce Object to update
     * @param string[] $fields The fields to update
     *
     * @return bool
     */
    public function updateSalesforceObject($object, $id, $fields)
    {
        $this->log->info(
            'Updating Salesforce object',
            [
                'id'     => $id,
                'object' => $object,
            ]
        );
        $id = urlencode($id);
        $fields = json_encode($fields);
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $this->patch("sobjects/{$object}/{$id}", $headers, $fields);

        return true;
    }

    protected function patch($path, $headers = [], $body = null, $options = [])
    {
        return $this->requestWithAutomaticReauthorize('PATCH', $path, $headers, $body, $options);
    }

    /**
     * Updates an Contact using the provided field values
     *
     * @param string   $id     The Contact Id of the Contact to update
     * @param string[] $fields The fields to update
     *
     * @return bool
     */
    public function updateContact($id, $fields)
    {
        return $this->updateSalesforceObject("Contact", $id, $fields);
    }

    /**
     * Gets the valid fields for Accounts via the describe API
     *
     * @return mixed The API output, converted from JSON to an associative array
     */
    public function getAccountFields()
    {
        return $this->getFields('Account');
    }

    /**
     * Gets the valid fields for a given Salesforce Object via the describe API
     *
     * @param string $object The name of the salesforce object.  i.e. Account or Contact
     *
     * @return mixed The API output, converted from JSON to an associative array
     */
    public function getFields($object)
    {
        $response = $this->get("sobjects/{$object}/describe");
        $jsonResponse = json_decode($response, true);
        $fields = [];
        foreach ($jsonResponse['fields'] as $row) {
            $fields[] = ['label' => $row['label'], 'name' => $row['name']];
        }

        return $fields;
    }

    /**
     * Gets the valid fields for Contacts via the describe API
     *
     * @return mixed The API output, converted from JSON to an associative array
     */
    public function getContactFields()
    {
        return $this->getFields('Contact');
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->log = $logger;
    }
}
