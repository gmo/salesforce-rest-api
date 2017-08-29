<?php
declare(strict_types=1);

namespace Gmo\Salesforce\Authentication;


class AuthenticationBag implements AuthenticationBagInterface
{

    /** @var  string */
    protected $token;

    /** @var  string */
    protected $instanceUrl;

    /** @var  string */
    protected $tokenType;

    /** @var  \DateTime */
    protected $issuedAt;

    protected $signature;

    public function __construct(array $jsonResponse)
    {
        $this->token = $jsonResponse['access_token'];
        $this->instanceUrl = $jsonResponse['instance_url'];
        $this->tokenType = $jsonResponse['token_type'];
        $this->issuedAt = (new \DateTime())->setTimestamp((int) $jsonResponse['issued_at']);
        $this->signature = $jsonResponse['access_token'];
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getInstanceUrl()
    {
        return $this->instanceUrl;
    }

    public function getTokenType()
    {
        return $this->tokenType;
    }

    public function issuedAt()
    {
        return $this->issuedAt;
    }

    public function getSignature()
    {
        return $this->signature;
    }
}