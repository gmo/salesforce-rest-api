<?php
declare(strict_types=1);

namespace Gmo\Salesforce\Authentication;

use GuzzleHttp as Http;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Gmo\Salesforce\Exception;

class PasswordAuthentication implements AuthenticationInterface, LoggerAwareInterface
{
    const SALESFORCE_SANDBOX_LOGIN_URL = 'https://test.salesforce.com/';

    const SALESFORCE_LOGIN_URL = 'https://login.salesforce.com/';

    const LOGIN_INSTANCES = [
        self::SALESFORCE_SANDBOX_LOGIN_URL,
        self::SALESFORCE_LOGIN_URL
    ];

    const LOGIN_URL = '/services/oauth2/token';

    /** @var LoggerInterface */
    protected $log;
    /** @var string */
    protected $clientId;
    /** @var string */
    protected $clientSecret;
    /** @var string */
    protected $username;
    /** @var string */
    protected $password;
    /** @var AuthenticationBagInterface|null */
    protected $responseBag;
    /** @var Http\ClientInterface */
    private $http;

    public function __construct(
        $clientId,
        $clientSecret,
        $username,
        $password,
        $loginApiUrl = self::SALESFORCE_SANDBOX_LOGIN_URL,
        Http\ClientInterface $guzzle = null
    ) {
        $this->log = new NullLogger();
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username = $username;
        $this->password = $password;
        $this->http = $guzzle ?? new Http\Client(['base_uri' => $loginApiUrl]);
    }

    public function getAccessToken(): AuthenticationBagInterface
    {
        if ($this->responseBag) {
            return $this->responseBag;
        }

        $postFields = [
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $this->username,
            'password' => $this->password,
        ];
        $response = $this->http->post(self::LOGIN_URL, ['form_params' => $postFields]);
        $responseBody = $response->getBody()->getContents();
        $jsonResponse = json_decode($responseBody, true);

        if ($response->getStatusCode() !== 200) {
            $message = $responseBody;
            if (isset($jsonResponse['error_description'])) {
                $message = $jsonResponse['error_description'];
            }
            $this->log->error($message, array('response' => $responseBody));
            throw new Exception\SalesforceAuthentication($message);
        }

        if (!isset($jsonResponse['access_token']) || empty($jsonResponse['access_token'])) {
            $message = 'Access token not found';
            $this->log->error($message, array('response' => $responseBody));
            throw new Exception\SalesforceAuthentication($message);
        }

        $this->responseBag = new AuthenticationBag($jsonResponse);

        return $this->responseBag;
    }

    public function invalidateAccessToken()
    {
        $this->responseBag = null;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->log = $logger;
    }

}
