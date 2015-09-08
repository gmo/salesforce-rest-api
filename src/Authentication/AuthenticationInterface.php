<?php
namespace Gmo\Salesforce\Authentication;

use Gmo\Salesforce\Exception;

interface AuthenticationInterface {
	/**
	 * Perform any necessary pre-processing to obtain the access token
	 * @return void
	 * @throws Exception\SalesforceAuthentication
	 */
	public function run();

	/**
	 * @return string
	 */
	public function getAccessToken();
}
