<?php

namespace Gmo\Salesforce;

use JmesPath\Env as JmesPath;
use Psr\Http\Message\ResponseInterface;

class Result implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var ResponseInterface */
    private $response;
    /** @var bool */
    private $parsed;
    /** @var array */
    private $data;

    /**
     * Constructor.
     *
     * @param ResponseInterface $response
     */
    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    public function search($expression)
    {
        return JmesPath::search($expression, $this->toArray());
    }

    public function has($key)
    {
        $this->parse();

        return isset($this->data[$key]);
    }

    public function get($key, $default = null)
    {
        $this->parse();

        return isset($this->data[$key]) ? $this->data[$key] : $default;
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        $this->parse();

        return count($this->data);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        $this->parse();

        return new \ArrayIterator($this->data);
    }

    /**
     * This method returns a reference to the variable to allow for indirect
     * array modification (e.g., $foo['bar']['baz'] = 'qux').
     *
     * @param $offset
     *
     * @return mixed|null
     */
    public function & offsetGet($offset)
    {
        $this->parse();

        if (isset($this->data[$offset])) {
            return $this->data[$offset];
        }

        $value = null;
        return $value;
    }

    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    public function offsetSet($offset, $value)
    {
        $this->parse();

        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        $this->parse();

        unset($this->data[$offset]);
    }

    public function toArray()
    {
        $this->parse();

        return $this->data;
    }

    protected function parse()
    {
        if (!$this->parsed) {
            $this->data = (array) \GuzzleHttp\json_decode($this->response->getBody(), true);
            $this->parsed = true;
        }
    }
}
