<?php

namespace Gmo\Salesforce;

use Gmo\Salesforce\Authentication\AuthenticationInterface;
use Gmo\Salesforce\Exception\SessionExpired;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

class SalesforceAuthMiddleware
{
    /** @var callable */
    private $nextHandler;
    /** @var AuthenticationInterface */
    private $authentication;

    /**
     * Constructor.
     *
     * @param callable                $nextHandler
     * @param AuthenticationInterface $authentication
     */
    public function __construct(callable $nextHandler, AuthenticationInterface $authentication)
    {
        $this->nextHandler = $nextHandler;
        $this->authentication = $authentication;
    }

    public static function create(AuthenticationInterface $authentication)
    {
        return function($handler) use ($authentication) {
            return new static($handler, $authentication);
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
        $token = $this->authentication->getAccessToken();
        $request = $request->withHeader('Authorization', "Bearer $token");

        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        }

        $fn = $this->nextHandler;

        return $fn($request, $options)->otherwise(
            function ($reason) use ($request, $options) {
                if ($reason instanceof SessionExpired && ++$options['retries'] < 3) {
                    $this->authentication->invalidateAccessToken();
                    return $this($request, $options);
                }
                return \GuzzleHttp\Promise\rejection_for($reason);
            }
        );
    }
}
