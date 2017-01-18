<?php
	
	namespace App\Api\Batch;
	
	use Silex\Application;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpKernel\HttpKernelInterface;
	use Symfony\Component\HttpKernel\Exception\HttpException;
	
	use Flow\JSONPath\JSONPath;
	
	/**
		Represents a single batched request
	
		See BatchRequestServiceProvider for implementation details
		
	*/
	class BatchedRequest {
		
		// array of the requests being batched
		private $batch;
		
		// boolean indicating if headers should be included in each response
		private $includeHeaders;
		
		// array of responses to the batched requests
		//	indexed by either the name given to the request in the batch, or by a number indicating the order the request was made in
		private $responses;
		
		/**
			Constructs the batched request
			@param	array	$batch				The array of requests to run (method, relative_url, etc.)
			@param	boolean	$includeHeaders		If headers should be returned with each subrequest's response
			
		*/
		public function __construct($batch, $includeHeaders=true) {
			$this->batch = $batch;
			$this->includeHeaders = $includeHeaders;
		}
		
		/**
			Handles a batch of requests, handling each request as a subrequest and recording the response as an array
			@param	Silex\Application	$app		The application object, used to handle each subrequest
			@param	array				$batch		Array of batch data, indicates which requests to run
														array(
															array('method' => <post/get>, 'relative_url' => <the url for the subrequest>)
															...
														)

			@return	null		Might update the return of this function to be the number of succesful requests, leaving as null for the time being
		*/
		public function execute(Application $app) {
			
			// array to populate with each response
			$this->responses = array();
			
			// handle each batch request, recording response, status codes, and headers
			foreach ($this->batch as $batchedRequest) {
				
				// decide on a name for this request in the batch to index the array of responses
				//	this will either be the name provided in the batched request, or simply the index of the request in the batch (zero based)
				$requestName = !empty($batchedRequest['name']) ? $batchedRequest['name'] : count($this->responses);
				
				// try to handle the request
				//	catch exceptions to attach appropriate response in array of responses, rather than having the master request fail
				try {
					
					// parse URL tokens in the relative_url
					//	converts tokens into paramters: thing/?ids={json_path} becomes thing/?ids=[1,2,3,4...]
					//	modifies $batchedRequest by reference
					$this->parseURLTokens($batchedRequest);
					
					// attach the response to the array of all responses
					$this->responses[$requestName] = $this->handleBatchedRequest($app, $batchedRequest);

				// catch specific http exceptions to attach specific http status code and exception name
				} catch (HttpException $e) {
					
					// reflection class, describes the exception being caught
					$reflectionClass = new \ReflectionClass($e);
					
					// attach the http error code in the exception and the error as the body of the response
					$this->responses[$requestName] = array();
					$this->responses[$requestName]['code'] = $e->getStatusCode();
					$this->responses[$requestName]['body'] = array(
						'error' => $e->getMessage(),
						'type' => $reflectionClass->getShortName(),
					);
					
					// include headers if requested
					if ($this->includeHeaders) {
						$this->responses[$requestName]['headers'] = $this->formatHeaders($e->getHeaders());
					}
					
				// catch generic exceptions and indicate a 500 internal server error
				} catch (\Exception $e) {
					
					// use http code 500 for unknown/misc exceptions and the exception message in the body of the response
					$this->responses[$requestName] = array();
					$this->responses[$requestName]['code'] = 500;
					$this->responses[$requestName]['body'] = array(
						'error' => $e->getMessage(),
						'type' => ""
					);
					
					// include headers if requested
					if ($this->includeHeaders) {
						$this->responses[$requestName]['headers'] = array();
					}
					
				}
				
			}
						
			// todo: would it be helpful to return number of processed/succesful requests?
			return null;
			
		}
		
		/**
			Accessor for the responses in the batch
			Populated when the batched request is executed with this.execute
			Responses returned will be indexed by the name given to the request in the batch, or by the index in which they were requested (zero-based)
				Example:
					{ "name": "get-something", "relative_url": "/something/1234" }
					{ "name": "get-thing", "relative_url": "/thing/5432" }
					{ "relative_url": "/something_else/1234" }
				The example above would have responses returned indexed as ("get-something", "get-thing", 2)
			
			@return	array
		*/
		public function getResponses() {
			return $this->responses;
		}
		
		/**
			Helper function to handle an individual request in the batch
			@param	Application	$app			Silex application
			@param	array		$batchedRequest	The invidiaul request in the batch (relative_url, method, etc.)
			
			@return	array		The response as an array (code, headers, body)
		*/
		private function handleBatchedRequest(Application $app, $batchedRequest) {
			
			// determine parameters (GET/POST) for the batched request
			$parameters = $this->getParameters($batchedRequest);

			// handle the subrequest
			$subRequest = Request::create($batchedRequest['relative_url'], $batchedRequest['method'], $parameters);
			$subRequestResponse = $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
			
			// attach the response code and body to the response
			$response = array();
			$response['code'] = $subRequestResponse->getStatusCode();
			$response['body'] =  $subRequestResponse->getContent();
			
			// include headers if requested
			if ($this->includeHeaders == true) {
				$response['headers'] = $this->formatHeaders($subRequestResponse->headers->all());
			}
			
			// return the final response as an array
			return $response;
			
		}
		
		/**
			Parses URL tokens in the given batched request, by reference
			The batched request provided will have its relative_url updated with any present tokens parsed
			@param	array	$batchedRequest			The array representing the batched request
			
			@return	bool
		*/
		private function parseURLTokens(&$batchedRequest) {
			
			// retrieve any tokens in the relative URL
			$urlTokens = $this->getRelativeURLTokens($batchedRequest['relative_url']);			
			
			// if there were tokens in the url, parse/replace them based on responses to other requests in the batch
			foreach ($urlTokens as $urlToken) {
				
				// ensure the dependancy request exists
				if (!isset($this->responses[$urlToken['dependancy']])) {
					throw new HttpException(400, "The request to '".$batchedRequest['relative_url']."' is dependant on the request '".$urlToken['dependancy']."', but is not present in the batch.");
				}
				
				// load the dependacy's response
				$dependancyResponse = $this->responses[$urlToken['dependancy']];
				
				// ensure the dependancy request was succesful
				if ($dependancyResponse['code'] !== 200) {
					throw new HttpException(400, "The request to '".$batchedRequest['relative_url']."' could not be completed because its dependant request '".$urlToken['dependancy']."' failed.");
				}
				
				// json decode the dependacy's response and apply the json path
				$bodyData = json_decode($dependancyResponse['body'], true);
				$jsonPath = new JSONPath($bodyData);
				$result = $jsonPath->find($urlToken['json_path']);
				
				// parse the tokens in the relative url using the result of the json path expression
				$batchedRequest['relative_url'] = str_replace($urlToken['url_token'], json_encode($result), $batchedRequest['relative_url']);
								
			}
			
			return true;
			
		}
		
		/**
			Retrieves and formats any parameters in the batched request as an array
			Parses relative_url for a query string and retrieves the get parameters
			@param	array	$batchedRequest		The batched request details [ method => "", relative_url => "",... ]
			
			@return	array	The query string parameters as an associative array: ?one=1&two=2 becomes [ "one" => 1, "two" => 2 ]
		*/
		private function getParameters($batchedRequest) {
			
			$parameters = array();
			
			// divide the relative url into sections within an array
			//	the first element will be the resource, the second the query string
			$urlSections = explode('?', $batchedRequest['relative_url']);
			
			// check if a valid, non-empty query string was sent
			if (count($urlSections) == 2 && !empty($urlSections[1])) {

				// retrieve the query string portion of the relative url	
				$queryString = array_pop($urlSections);
				
				// manually parse the query string into an associative array of variables
				//	avoid using PHP's parse_str function due to potential security concerns
				//	parse_str sets the query string parameters as variables in local scope, allowing malicious users to access global vars
				//	for example: sending ?parameters=broken would change what this function returns
				foreach (explode('&', $queryString) as $queryStringVariable) {
					$queryStringVariableParts = explode('=', $queryStringVariable);
					$parameters[$queryStringVariableParts[0]] = $queryStringVariableParts[1];
				}
				
			}
			
			return $parameters;
			
		}
		
		/**
			Parses the relative URL for URL tokens and returns an array of token data, or an empty area if the relative URL contains no tokens
			@param	string		$relativeURL		The relative URL to parse, example: /friends/larry?page={result=page-request:$.data.page_number}
			
			@return	array							Array of token data, as formatted by this.getTokenDataFromString
		*/
		private function getRelativeURLTokens($relativeURL) {
			
			// array to return all tokens with
			//	populated by parsing the relative URL for tokens and then formatted with this.getTokenDataFromTokenString
			$tokens = array();
			
			// loop until all tokens have been parsed
			//	tokens are contained within braces, look for an opening brace to identify a token
			//	$relativeURL is modified within the loot to remove each token as it is found
			do {

				// find an ocurrance of a token
				//	this could be improved if nested tokens are ever desired, such as: {result=something:$.*.{result=something-else:$.id_attribute}}
				//	for now, it will not parse the nested token, resulting in fewer tokens with some containing the '}' character inside the token
				$urlTokenStart = strpos($relativeURL, '{');
				$urlTokenEnd = strpos($relativeURL, '}');
				$urlTokenLength = $urlTokenEnd - $urlTokenStart + 1;
				$urlToken = substr($relativeURL, $urlTokenStart, $urlTokenLength);
				
				if ($urlTokenStart !== false) {
					
					// remove the occurence of this url token to continue parsing
					$relativeURL = str_replace($urlToken, "", $relativeURL);
					
					// add token data to return
					$tokens[] = $this->getTokenDataFromTokenString($urlToken);

				}
								
			} while ($urlTokenStart !== false);
						
			// final array of tokens
			//	will be blank if there were no tokens
			//	populated with arrays containing token data, as formatted by this.getTokenDataFromTokenString
			return $tokens;
			
		}
		
		/**
			Helper function to string parse a URL token into an array of data
			@param	string	$urlToken		A URL token to parse, example: {result-named-query:$.data.*.id}
			
			@return	array					Array of data contained within the token
		*/
		private function getTokenDataFromTokenString($urlToken) {
						
			// remove the braces from the token (remove first and last character)
			$tokenBody = substr($urlToken, 1, strlen($urlToken)-2);
			
			// position of the colon, delimiter for the token
			$colonPosition = strpos($tokenBody, ':');
			
			// position of the equals sign, delimiter for the token type and request name
			$equalsPotions = strpos($tokenBody, '=');
			
			// get the type of the token
			//	everything from the begining of the token body up to the position of the equals, example: "result=named-query:$.data.*.id" yields "result"
			$tokenType = substr($tokenBody, 0, $equalsPotions);
			
			// name of the dependancy request, example: "result=named-query:$.data.*.id" yields "named-query"
			$dependancyName = substr($tokenBody, $equalsPotions+1, $colonPosition - strlen($tokenType) - 1);
			
			// get the JSON path portion of the token
			//	everything past the position of the colon until the end of the token, example: "result=named-query:$.data.*.id" yields "$.data.*.id"
			$tokenJSONPath = substr($tokenBody, $colonPosition+1);
			
			// format the token data and return
			return array(
				'url_token' => $urlToken,
				'type' => $tokenType,
				'dependancy' => $dependancyName,
				'json_path' => $tokenJSONPath,
			);

		}
		
		/**
			Helper function to format headers
			
			@return	array
		*/
		private function formatHeaders(array $headers) {
			return array_map(function ($name, $value) {
				return array('name' => $name, 'value' => current($value));
			}, array_keys($headers), $headers);
		}		
		
	}
