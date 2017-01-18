# Silex Batched Request Service Provider
Silex service provider to perform batched requests. Usage and implementation roughly based on <a href="https://developers.facebook.com/docs/graph-api/making-multiple-requests">making batch requests</a> with Facebook's Graph API.

Batching requests allows you to send multiple requests at once, allowing you to perform multiple operations in a single HTTP request. Each request in the batch is processed in sequence unless dependencies are specified.

Requests within the batch can list dependancies on other requests within the batch, and access their responses via JSONP syntax. Again, similar to Facebook's Graph API. Using <a href="https://code.google.com/archive/p/jsonpath/">JSONP syntax</a>, a one request in the batch can use the response from another request in the batch. Once all requests have been completed, an array of responses will be returned and the HTTP connection closed.

Specifying dependancies allows a one request in the batch to utilize the response from another request in the batch. This is achieved using <a href="https://en.wikipedia.org/wiki/JSONP">JSONP</a> syntax. More information is provided in the [Usage and Examples](#Usage-and-Examples) section.

Built and tested on PHP 5.6, compatible with Silex version 2.


## Dependancies

* __PHP >=5.6__ Built and tested on PHP 5.6
* __Silex ~2.0__ Originally built using Silex version 2.0.4 (https://github.com/silexphp/Silex)
* __FlowCommunications/JSONPath ~0.3.4__ Accommodates JSONP parsing for specifying dependancies (https://github.com/FlowCommunications/JSONPath)


## Installation and Setup

Install the package via <a href="https://getcomposer.org/">composer</a>.
```bash
composer install dvanderburg/silex-batched-request
```

Register the service provider as part of your application bootstrap process.
```php
$app->register(new Dvanderburg\BatchedRequest\BatchRequestServiceProvider(), array(
	'batchrequest.url' => "batch"	// this is the default value
));
```

## Service Provider Configuration

The service provider can be configured to determine which URL to process batched requests with. The default is `/batch/`, meaning to send a batched request you would POST to something like `http://localhost/batch/`. This can be customized, including set the the web root by specifying `/` for `batchrequest.url`.


## Simple Batched Requests

Send a basic batched request by sending an HTTP post to the url you configured the service provider with (/batch/ by default). This example will perform two GET requests and a POST, returning an array of responses.

HTTP POST Example:
```
POST /batch HTTP/1.1
Content-Type: application/json
include_headers: true,
batch: [
	{ "method": "GET",	"relative_url": "/products/1?one=1&two=2&three=3" },
	{ "method": "GET",	"relative_url": "/users/?ids=larry,jill,sally" },
	{ "method": "POST",	"name": "create-user", "relative_url": "/users/?username=john&password=admin" },
```

JQuery XHR Example:
```javascript
$.ajax({
	method: "POST",
	dataType: "json",
	data: {
		include_headers: true,
		batch: [
			{ "method": "GET",	"relative_url": "/products/1?one=1&two=2&three=3" },
			{ "method": "GET",	"relative_url": "/users/?ids=larry,jill,sally" },
			{ "method": "POST",	"name": "create-user", "relative_url": "/users/?username=john&password=admin" },
		]
	}
})
```

For the examples above, the expected response format would be:
```javascript
[
	{
		"code": 200,
		"headers": [ ... ],
		"body": [{ product_id: 1 }, { product_id: 2 }, { product_id: 3 }]
	},
	{
		"code": 200,
		"headers": [ ... ],
		"body": [{ username: "larry" }, { username: "jill" }, { username: "sally" }]
	},
	{
		"code": 200,
		"headers": [ ... ],
		"body": { username: "john" }
	},
]
```


## Removing Headers from the Response

Headers can be removed from the response by setting `include_headers` to `false` in the data of the batch HTTP request. If not specified, the default behaviour is to return headers with each response.


## Errors

If a specific request in the batch fails its response within the array of responses will contain a non-200 code. However, the actual HTTP request to process the batch will still return a 200-OK.


## Specifying Dependencies with JSONP

Sometimes an operation of one request in the batch is dependant on the response of another. This dependancy can be created specifying a name for a request and then accessing the response of that request using JSONP syntax.

The following example retrieves a user's collection of books which are represented with a `book_id`. The book IDs are then used by a second request in the batch to retrieve information about those books.

HTTP POST Example:
```
POST /batch HTTP/1.1
Content-Type: application/json
batch: [
	{ "method": "GET", "name": "get-user-books", "relative_url": "/user_books/?username=larry" },
	{ "method": "GET", "relative_url": "/books/?book_ids={result=get-user-books:$.book_ids.*}" },
```

In the example above, the first request in the batch is named get-user-books and the second request in the batch uses JSONP syntax to extract all the book_ids from the first request in order to know what books to retrieve.

If a request is a dependancy of another request and fails, it will cause the request which is dependant on it to also fail. In the above example if the get-user-books request fails, the request to retrieve books will also fail.


## Limitations

Batched requests are processed after firewall security, meaning you cannot protect individual requests with firewall rules. It is recommended that batched requests are all behind a firewall requiring authentication, or each individual request checks user authentication and permissions.
