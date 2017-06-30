<?php

namespace Gmo\Salesforce;

class AccessToken
{
    /** @var string */
    private $id;

    /** @var \DateTime */
    private $issued;

    /** @var \DateTime */
    private $expires;

    /** @var string */
    private $tokenType;

    /** @var string */
    private $accessToken;

    /** @var string */
    private $signature;

    /** @var string */
    private $apiUrl;

    /** @var array|null */
    private $scope;

    /** @var string|null */
    private $refreshToken;

    /**
     * Constructor.
     *
     * @param string      $id
     * @param \DateTime   $issued
     * @param \DateTime   $expires
     * @param string      $tokenType
     * @param string      $accessToken
     * @param string      $signature
     * @param string      $apiUrl
     * @param array|null  $scope
     * @param null|string $refreshToken
     */
    public function __construct(
        $id,
        \DateTime $issued,
        \DateTime $expires,
        $tokenType,
        $accessToken,
        $signature,
        $apiUrl,
        $scope,
        $refreshToken
    ) {
        $this->id = $id;
        $this->issued = $issued;
        $this->expires = $expires;
        $this->tokenType = $tokenType;
        $this->accessToken = $accessToken;
        $this->signature = $signature;
        $this->apiUrl = $apiUrl;
        $this->scope = $scope;
        $this->refreshToken = $refreshToken;
    }

    public function isExpired()
    {
        return $this->expires < new \DateTime();
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return \DateTime
     */
    public function getIssued()
    {
        return $this->issued;
    }

    /**
     * @return \DateTime
     */
    public function getExpires()
    {
        return $this->expires;
    }

    /**
     * @return string
     */
    public function getTokenType()
    {
        return $this->tokenType;
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @return string
     */
    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * @return string
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    /**
     * @return array|null
     */
    public function getScope()
    {
        return $this->scope;
    }

    /**
     * @return null|string
     */
    public function getRefreshToken()
    {
        return $this->refreshToken;
    }
}
