<?php
namespace Gmo\Salesforce\Authentication;

use Gmo\Salesforce\Exception;

interface AuthenticationInterface
{
    /**
     * @return string
     * @throws Exception\SalesforceAuthentication
     */
    public function getAccessToken();

    public function invalidateAccessToken();
}
