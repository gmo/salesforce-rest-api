<?php
namespace Gmo\Salesforce;

use GuzzleHttp\Psr7\Uri;
use JmesPath\Env as JmesPath;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class QueryIterator implements \Countable, \Iterator
{
    /** @var BasicClient */
    protected $client;
    /** @var RequestInterface */
    protected $firstRequest;
    /** @var RequestInterface */
    protected $request;
    /** @var int */
    protected $totalSize;
    /** @var UriInterface|null */
    protected $nextPageUri;

    protected $fetched = false;
    protected $records = [];

    /** @var int */
    protected $position = -1;

    /**
     * @param BasicClient  $client
     * @param RequestInterface $request
     */
    public function __construct(BasicClient $client, RequestInterface $request)
    {
        $this->client = $client;
        $this->request = $this->firstRequest = $request;
    }

    public function search($expression)
    {
        foreach ($this as $record) {
            yield JmesPath::search($expression, $record);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return isset($this->records[$this->position]);
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return $this->records[$this->position];
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->position;
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        $this->fetch();

        if (++$this->position < count($this->records)) {
            return;
        }

        if (!$this->nextPageUri) {
            $this->position = -1;
            return;
        }

        // Fetch next
        $this->request = $this->request->withUri($this->nextPageUri);
        $this->fetched = false;
        $this->fetch();
        $this->position = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->position = -1;
        $this->request = $this->firstRequest;
        $this->fetched = false;
        $this->next();
    }

    protected function fetch()
    {
        if (!$this->fetched) {
            $this->doFetch();
            $this->fetched = true;
        }
    }

    protected function doFetch()
    {
        $result = $this->client->fetchRequest($this->request);

        $this->records = $result->get('records', []);
        $this->totalSize = $result->get('totalSize', 0);
        $this->nextPageUri = $result->has('nextRecordsUrl') ? new Uri($result['nextRecordsUrl']) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        $this->fetch();
        return $this->totalSize;
    }
}
