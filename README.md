# Silex Batched Request Service Provider
Silex service provider to add batched requests. Usage and implementation roughly based on <a href="https://developers.facebook.com/docs/graph-api/making-multiple-requests">making batch requests</a> with Facebook's Graph API.

Batching requests allows you to send multiple requests at once, allowing you to perform multiple GET, POST, etc. requests in a single HTTP request. A batched request returns an array of responses with relevant headers, error or success codes, and response bodies.

Built and tested on PHP 5.6, compatible with Silex version 2.


## Installation and Setup

Install the package via <a href="https://getcomposer.org/">composer</a>.
```
composer install dvanderburg/silex-batched-request
```

Register the service provider as part of your application bootstrap process.
```
$app->register(new App\Api\Batch\BatchRequestServiceProvider(), array(
	'batchrequest.url' => "batch"	// this is the default value
));
```


## Example

