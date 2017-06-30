<?php
namespace Gmo\Salesforce;

use Gmo\Salesforce\Authentication\AuthenticationInterface;
use Gmo\Salesforce\Exception;
use GuzzleHttp;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Client implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SALESFORCE_API_URL_PATTERN = 'https://{region}.salesforce.com/services/data/{version}/';

    /** @var GuzzleHttp\Client */
    protected $guzzle;
    /** @var AuthenticationInterface */
    protected $authentication;

    /** @var QueryCompiler */
    private $queryCompiler;

    /**
     * Creates a Salesforce REST API client that uses username-password authentication
     *
     * @param GuzzleHttp\Client       $guzzle
     * @param LoggerInterface         $logger
     */
    public function __construct(
        GuzzleHttp\Client $guzzle,
        LoggerInterface $logger = null
    ) {
        $this->guzzle = $guzzle;
        $this->setLogger($logger ?: new NullLogger());
        $this->queryCompiler = new QueryCompiler();
    }

    /**
     * Creates a Salesforce REST API client that uses username-password authentication
     *
     * @param AuthenticationInterface $authentication
     * @param string                  $region  The region to use for the Salesforce API.  i.e. na5 or cs30
     * @param string                  $version The version of the API to use.  i.e. v31.0
     * @param LoggerInterface         $logger
     *
     * @return Client
     */
    public static function create(
        AuthenticationInterface $authentication,
        $region,
        $version = 'v31.0',
        LoggerInterface $logger = null
    ) {
        $baseUri = str_replace(
            ['{region}', '{version}'],
            [$region, $version],
            static::SALESFORCE_API_URL_PATTERN
        );
        $stack = HandlerStack::create();
        $stack->push(SalesforceAuthMiddleware::create($authentication), 'salesforce');
        $stack->push(SalesforceErrorsMiddleware::create(), 'salesforce_errors');

        $client = new GuzzleHttp\Client([
            'base_uri' => $baseUri,
            'handler' => $stack,
        ]);

        return new static($client, $logger);
    }

    /**
     * @param RequestInterface $request
     *
     * @return Result
     */
    public function fetchRequest(RequestInterface $request)
    {
        $response = $this->guzzle->send($request);

        return new Result($response);
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $options
     *
     * @return Result
     */
    public function fetch($method, $path, $options = [])
    {
        $response = $this->guzzle->request($method, $path, $options);

        return new Result($response);
    }

    /**
     * Makes a call to the Query API
     *
     * @param string $query
     * @param array  $parameters Parameters to bind
     *
     * @return QueryIterator
     */
    public function query($query, $parameters = [])
    {
        return $this->doQuery('query', $query, $parameters);
    }

    /**
     * Makes a call to the QueryAll API
     *
     * @param string $query
     * @param array  $parameters Parameters to bind
     *
     * @return QueryIterator
     */
    public function queryAll($query, $parameters = [])
    {
        return $this->doQuery('queryAll', $query, $parameters);
    }

    /**
     * @param string $path
     * @param string $query
     * @param array  $parameters
     *
     * @return QueryIterator
     */
    protected function doQuery($path, $query, $parameters = [])
    {
        $uri = new GuzzleHttp\Psr7\Uri($path);
        $uri = $uri->withQuery(GuzzleHttp\Psr7\build_query([
            'q' => $this->queryCompiler->compile($query, $parameters),
        ]));
        $request = new GuzzleHttp\Psr7\Request('GET', $uri);

        return new QueryIterator($this, $request);
    }

    public function getObject($object, $accountId, array $fields)
    {
        $result = $this->fetch('GET', "sobjects/$object/$accountId", [
            'query' => [
                'fields' => implode(',', $fields),
            ],
        ]);

        if (empty($result['attributes'])) {
            $message = 'No results found';
            $this->logger->info($message, ['response' => $result->getResponse()]);
            throw new Exception\SalesforceNoResults($message);
        }

        return $result;
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
    public function newObject($object, $fields)
    {
        $this->logger->info('Creating Salesforce object', [
            'object' => $object,
            'fields' => $fields,
        ]);

        $result = $this->fetch('POST', "sobjects/$object/", [
            'json' => $fields,
        ]);

        if (empty($result['id'])) {
            $message = 'Error while creating account';
            $this->logger->info($message, ['response' => $result->getResponse()]);
            throw new Exception\Salesforce($message);
        }

        return $result['id'];
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
    public function updateObject($object, $id, $fields)
    {
        $this->logger->info('Updating Salesforce object', [
            'id'     => $id,
            'object' => $object,
        ]);

        $this->fetch('PATCH', "sobjects/$object/$id", [
            'json' => $fields,
        ]);

        return true;
    }

    /**
     * Gets the valid fields for a given Salesforce Object via the describe API
     *
     * @param string $object The name of the salesforce object.  i.e. Account or Contact
     *
     * @return array The API output, converted from JSON to an associative array
     */
    public function getFields($object)
    {
        $json = $this->fetch('GET', "sobjects/{$object}/describe");
        $fields = [];
        foreach ($json['fields'] as $row) {
            $fields[] = ['label' => $row['label'], 'name' => $row['name']];
        }

        return $fields;
    }

    /**
     * Get a record Id via a call to the Query API
     *
     * @param string $query
     *
     * @return string The Id field of the first result of the query
     * @throws Exception\SalesforceNoResults
     */
    public function queryForId($query)
    {
        foreach ($this->query($query) as $record) {
            return $record['Id'];
        }
    }

    /**
     * Get an Account by Account Id
     *
     * @param string   $accountId
     * @param string[] $fields The Account fields to return. Default Name & BillingCountry
     *
     * @return mixed The API output, converted from JSON to an associative array
     * @throws Exception\SalesforceNoResults
     */
    public function getAccount($accountId, $fields = [])
    {
        return $this->getObject('Account', $accountId, $fields ?: ['Name', 'BillingCountry']);
    }

    /**
     * Get a Contact by Account Id
     *
     * @param string   $accountId
     * @param string[] $fields The Contact fields to return. Default FirstName, LastName and MailingCountry
     *
     * @return mixed The API output, converted from JSON to an associative array
     * @throws Exception\SalesforceNoResults
     */
    public function getContact($accountId, $fields = [])
    {
        return $this->getObject('Contact', $accountId, $fields ?: ['FirstName', 'LastName', 'MailingCountry']);
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
        return $this->newObject('Account', $fields);
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
        return $this->updateObject('Contact', $id, $fields);
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
        return $this->newObject('Contact', $fields);
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
        return $this->updateObject('Account', $id, $fields);
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
     * Gets the valid fields for Contacts via the describe API
     *
     * @return array The API output, converted from JSON to an associative array
     */
    public function getContactFields()
    {
        return $this->getFields('Contact');
    }
}
