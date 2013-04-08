<?php

/**
 * An extension of the Codebird class to use Wordpress' HTTP API instead of
 * cURL.
 *
 * @version 1.0.1
 */
class WP_Codebird extends Codebird {
	/**
	 * Returns singleton class instance
	 * Always use this method unless you're working with multiple authenticated
	 * users at once.
	 *
	 * This method had to be overloaded because self was used
	 * in the references instead of get_called_class. So the method was always
	 * returning instances of Codebird instead of WP_Codebird.
	 *
	 * @return Codebird The instance
	 */
	public static function getInstance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self;
		}

		return self::$_instance;
	}

	/**
	 * Calls the API using Wordpress' HTTP API.
	 *
	 * @since 0.1.0
	 * @see Codebird::_callApi
	 * @param string          $httpmethod      The HTTP method to use for making the request
	 * @param string          $method          The API method to call
	 * @param string          $method_template The templated API method to call
	 * @param array  optional $params          The parameters to send along
	 * @param bool   optional $multipart       Whether to use multipart/form-data
	 *
	 * @return mixed The API reply, encoded in the set return_format.
	 */
	protected function _callApi($httpmethod, $method, $method_template, $params = array(), $multipart = false) {
		$url = $this->_getEndpoint( $method, $method_template );
		$remote_params = array(
			'method' => 'GET',
			'timeout' => 5,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(),
			'body' => null,
			'cookies' => array(),
			'sslverify' => false
		);

		if ($httpmethod == 'GET') {
			$signed = $this->_sign( $httpmethod, $url, $params );
			$reply = wp_remote_get( $signed, $remote_params );
		} else {
			if ( $multipart ) {
				$authorization = $this->_sign( 'POST', $url, array(), true );
				$post_fields   = $params;
			} else {
				$post_fields = $this->_sign( 'POST', $url, $params );
			}

			$headers = array();
			if ( isset( $authorization ) ) {
				$headers = array( $authorization, 'Expect:' );
			}

			$remote_params = array(
				'method' => 'POST',
				'timeout' => 5,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => $headers,
				'body' => $post_fields,
				'cookies' => array(),
				'sslverify' => false
			);

			$reply = wp_remote_post( $url, $remote_params );
		}

		if ( isset( $reply ) ) {
			if ( is_wp_error( $reply ) ) {
				throw new Exception( $reply->get_error_message() );
			} else {
				$httpstatus = $reply[ 'response' ][ 'code' ];
				$reply = $this->_parseApiReply( $method_template, $reply );

				if ( $this->_return_format == CODEBIRD_RETURNFORMAT_OBJECT ) {
					$reply->httpstatus = $httpstatus;
				} else {
					$reply[ 'httpstatus' ] = $httpstatus;
				}
			}
		} else {
			throw new Exception( 'A reply was never generated. Some has gone horribly awry.' );
		}

		return $reply;
	}

	/**
	 * Parses the API reply to encode it in the set return_format.
	 *
	 * @since 0.1.0
	 * @see Codebird::_parseApiReply
	 * @param string $method The method that has been called
	 * @param string $reply  The actual reply, JSON-encoded or URL-encoded
	 *
	 * @return array|object The parsed reply
	 */
	protected function _parseApiReply( $method, $reply ) {
		// split headers and body
		$http_response = $reply;
		$headers = $http_response[ 'headers' ];

		$reply = '';
		if ( isset( $http_response[ 'body' ] ) ) {
			$reply = $http_response[ 'body' ];
		}

		$need_array = $this->_return_format == CODEBIRD_RETURNFORMAT_ARRAY;
		if ( $reply == '[]' ) {
			return $need_array ? array() : new stdClass;
		}

		$parsed = array();
		if ( $method == 'users/profile_image/:screen_name' ) {
			// this method returns a 302 redirect, we need to extract the URL
			if ( isset( $headers[ 'Location' ] ) ) {
				$parsed = array( 'profile_image_url_https' => $headers[ 'Location' ] );
			}
		} elseif ( !$parsed = json_decode( $reply, $need_array ) ) {
			if ( $reply ) {
				$reply = explode( '&', $reply );
				foreach ( $reply as $element ) {
					if ( stristr( $element, '=' ) ) {
						list( $key, $value ) = explode( '=', $element );
						$parsed[ $key ] = $value;
					} else {
						$parsed[ 'message' ] = $element;
					}
				}
			}
		}
		if ( !$need_array ) {
			$parsed = ( object ) $parsed;
		}
		return $parsed;
	}
}
