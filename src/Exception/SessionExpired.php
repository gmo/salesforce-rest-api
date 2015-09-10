<?php
namespace Gmo\Salesforce\Exception;

class SessionExpired extends SalesforceAuthentication {
	const ERROR_CODE = 401;
}
