<?php
declare(strict_types=1);

namespace Gmo\Salesforce;

use Doctrine\Common\Collections\ArrayCollection;
use Gmo\Salesforce\Authentication\AuthenticationInterface;
use Gmo\Salesforce\Exception;
use Gmo\Salesforce\Sobject\Field;
use Gmo\Salesforce\Sobject\Sobject;
use GuzzleHttp as Http;
use GuzzleHttp\Exception\BadResponseException;
use MongoDB\Driver\Query;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Client implements LoggerAwareInterface
{
    const SALESFORCE_API_URL = '/services/data/';
    const SALESFORCE_DESCRIBE_PATTERN = '/sobjects/%s/describe';
    const SALESFORCE_SOBJECTS = '/sobjects';
    const SALESFORCE_POST_PATTERN = '/sobjects/%s';
    const SALESFORCE_PATCH_PATTERN = '/sobjects/%s/%s';

    const SALESFORCE_QUERY = '/query';
    const SALESFORCE_QUERYALL = '/queryAll';

    /** @var LoggerInterface */
    protected $log;
    /** @var  Http\Client */
    protected $http;
    /** @var AuthenticationInterface */
    protected $authentication;
    /** @var string */
    protected $apiVersion;
    /** @var array */
    protected $httpHeader;

    /**
     * Creates a Salesforce REST API client that uses username-password authentication
     * @param AuthenticationInterface $authentication
     * @param Http\ClientInterface $guzzle
     * @param string $apiVersion The version of the API to use.  i.e. v31.0
     * @param LoggerInterface $log
     */
    public function __construct(
        AuthenticationInterface $authentication,
        Http\ClientInterface $guzzle = null,
        string $apiVersion = 'last',
        LoggerInterface $log = null
    ) {
        $this->log = $log ?? new NullLogger();
        $this->apiVersion = $apiVersion;
        $this->authentication = $authentication;
        $this->http = $guzzle?? new Http\Client();
    }

    /**
     * Makes a call to the QueryAll API
     * @param string $queryToRun
     * @param array $parameters Parameters to bind
     * @return QueryIterator
     * @throws Exception\SalesforceNoResults
     */
    public function queryAll(string $queryToRun, array $parameters = []): QueryIterator
    {
        $apiPath = $this->buildApiPathForQuery(self::SALESFORCE_QUERYALL, $queryToRun, $parameters);
        $queryResults = $this->callQueryApiAndGetQueryResults($apiPath);

        return new QueryIterator($this, $queryResults);
    }

    /**
     * @param string $queryMethod
     * @param string $queryToRun
     * @param array $parameters
     * @return string
     */
    protected function buildApiPathForQuery(string $queryMethod, string $queryToRun, array $parameters = []): string
    {
        if (!empty($parameters)) {
            $queryToRun = $this->bindParameters($queryToRun, $parameters);
        }

        $queryToRun = urlencode($queryToRun);

        return '/' . $queryMethod .  '/?q=' . $queryToRun;
    }

    /**
     * @param string $queryString
     * @param array $parameters
     * @return string
     */
    protected function bindParameters(string $queryString, array $parameters): string
    {
        $paramKeys = array_keys($parameters);
        $isNumericIndexes = array_reduce(array_map('is_int', $paramKeys), function ($carry, $item) {
            return $carry && $item;
        }, true);

        if ($isNumericIndexes) {
            $searchArray = array_fill(0, count($paramKeys), '?');
            $replaceArray = array_values($parameters);
        } else {
            // NOTE: krsort here will prevent the scenario of a replacement of array('foo' => 1, 'foobar' => 2) on string "Hi :foobar" resulting in "Hi 1bar"
            krsort($parameters);
            $searchArray = array_map(function ($string) {
                return ':' . $string;
            }, array_keys($parameters));
            $replaceArray = array_values($parameters);
        }

        $replaceArray = $this->addQuotesToStringReplacements($replaceArray);
        $replaceArray = $this->replaceBooleansWithStringLiterals($replaceArray);

        return str_replace($searchArray, $replaceArray, $queryString);
    }

    protected function addQuotesToStringReplacements(array $replacements): array
    {
        foreach ($replacements as $key => $val) {
            if (is_string($val) && !$this->isSalesforceDateFormat($val)) {
                $val = str_replace('\'', '\\\'', $val);
                $replacements[$key] = '\'' . $val . '\'';
            }
        }

        return $replacements;
    }

    protected function isSalesforceDateFormat($string)
    {
        return preg_match('/\d+[-]\d+[-]\d+[T]\d+[:]\d+[:]\d+[Z]/', $string) === 1;
    }

    protected function replaceBooleansWithStringLiterals(array $replacements): array
    {
        return array_map(function ($val) {
            if (!is_bool($val)) {
                return $val;
            }
            return $val ? 'true' : 'false';
        }, $replacements);
    }

    /**
     * Call the API for the provided query API path, handle No Results, and return a QueryResults object
     * @param $apiPath
     * @return QueryResults
     * @throws Exception\SalesforceNoResults
     */
    protected function callQueryApiAndGetQueryResults($apiPath)
    {
        $response = $this->get($this->getUrl($apiPath));
        $jsonResponse = json_decode($response, true);

        if (!isset($jsonResponse['totalSize']) || empty($jsonResponse['totalSize'])) {
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

    protected function get(string $url = '')
    {
        return $this->requestWithAutomaticReauthorize('GET', $url);
    }

    protected function requestWithAutomaticReauthorize(
        string $type,
        string $path,
        array $headers = [],
        $body = null
    ) {
        try {
            return $this->request($type, $path, $headers, $body);
        } catch (Exception\SessionExpired $e) {
            $this->authentication->invalidateAccessToken();
            $this->populateToken();

            return $this->request($type, $path, $headers, $body);
        }
    }

    protected function request(string $type, string $path, array $headers = [], $body = null)
    {
        $this->initialize();

        $options = [
            'headers' => array_merge($headers, $this->httpHeader),
            'form_params' => $body
        ];
        if (isset($options['headers']['Content-Type']) && $options['headers']['Content-Type'] === 'application/json') {
            $options['json'] = $options['form_params'];
            unset($options['form_params']);
        }

        try {
            $request = $this->getHttp()->request($type, $path, $options);
            $responseBody = $request->getBody()->getContents();
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $message = $responseBody = $response->getBody()->getContents();
            $errorCode = $response->getStatusCode();

            $jsonResponse = json_decode($responseBody, true);
            if (isset($jsonResponse[0]) && isset($jsonResponse[0]['message'])) {
                $message = $jsonResponse[0]['message'];
            }

            $fields = [];
            if (isset($jsonResponse[0]) && isset($jsonResponse[0]['fields'])) {
                $fields = $jsonResponse[0]['fields'];
            }
            $this->log->error($message, array(
                'response' => $responseBody,
                'fields' => $fields,
            ));

            throw $this->getExceptionForSalesforceError($message, $errorCode, $fields);
        }

        return $responseBody;
    }

    /**
     * Lazy loads the access token by running authentication and setting the access token into the $this->guzzle headers
     */
    protected function initialize()
    {
        if ($this->httpHeader['Authorization']) {
            return;
        }

        $this->populateToken();
    }

    protected function populateToken()
    {
        $accessToken = $this->authentication->getAccessToken();
        $this->httpHeader['Authorization'] = $accessToken->getTokenType() . ' ' . $accessToken->getToken();
    }

    /**
     * @param string $message
     * @param int $code
     * @param array $fields
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
     * @param QueryResults $queryResults
     * @return QueryResults
     * @throws Exception\SalesforceNoResults
     */
    public function getNextQueryResults(QueryResults $queryResults)
    {
        $basePath = parse_url($this->getApiBaseUrl())['path'];
        $nextRecordsRelativePath = str_replace($basePath, '', $queryResults->getNextQuery());

        return $this->callQueryApiAndGetQueryResults($nextRecordsRelativePath);
    }

    /**
     * Makes a call to the Query API
     * @param string $queryToRun
     * @param array $parameters Parameters to bind
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
     * Creates a new Salesforce Object using the provided field values
     * @param string $object The name of the salesforce object.  i.e. Account or Contact
     * @param string[] $fields The field values to set on the new Salesforce Object
     * @return string The Id of the newly created Salesforce Object
     * @throws Exception\Salesforce
     */
    public function create(string $object, array $fields)
    {
        $this->log->info('Creating Salesforce object', ['object' => $object, 'fields' => $fields]);
        $headers = ['Content-Type' => 'application/json'];

        $url = sprintf(self::SALESFORCE_POST_PATTERN, $object);
        $response = $this->post($this->getUrl($url), $headers, $fields);
        $jsonResponse = json_decode($response, true);

        if (!isset($jsonResponse['id']) || empty($jsonResponse['id'])) {
            $message = 'Error while creating account';
            $this->log->info($message, array('response' => $response));
            throw new Exception\Salesforce($message);
        }

        return $jsonResponse['id'];
    }

    protected function post(string $path, array $headers = [], $body = null)
    {
        return $this->requestWithAutomaticReauthorize('POST', $path, $headers, $body);
    }

    /**
     * Updates an Salesforce Object using the provided field values
     * @param string $object The name of the salesforce object.  i.e. Account or Contact
     * @param string $id The Id of the Salesforce Object to update
     * @param string[] $fields The fields to update
     * @return bool
     */
    public function update(string $object, string $id, ArrayCollection $fields)
    {
        $this->log->info('Updating Salesforce object', ['id' => $id, 'object' => $object]);
        $id = urlencode($id);
        $fields = json_encode($fields);
        $headers = ['Content-Type' => 'application/json'];

        $url = sprintf(self::SALESFORCE_PATCH_PATTERN, $object, $id);
        $this->patch($this->getUrl($url), $headers, $fields);

        return true;
    }

    protected function patch(string $url, array $headers = [], string $body = null)
    {
        return $this->requestWithAutomaticReauthorize('PATCH', $url, $headers, $body);
    }

    public function getSobjects()
    {

        $url = sprintf(self::SALESFORCE_SOBJECTS);
        $jsonResponse = json_decode($this->get($this->getUrl($url)), true);
        $fields = new ArrayCollection();
        foreach ($jsonResponse['sobjects'] as $row) {
            $fields->add(new Sobject($row));
        }
        return $fields;
    }

    /**
     * Gets the valid fields for a given Salesforce Object via the describe API
     * @param string $object The name of the salesforce object.  i.e. Account or Contact
     * @return mixed The API output, converted from JSON to an associative array
     */
    public function getFields(string $object): ArrayCollection
    {
        $url = sprintf(self::SALESFORCE_DESCRIBE_PATTERN, $object);
        $jsonResponse = json_decode($this->get($this->getUrl($url)), true);
        $fields = new ArrayCollection();
        foreach ($jsonResponse['fields'] as $row) {
            $fields->add(new Field($row));
        }
        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->log = $logger;
    }

    protected function getApiBaseUrl(): string
    {
        return $this->authentication->getAccessToken()->getInstanceUrl() .
            self::SALESFORCE_API_URL .
            $this->getApiVersion();
    }

    protected function getUrl(string $url): string
    {
        return $this->getApiBaseUrl() . $url;
    }

    protected function getHttp() : Http\Client
    {
        if ('last' === $this->apiVersion) {
            $this->getApiVersion();
            $this->http->__construct(['base_uri' => $this->getApiBaseUrl()]);
        }
        return $this->http;
    }

    protected function getApiVersion(): string
    {
        if ('last' !== $this->apiVersion) {
            return $this->apiVersion;
        }
        $this->http->__construct([
            'base_uri' => $this->authentication->getAccessToken()->getInstanceUrl() . self::SALESFORCE_API_URL
        ]);
        $versions = json_decode($this->http->get('')->getBody()->getContents(), true);
        $this->apiVersion = 'v' . end($versions)['version'];
        return $this->apiVersion;
    }
}
