<?php
	
	namespace Dvanderburg\BatchedRequest;
	
	use Silex\Application;
	use Silex\Api\BootableProviderInterface;
	use Pimple\Container;
	use Pimple\ServiceProviderInterface;
	use Symfony\Component\HttpFoundation\Request;
	
	use App\Api\Batch\BatchedRequest;

	/**
		Service provider to accomodate batched requests
		Will mount a specific url to handle a batch of requests
		Expects a "batch" parameter sent as form data to the url handling batched requests
		Requests are processed in the order they are listed in
		
		Important note: Firewall rules do not apply to subrequests!
		
		Integration
			$app->register(new App\Api\Batch\BatchRequestServiceProvider(), array(
				'batchrequest.url' => "batch"	// this is the default value
			));
			
		
		Implementation
			POST /batch HTTP/1.1
			Content-Type: application/json
			batch: [
				{ "method": "GET",	"relative_url": "/products/1?one=1&two=2&three=3" },
				{ "method": "GET",	"relative_url": "/users/?ids=larry,jill,sally" },
				{ "method": "POST",	"name": "create-user", "relative_url": "/users/?username=john&password=admin" },
			]
			
		The POST request above will respond with an array of JSON responses
		The method and relative url attributes are required, however, a name is optional
			If a name was provided, the returned response will be indexed with that name
			
		
		Specifying dependancies with JSON Path syntax
			POST /batch HTTP/1.1
			Content-Type: application/json
			batch: [
				{ "method": "GET",	"name": "get-user-products", "relative_url": "/user_products/john" },
				{ "method": "GET",	"relative_url": "/products/?ids={result=get-user-products:$.*.product_id}" },
			]
			
		The POST request above will process the "get-user-products" request first, then supply the result to the second request in the path
		The JSON Path syntax will insert an array containing all retrieved product IDs from the first request
		
		Service options
			batchrequest.url			The URL to use to handle batch requests, defaults to "batch"
		
	*/
	class BatchRequestServiceProvider implements ServiceProviderInterface, BootableProviderInterface {
		
		/**
			Registers the service provider
			@param	Pimple\Container	$app		The application container
			
			@return	null
		*/
		public function register(Container $app) {
			
			// defaults
			$app['batchrequest.url'] = "batch";
			
		}
		
		/**
			Boots the service provider
			Attaches the route responsible for handling batched requests
			@param	Silex\Application		The application object
			
			@return	null
		*/
		public function boot(Application $app) {

			// post route to handle a batch request
			$app->post($app['batchrequest.url'], function(Application $app, Request $request) {
				
				// boolean indicating if headers should be sent for each sub request in the batch
				$includeHeaders = $request->request->get("include_headers", true);
				
				// all request data are strings
				//	set $includeHeaders to true if the provided form data in the request is litterally the string "true"
				$includeHeaders |= $includeHeaders == "true";
				
				// get the batch of requests
				//	array of data sent with the master request
				//	each element contains relative_url and method
				$batch = $request->request->get("batch", array());
				
				// create a new batched request
				$batchedRequest = new BatchedRequest($batch, $includeHeaders);
				
				// handle the batch of requests
				$batchedRequest->execute($app);
				
				// the array of responses to return to this POST request
				$responses = $batchedRequest->getResponses();
				
				// return a json response
				return $app->json($responses);
				
			});

		}		

	}

