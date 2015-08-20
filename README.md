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

$salesforce = new Salesforce\Client(
	"na5",
	"ClientId",
	"ClientSecret",
	"Username",
	"Password",
	"SecurityToken"
);

try {
	$contactRecords = $salesforce->query("SELECT AccountId, LastName
		FROM Contact
		WHERE FirstName = ?",
		array('Alice')
	);
	print_r($contactRecords);   // The output of the query API JSON, converted to associative array
	
    $contactRecords2 = $salesforce->query("SELECT AccountId, LastName
        FROM Contact
        WHERE FirstName = :firstName",
        array('firstName' => 'Bob')
    );
	print_r($contactRecords2);   // The output of the query API JSON, converted to associative array

} catch(Exception\SalesforceNoResults $e) {
	// Do something when you have no results from your query
} catch(Exception\Salesforce $e) {
	// Error handling
}
```


