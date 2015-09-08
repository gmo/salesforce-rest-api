# salesforce-rest-api
A simple PHP client for the Salesforce REST API

## Installation

Install with composer:
```
composer config repositories.salesforce-rest-api vcs https://github.com/gmo/salesforce-rest-api
composer require "gmo/salesforce-rest-api:^1.0"
```

## Usage

Initialize the `Salesforce\Client` class, call the APIs you want.

```php
use Gmo\Salesforce;
use Gmo\Salesforce\Exception;
use Guzzle\Http;

$authentication = new Salesforce\Authentication\PasswordAuthentication(
	"ClientId",
	"ClientSecret",
	"Username",
	"Password",
	"SecurityToken"
);
$salesforce = new Salesforce\Client($authentication, new Guzzle\Http(), "na5");

try {
	$contactQueryResults = $salesforce->query("SELECT AccountId, LastName
		FROM Contact
		WHERE FirstName = ?",
		array('Alice')
	);
	print_r($contactQueryResults->getResults());   // The output of the query API JSON, converted to associative array
	
    $contactQueryResults2 = $salesforce->query("SELECT AccountId, LastName
        FROM Contact
        WHERE FirstName = :firstName",
        array('firstName' => 'Bob')
    );
	print_r($contactQueryResults2->getResults());   // The output of the query API JSON, converted to associative array

} catch(Exception\SalesforceNoResults $e) {
	// Do something when you have no results from your query
} catch(Exception\Salesforce $e) {
	// Error handling
}
```


