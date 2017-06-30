<?php
namespace Gmo\Salesforce\Authentication;

use Gmo\Salesforce\AccessToken;
use GuzzleHttp;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Gmo\Salesforce\Exception;

class PasswordAuthentication implements AuthenticationInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

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
    /** @var string */
    protected $accessToken;
    /** @var GuzzleHttp\Client */
    private $guzzle;

    public function __construct(
        $clientId,
        $clientSecret,
        $username,
        $password,
        $securityToken,
        LoggerInterface $logger = null,
        $loginApiUrl = 'https://login.salesforce.com/services/'
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username = $username;
        $this->password = $password;
        $this->securityToken = $securityToken;
        $this->guzzle = new GuzzleHttp\Client([
            'base_uri' => $loginApiUrl,
        ]);
        $this->setLogger($logger ?: new NullLogger());
    }

    /**
     * @inheritdoc
     */
    public function getAccessToken()
    {
        if (!$this->accessToken) {
            $this->accessToken = $this->fetchAccessToken();
        }

        return $this->accessToken;
    }

    public function invalidateAccessToken()
    {
        $this->accessToken = null;
    }

    protected function fetchAccessToken()
    {
        $response = $this->guzzle->post('oauth2/token', [
            'auth'        => ['user', 'pass'],
            'http_errors' => false,
            'form_params' => [
                'grant_type'    => 'password',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username'      => $this->username,
                'password'      => $this->password . $this->securityToken,
            ],
        ]);
        $body = (string) $response->getBody();
        $json = json_decode($body, true);

        if ($response->getStatusCode() !== 200) {
            $message = isset($json['error_description']) ? $json['error_description'] : $body;
            $this->logger->error($message, ['response' => $body]);
            throw new Exception\SalesforceAuthentication($message);
        }

        if (!isset($json['access_token']) || empty($json['access_token'])) {
            $message = 'Access token not found';
            $this->logger->error($message, ['response' => $body]);
            throw new Exception\SalesforceAuthentication($message);
        }

        $issued = (new \DateTime(null))->setTimestamp((int) ($json['issued_at'] / 1000));
        $expires = clone $issued;
        $expires->modify('+1 hour -5 minutes');

        $token = new AccessToken(
            $json['id'],
            $issued,
            $expires,
            $json['token_type'],
            $json['access_token'],
            $json['signature'],
            $json['instance_url'],
            isset($json['scope']) ? $json['scope'] : null,
            isset($json['refresh_token']) ? $json['refresh_token'] : null
        );

        return $token;
    }
}
