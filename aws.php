<?php

	abstract class AWS {
		
		const VERSION = '0.1';
		
		private $aws_access_key = null;
		private $aws_secret = null;
		
		protected $api_version;
		protected $endpoint;
		protected $xml_namespace;
		
		public function __construct ( $aws_access_key = null, $aws_secret = null ) {
			
			// for compatibility with other libraries, accept constants as well
			if ( $aws_access_key == null && !defined('AWS_KEY') ) {
				throw new AWS_Exception( 'No access key provided and no AWS_KEY constant available.' );
			}
			
			if ( $aws_secret == null && !defined('AWS_SECRET_KEY') ) {
				throw new AWS_Exception( 'No secret key provided and no AWS_SECRET_KEY constant available.' );
			}
			
			$this->aws_access_key = $aws_access_key;
			$this->aws_secret = $aws_secret;
			
		}
		
		protected function request ( $action, $options = array() ) {
		
			$dom = new DOMDocument( '1.0', 'utf-8' );
			$dom->formatOutput = true;
			
			$envelope = $dom->appendChild( new DOMElement( 'soapenv:Envelope', '', 'http://schemas.xmlsoap.org/soap/envelope/' ) );
			$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:soapenv', 'http://schemas.xmlsoap.org/soap/envelope/' );
			$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:soapenc', 'http://http://schemas.xmlsoap.org/soap/encoding/' );
			$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
			$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema' );
			
			$timestamp = gmdate('c');		// GMT, as recommended by Amazon
			$signature = $this->generate_signature( $action, $timestamp );
			
			$body = $envelope->appendChild( new DOMElement( 'soapenv:Body', '', 'http://schemas.xmlsoap.org/soap/envelope/' ) );
			
			$request = $body->appendChild( new DOMElement( $action, '', $this->xml_namespace ) );
			
			// add all the authentication info for the request
			$timestamp = $request->appendChild( new DOMElement( 'Timestamp', $timestamp ) );
			$signature = $request->appendChild( new DOMElement( 'Signature', $signature ) );
			$version = $request->appendChild( new DOMElement( 'Version', $this->api_version ) );
			$access_key = $request->appendChild( new DOMElement( 'AWSAccessKeyId', $this->aws_access_key ) );
			
			//$action = $request->appendChild( new DOMElement( 'Action', $action ) );
			$max_domains = $request->appendChild( new DOMElement( 'MaxNumberOfDomains', '2' ) );
			
			echo 'REQUEST:' . "\n";
			echo $dom->saveXML();
			echo "\n\n";
			
			$options = array(
				'http' => array(
					'method' => 'POST',
					'user_agent' => 'AWS/' . self::VERSION,
					'content' => $dom->saveXML(),
					'header' => array(
						'Content-Type: application/soap+xml; charset=utf-8',		// the content-type triggers the SOAP API, rather than REST
					),
					'ignore_errors' => true,		// ignore HTTP status code failures and return the result so we can check for the error message 
				)
			);
			
			$context = stream_context_create( $options );
			
			$response = file_get_contents( 'https://' . $this->endpoint, false, $context );
			
			//echo $response;
			
			$response_dom = new DOMDocument( '1.0', 'utf-8' );
			$response_dom->formatOutput = true;
			$response_dom->validateOnParse = true;
			$response_dom->loadXML( $response );
			
			
			
			echo "RESPONSE:\n";
			echo $response_dom->saveXML();
			
			// Error elements are returned for REST XML responses - check those for good measure
			$errors = $response_dom->getElementsByTagName( 'Error' );
			
			if ( $errors->length > 0 ) {
				
				foreach ( $errors as $error ) {
					
					foreach ( $error->childNodes as $child ) {
						if ( $child->nodeName == 'Code' ) {
							$code = $child->nodeValue;
						}
						if ( $child->nodeName == 'Message' ) {
							$message = $child->nodeValue;
						}
					}
					
					throw new AWS_Exception( $code . ': ' . $message );
				}
				
			}
			
			// check for SOAP faults
			$faults = $response_dom->getElementsByTagNameNS( 'http://schemas.xmlsoap.org/soap/envelope/', 'Fault' );
			
			if ( $faults->length > 0 ) {
				
				foreach ( $faults as $fault ) {
				
					foreach ( $fault->childNodes as $child ) {
						if ( $child->nodeName == 'faultcode' ) {
							$code = $child->nodeValue;
						}
						if ( $child->nodeName == 'faultstring' ) {
							$string = $child->nodeValue;
						}
					}
					
					throw new AWS_Exception( $code . ': ' . $string );
					
				}
				
			}
			
			// now that we're sure we've got a valid result, parse out the meta data
			$request_id = $response_dom->getElementsByTagName( 'RequestId' ) ->item(0)->nodeValue;
			$box_usage = $response_dom->getElementsByTagName( 'BoxUsage' )->item(0)->nodeValue;
			
			// see if we have a next token
			$next_tokens = $response_dom->getElementsByTagName( 'NextToken' );
			
			if ( $next_tokens->length > 0 ) {
				$next_token = $next_tokens->item(0)->nodeValue;
			}
			else {
				$next_token = null;
			}
			
			$result = $response_dom->getElementsByTagName( $action . 'Result' )->item(0);
			
			// if there is an xpath query defined, run it
			if ( $result_xpath != null ) {
				$xpath = new DOMXPath( $response_dom );
				
				$result = $xpath->query( $result_xpath, $result );
			}
			else {
				// otherwise, just return the result
				
			}
			
			$r = new AWS_Response();
			$r->request_id = $request_id;
			$r->box_usage = $box_usage;
			$r->next_token = $next_token;
			
			$r->response = $result;
			
			$r->response_dom = $response_dom;
			
			print_r($r);
			
			return $r;
			
		}
		
		private function generate_signature ( $action, $timestamp ) {
			
			$hash = hash_hmac( 'sha1', $action . $timestamp, $this->aws_secret, true );
			
			// ah HA! you thought we missed reading that line of the docs... well... we did
			return base64_encode( $hash );
			
		}
		
	}

	class AWS_Response {
		
		public $request_id;
		public $box_usage;
		
		public $response;
		
		public $response_dom;
		
	}
	
	class AWS_Exception extends Exception {}
	
?>