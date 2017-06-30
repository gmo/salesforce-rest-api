<?php
namespace Gmo\Salesforce;

use Gmo\Salesforce\Authentication\AuthenticationInterface;
use Gmo\Salesforce\Exception;
use GuzzleHttp;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

class BasicClient
{
    const SALESFORCE_API_URL_PATTERN = 'https://{region}.salesforce.com/services/data/{version}/';
    const DATETIME_FORMAT = 'yyyy-MM-ddTHH:mm:ss+00:00';

    /** @var GuzzleHttp\Client */
    protected $guzzle;
    /** @var AuthenticationInterface */
    protected $authentication;

    /** @var QueryCompiler */
    private $queryCompiler;

    /**
     * Constructor.
     *
     * @param AuthenticationInterface $authentication
     * @param string                  $region  The region to use for the Salesforce API.  i.e. na5 or cs30
     * @param string                  $version The version of the API to use.  i.e. v31.0
     */
    public function __construct(
        AuthenticationInterface $authentication,
        $region,
        $version = 'v31.0'
    ) {
        $baseUri = str_replace(
            ['{region}', '{version}'],
            [$region, $version],
            static::SALESFORCE_API_URL_PATTERN
        );
        $stack = HandlerStack::create();
        $stack->push(SalesforceAuthMiddleware::create($authentication), 'salesforce');
        $stack->push(SalesforceErrorsMiddleware::create(), 'salesforce_errors');

        $this->guzzle = new GuzzleHttp\Client([
            'base_uri' => $baseUri,
            'handler' => $stack,
        ]);

        $this->queryCompiler = new QueryCompiler();
    }

    public function limits()
    {
        return $this->fetch('GET', 'limits');
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
     * Get a record Id via a call to the Query API
     *
     * @param string $query
     *
     * @return string The Id field of the first result of the query
     */
    public function queryForId($query)
    {
        foreach ($this->query($query) as $record) {
            return $record['Id'];
        }

        return null;
    }

    public function get($object, $accountId, array $fields)
    {
        return $this->fetch('GET', "sobjects/$object/$accountId", [
            'query' => [
                'fields' => implode(',', $fields),
            ],
        ]);
    }

    /**
     * Creates a new Salesforce Object using the provided field values
     *
     * @param string $objectName The name of the salesforce object.
     * @param array  $record     The record values to set on the new Salesforce Object
     *
     * @return string The ID of the newly created Salesforce Object
     *
     * @throws Exception\Salesforce
     */
    public function create($objectName, $record)
    {
        $result = $this->fetch('POST', "sobjects/$objectName", [
            'json' => $record,
        ]);

        if (empty($result['id'])) {
            throw new Exception\Salesforce('Error while creating account');
        }

        return $result['id'];
    }

    /**
     * Updates an Salesforce Object using the provided field values
     *
     * @param string   $objectName The name of the salesforce object.  i.e. Account or Contact
     * @param string   $id         The Id of the Salesforce Object to update
     * @param array $record     The fields to update
     */
    public function update($objectName, $id, $record)
    {
        $this->fetch('PATCH', "sobjects/$objectName/$id", [
            'json' => $record,
        ]);
    }

    public function upsertExternal($objectName, $externalFieldName, $externalId, $record)
    {
        $this->fetch('PATCH', "sobjects/$objectName/$externalFieldName/$externalId", [
            'json' => $record,
        ]);
    }

    public function delete($objectName, $id)
    {
        $this->fetch('DELETE', "sobjects/$objectName/$id");
    }

    public function getObjects()// describe global?
    {
        return $this->fetch('GET', 'sobjects');
    }

    public function basicInformation($objectName)
    {
        return $this->fetch('GET', "sobjects/$objectName");
    }

    public function describe($objectName)
    {
        return $this->fetch('GET', "sobjects/$objectName/describe/");
    }

    public function getDeleted($objectName, \DateTime $start, \DateTime $end)
    {
        return $this->fetch('GET', "sobjects/$objectName/deleted/", [
            'query' => [
                'start' => $start->format(static::DATETIME_FORMAT),
                'end' => $end->format(static::DATETIME_FORMAT),
            ],
        ]);
    }

    public function getUpdated($objectName, \DateTime $start, \DateTime $end)
    {
        return $this->fetch('GET', "sobjects/$objectName/updated/", [
            'query' => [
                'start' => $start->format(static::DATETIME_FORMAT),
                'end' => $end->format(static::DATETIME_FORMAT),
            ],
        ]);
    }

    public function describeLayout($objectName, $recordTypeId = null)
    {
        return $this->fetch('GET', "sobjects/$objectName/describe/layouts/$recordTypeId");
    }

    public function recent($limit = 200)
    {
        return $this->fetch('GET', 'recent', [
            'query' => [
                'limit' => $limit,
            ],
        ]);
    }

    public function search($query, $parameters = [])
    {
        return $this->doQuery('search', $query, $parameters);
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
}
