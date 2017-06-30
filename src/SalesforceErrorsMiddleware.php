<?php

namespace Gmo\Salesforce;

use Gmo\Salesforce\Exception;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class SalesforceErrorsMiddleware
{
    /** @var callable */
    private $nextHandler;

    /**
     * Constructor.
     *
     * @param callable $nextHandler
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    public static function create()
    {
        return function($handler) {
            return new static($handler);
        };
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        $fn = $this->nextHandler;

        return $fn($request, $options)->then(
            function (ResponseInterface $response) use ($request) {
                $code = $response->getStatusCode();
                if ($code < 400) {
                    return $response;
                }

                $body = $response->getBody()->getContents();

                try {
                    $json = \GuzzleHttp\json_decode($body, true);
                } catch (\InvalidArgumentException $e) {
                    throw new Exception\Salesforce($e->getMessage(), 0, $e);
                }

                $message = isset($json[0]['message']) ? $json[0]['message'] : $body;

                $fields = isset($json[0]['fields']) ? $json[0]['fields'] : [];
                if (!empty($fields)) {
                    throw new Exception\SalesforceFields($message, $code, $fields);
                }

                switch ($code) {
                    case Exception\SessionExpired::ERROR_CODE:
                        throw new Exception\SessionExpired($message, $code);
                    case Exception\RequestRefused::ERROR_CODE:
                        throw new Exception\SessionExpired($message, $code);
                    case Exception\ResourceNotFound::ERROR_CODE:
                        throw new Exception\SessionExpired($message, $code);
                    case Exception\UnsupportedFormat::ERROR_CODE:
                        throw new Exception\SessionExpired($message, $code);
                    default:
                        throw new Exception\Salesforce($message, $code);
                }

                // TODO: Log error?
            }
        );
    }
}
