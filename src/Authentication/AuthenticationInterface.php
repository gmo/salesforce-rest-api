<?php
declare(strict_types=1);

namespace Gmo\Salesforce\Authentication;

use Gmo\Salesforce\Exception;

interface AuthenticationInterface
{
    /**
     * @return AuthenticationBagInterface
     * @throws Exception\SalesforceAuthentication
     */
    public function getAccessToken();

    public function invalidateAccessToken();
}
