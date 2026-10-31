<?php
/**
 * Bolna Voice AI API client — framework-agnostic.
 *
 * Inside WordPress it uses the WP HTTP API and returns WP_Error on failure.
 * In any plain PHP application it uses cURL and throws RuntimeException on failure:
 *
 *     require 'includes/class-voicepress-bolna-client.php';
 *     $bolna = new VoicePress_Bolna_Client( getenv( 'BOLNA_API_KEY' ) );
 *     $call  = $bolna->make_call( $agent_id, '+919876543210', array( 'customer_name' => 'Asha' ) );
 *     echo $call['execution_id'];
 *
 * @package VoicePress
 */

// Loadable without WordPress (plain PHP shops), but never executed as a direct web request.
if ( ! defined( 'ABSPATH' ) && isset( $_SERVER['SCRIPT_FILENAME'] ) && basename( (string) $_SERVER['SCRIPT_FILENAME'] ) === basename( __FILE__ ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	exit;
}

/**
 * Thin client for https://api.bolna.ai.
 *
 * Every public API method returns the decoded JSON response as an array (an empty array when the
 * response has no body). On failure it returns WP_Error( 'voicepress_bolna', 'Bolna API {status}: {message}',
 * array( 'status' => int, 'body' => mixed ) ) when WordPress is loaded, otherwise it throws RuntimeException
 * (exception code = HTTP status; 502 for network errors, 400/401 for missing arguments or API key).
 */
class VoicePress_Bolna_Client {

	const DEFAULT_BASE_URL  = 'https://api.bolna.ai';
	const TIMEOUT           = 30;
	const RECORDING_TIMEOUT = 60;
	const VERSION           = '0.1.0';

	/**
	 * Bolna API key ("bn-…").
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $api_key  Bolna API key.
	 * @param string $base_url API base URL.
	 */
	public function __construct( string $api_key, string $base_url = 'https://api.bolna.ai' ) {
		$this->api_key  = trim( $api_key );
		$base_url       = rtrim( trim( $base_url ), '/' );
		$this->base_url = '' !== $base_url ? $base_url : self::DEFAULT_BASE_URL;
	}

	/* ------------------------------------------------------------------ Account */

	/**
	 * GET /user/me — account details (wallet is in cents, concurrency limits).
	 *
	 * @return array|WP_Error
	 */
	public function me() {
		return $this->request( 'GET', '/user/me' );
	}

	/* ------------------------------------------------------------------- Agents */

	/**
	 * GET /v2/agent/all — bare array of agents.
	 *
	 * @return array|WP_Error
	 */
	public function list_agents() {
		return $this->request( 'GET', '/v2/agent/all' );
	}

	/**
	 * GET /v2/agent/{id}.
	 *
	 * @param string $id Agent id.
	 * @return array|WP_Error
	 */
	public function get_agent( $id ) {
		$path = $this->id_path( '/v2/agent/%s', $id );
		return null === $path ? $this->missing( 'agent id' ) : $this->request( 'GET', $path );
	}

	/**
	 * POST /v2/agent → 201 {agent_id, state}.
	 *
	 * @param array $agent_config  Agent config.
	 * @param array $agent_prompts Agent prompts ({task_1: {system_prompt}}).
	 * @return array|WP_Error
	 */
	public function create_agent( array $agent_config, array $agent_prompts ) {
		return $this->request(
			'POST',
			'/v2/agent',
			array(
				'agent_config'  => $agent_config,
				'agent_prompts' => $agent_prompts,
			)
		);
	}

	/**
	 * PUT /v2/agent/{id} — full update.
	 *
	 * @param string $id            Agent id.
	 * @param array  $agent_config  Agent config.
	 * @param array  $agent_prompts Agent prompts.
	 * @return array|WP_Error
	 */
	public function update_agent( $id, array $agent_config, array $agent_prompts ) {
		$path = $this->id_path( '/v2/agent/%s', $id );
		if ( null === $path ) {
			return $this->missing( 'agent id' );
		}
		return $this->request(
			'PUT',
			$path,
			array(
				'agent_config'  => $agent_config,
				'agent_prompts' => $agent_prompts,
			)
		);
	}

	/**
	 * PATCH /v2/agent/{id} — partial update.
	 *
	 * @param string $id   Agent id.
	 * @param array  $body Fields to change.
	 * @return array|WP_Error
	 */
	public function patch_agent( $id, array $body ) {
		$path = $this->id_path( '/v2/agent/%s', $id );
		return null === $path ? $this->missing( 'agent id' ) : $this->request( 'PATCH', $path, $body );
	}

	/**
	 * DELETE /v2/agent/{id}.
	 *
	 * @param string $id Agent id.
	 * @return array|WP_Error
	 */
	public function delete_agent( $id ) {
		$path = $this->id_path( '/v2/agent/%s', $id );
		return null === $path ? $this->missing( 'agent id' ) : $this->request( 'DELETE', $path );
	}

	/* -------------------------------------------------------------------- Calls */

	/**
	 * POST /call — place an outbound call → {message, status: 'queued', execution_id}.
	 *
	 * @param string $agent_id  Agent id.
	 * @param string $to        Recipient phone number, E.164.
	 * @param array  $user_data Variables for the agent prompt ({{customer_name}} …). Sent only when non-empty.
	 * @param string $from      Caller id from your Bolna account. Sent only when non-empty.
	 * @return array|WP_Error
	 */
	public function make_call( $agent_id, $to, array $user_data = array(), $from = '' ) {
		$agent_id = trim( (string) $agent_id );
		$to       = trim( (string) $to );
		if ( '' === $agent_id ) {
			return $this->missing( 'agent id' );
		}
		if ( '' === $to ) {
			return $this->missing( 'recipient phone number' );
		}
		$body = array(
			'agent_id'               => $agent_id,
			'recipient_phone_number' => $to,
		);
		$from = trim( (string) $from );
		if ( '' !== $from ) {
			$body['from_phone_number'] = $from;
		}
		if ( ! empty( $user_data ) ) {
			$body['user_data'] = $user_data;
		}
		return $this->request( 'POST', '/call', $body );
	}

	/**
	 * POST /call/{execution_id}/stop — cancel a queued/scheduled call.
	 *
	 * @param string $execution_id Execution id.
	 * @return array|WP_Error
	 */
	public function stop_call( $execution_id ) {
		$path = $this->id_path( '/call/%s/stop', $execution_id );
		return null === $path ? $this->missing( 'execution id' ) : $this->request( 'POST', $path );
	}

	/**
	 * GET /executions/{execution_id} — status, transcript, summary, extracted_data, telephony_data, cost.
	 *
	 * @param string $execution_id Execution id.
	 * @return array|WP_Error
	 */
	public function get_execution( $execution_id ) {
		$path = $this->id_path( '/executions/%s', $execution_id );
		return null === $path ? $this->missing( 'execution id' ) : $this->request( 'GET', $path );
	}

	/**
	 * GET /v2/agent/{id}/executions?page_number=&page_size= → {data: [...], total, has_more, ...}.
	 *
	 * @param string $agent_id  Agent id.
	 * @param int    $page      Page number (1-based).
	 * @param int    $page_size Page size.
	 * @return array|WP_Error
	 */
	public function list_agent_executions( $agent_id, $page = 1, $page_size = 25 ) {
		$path = $this->id_path( '/v2/agent/%s/executions', $agent_id );
		if ( null === $path ) {
			return $this->missing( 'agent id' );
		}
		return $this->request(
			'GET',
			$path,
			null,
			array(
				'page_number' => max( 1, (int) $page ),
				'page_size'   => max( 1, (int) $page_size ),
			)
		);
	}

	/* ------------------------------------------------------ Numbers & inbound */

	/**
	 * GET /phone-numbers/all — bare array of {id, phone_number, telephony_provider, agent_id, …}.
	 *
	 * @return array|WP_Error
	 */
	public function list_phone_numbers() {
		return $this->request( 'GET', '/phone-numbers/all' );
	}

	/**
	 * POST /inbound/setup — answer calls to a phone number with an agent.
	 *
	 * @param string $agent_id        Agent id.
	 * @param string $phone_number_id Phone number id (from list_phone_numbers()).
	 * @return array|WP_Error
	 */
	public function setup_inbound( $agent_id, $phone_number_id ) {
		return $this->request(
			'POST',
			'/inbound/setup',
			array(
				'agent_id'        => (string) $agent_id,
				'phone_number_id' => (string) $phone_number_id,
			)
		);
	}

	/**
	 * POST /inbound/unlink.
	 *
	 * @param string $phone_number_id Phone number id.
	 * @return array|WP_Error
	 */
	public function unlink_inbound( $phone_number_id ) {
		return $this->request( 'POST', '/inbound/unlink', array( 'phone_number_id' => (string) $phone_number_id ) );
	}

	/* -------------------------------------------------------------- Extraction */

	/**
	 * POST /agent/{agent_id}/extraction-categories {name, model} → {id, …}.
	 *
	 * @param string $agent_id Agent id.
	 * @param string $name     Category name.
	 * @param string $model    Model used for the extraction pass.
	 * @return array|WP_Error
	 */
	public function create_extraction_category( $agent_id, $name, $model = 'gpt-4.1-mini' ) {
		$path = $this->id_path( '/agent/%s/extraction-categories', $agent_id );
		if ( null === $path ) {
			return $this->missing( 'agent id' );
		}
		return $this->request(
			'POST',
			$path,
			array(
				'name'  => (string) $name,
				'model' => (string) $model,
			)
		);
	}

	/**
	 * GET /agent/{agent_id}/extraction-categories.
	 *
	 * @param string $agent_id Agent id.
	 * @return array|WP_Error
	 */
	public function list_extraction_categories( $agent_id ) {
		$path = $this->id_path( '/agent/%s/extraction-categories', $agent_id );
		return null === $path ? $this->missing( 'agent id' ) : $this->request( 'GET', $path );
	}

	/**
	 * POST /dispositions/ → {id, category_id}.
	 *
	 * @param array $data agent_id, category_id, name, question, is_subjective, is_objective,
	 *                    objective_options [{value, condition}], subjective_type 'text'|'numeric'.
	 * @return array|WP_Error
	 */
	public function create_disposition( array $data ) {
		return $this->request( 'POST', '/dispositions/', $data );
	}

	/* ---------------------------------------------------------------- Web calls */

	/**
	 * POST /web-call/session — mint a browser (WebRTC/SIP) session for the Bolna Web Call SDK.
	 * Returns {run_id, agent_id, sip_username, sip_password, sip_domain, wss_url, sip_register, expires_in, ice_servers} unchanged.
	 *
	 * @param string $agent_id  Agent id.
	 * @param array  $user_data Variables for the agent prompt.
	 * @return array|WP_Error
	 */
	public function mint_web_session( $agent_id, array $user_data = array() ) {
		$agent_id = trim( (string) $agent_id );
		if ( '' === $agent_id ) {
			return $this->missing( 'agent id' );
		}
		return $this->request(
			'POST',
			'/web-call/session',
			array(
				'agent_id'  => $agent_id,
				'user_data' => empty( $user_data ) ? new stdClass() : $user_data,
			)
		);
	}

	/* --------------------------------------------------------------- Recordings */

	/**
	 * Download a call recording (e.g. https://api.bolna.ai/recordings/call/{execution_id}).
	 *
	 * The API key is only ever sent to Bolna hosts. Redirects (e.g. to a pre-signed storage URL) are
	 * followed manually so the Authorization header never leaks to the storage host.
	 *
	 * @param string $url Absolute recording URL.
	 * @return array|WP_Error ['body' => binary string, 'content_type' => string]
	 */
	public function fetch_recording( $url ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $this->fail( 'Bolna API: invalid recording URL.', 400 );
		}

		for ( $hop = 0; $hop < 6; $hop++ ) {
			$trusted = $this->is_bolna_url( $url );
			// Inside WordPress, any non-Bolna link (storage redirect, legacy URL) must be a safe public address (no SSRF).
			if ( ! $trusted && function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
				return $this->fail( 'Bolna API: refusing to download the recording from an unsafe address.', 502 );
			}
			$headers = array( 'Accept' => 'audio/*, application/octet-stream, application/json;q=0.5, */*;q=0.1' );
			if ( $trusted ) {
				if ( '' === $this->api_key ) {
					return $this->fail( 'Bolna API: the API key is missing.', 401 );
				}
				$headers['Authorization'] = 'Bearer ' . $this->api_key;
			}

			$response = $this->send( 'GET', $url, $headers, '', self::RECORDING_TIMEOUT, false );
			if ( isset( $response['error'] ) ) {
				return $this->fail( 'Could not download the recording: ' . $response['error'], 502 );
			}

			$status       = $response['status'];
			$content_type = strtolower( trim( (string) strtok( $response['headers']['content-type'], ';' ) ) );

			// Redirect (e.g. to a pre-signed S3 link): follow without our credentials unless it is Bolna again.
			if ( $status >= 300 && $status < 400 && '' !== $response['headers']['location'] ) {
				$url = $this->absolute_url( $response['headers']['location'], $url );
				continue;
			}

			if ( $status >= 200 && $status < 300 ) {
				// The endpoint may answer with the (pre-signed) file URL — as JSON or plain text — instead of redirecting.
				$is_media = 0 === strpos( $content_type, 'audio/' ) || 0 === strpos( $content_type, 'video/' );
				if ( ! $is_media && strlen( $response['body'] ) < 8192 ) {
					$trimmed = trim( $response['body'] );
					$next    = preg_match( '#^https?://\S+$#i', $trimmed ) ? $trimmed : $this->find_url( json_decode( $trimmed, true ) );
					if ( '' !== $next ) {
						$url = $next;
						continue;
					}
					if ( false !== strpos( $content_type, 'json' ) ) {
						return $this->fail( 'Bolna API: the recording response did not contain a file URL.', 502, $this->snippet( $response['body'] ) );
					}
				}
				if ( '' === $response['body'] ) {
					return $this->fail( 'Bolna API: the recording is empty.', 404 );
				}
				return array(
					'body'         => $response['body'],
					'content_type' => $this->audio_type( $content_type, $url, $response['body'] ),
				);
			}

			$decoded = json_decode( $response['body'], true );
			return $this->fail(
				sprintf( 'Bolna API %d: %s', $status, $this->error_message( $decoded, $response['body'] ) ),
				( $status >= 400 && $status < 600 ) ? $status : 502,
				is_array( $decoded ) ? $decoded : $this->snippet( $response['body'] )
			);
		}

		return $this->fail( 'Bolna API: too many redirects while downloading the recording.', 502 );
	}

	/* --------------------------------------------------------------------- Core */

	/**
	 * Call the Bolna API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path relative to the base URL ("/v2/agent/all").
	 * @param array|null $body   JSON body (null = no body).
	 * @param array      $query  Query string parameters.
	 * @return array|WP_Error Decoded JSON on 2xx (empty array when there is no body).
	 * @throws RuntimeException On failure when WordPress (WP_Error) is not available.
	 */
	public function request( string $method, string $path, $body = null, array $query = array() ) {
		if ( '' === $this->api_key ) {
			return $this->fail( 'Bolna API: the API key is missing.', 401 );
		}

		$method = strtoupper( trim( $method ) );
		$url    = $this->base_url . '/' . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$payload = '';
		if ( null !== $body ) {
			$payload = $this->encode_json( $body );
			if ( false === $payload ) {
				return $this->fail( 'Bolna API: the request body could not be encoded as JSON.', 400 );
			}
		}

		$response = $this->send(
			$method,
			$url,
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			$payload,
			self::TIMEOUT,
			true
		);
		if ( isset( $response['error'] ) ) {
			return $this->fail( 'Could not reach the Bolna API: ' . $response['error'], 502 );
		}

		$status  = $response['status'];
		$raw     = $response['body'];
		$decoded = '' === trim( $raw ) ? null : json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			if ( '' === trim( $raw ) ) {
				return array();
			}
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			return null === $decoded ? array( 'raw' => $this->snippet( $raw ) ) : array( 'value' => $decoded );
		}

		return $this->fail(
			sprintf( 'Bolna API %d: %s', $status, $this->error_message( $decoded, $raw ) ),
			( $status >= 400 && $status < 600 ) ? $status : 502,
			null !== $decoded ? $decoded : $this->snippet( $raw )
		);
	}

	/* ----------------------------------------------------------------- Internals */

	/**
	 * Perform an HTTP request with the WP HTTP API when available, else cURL.
	 *
	 * @param string $method   Method.
	 * @param string $url      Absolute URL.
	 * @param array  $headers  Request headers (name => value).
	 * @param string $body     Raw body ('' = none).
	 * @param int    $timeout  Timeout in seconds.
	 * @param bool   $follow   Follow redirects automatically.
	 * @return array ['status' => int, 'headers' => ['content-type' => string, 'location' => string], 'body' => string] or ['error' => string]
	 */
	private function send( $method, $url, array $headers, $body, $timeout, $follow ) {
		if ( function_exists( 'wp_remote_request' ) ) {
			$response = wp_remote_request(
				$url,
				array(
					'method'      => $method,
					'timeout'     => $timeout,
					'redirection' => $follow ? 5 : 0,
					'headers'     => $headers,
					'body'        => $body,
					'user-agent'  => $this->user_agent(),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array( 'error' => $response->get_error_message() );
			}
			return array(
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => array(
					'content-type' => $this->header_value( wp_remote_retrieve_header( $response, 'content-type' ) ),
					'location'     => $this->header_value( wp_remote_retrieve_header( $response, 'location' ) ),
				),
				'body'    => (string) wp_remote_retrieve_body( $response ),
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'error' => 'the PHP cURL extension is required to call the Bolna API outside WordPress' );
		}

		$lines = array( 'Expect:' );
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$response_headers = array();
		$options          = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_TIMEOUT        => (int) $timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => (bool) $follow,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_USERAGENT      => $this->user_agent(),
			CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$response_headers ) {
				$trimmed = trim( $line );
				if ( 0 === stripos( $trimmed, 'HTTP/' ) ) {
					$response_headers = array(); // New response (redirect hop / 100 Continue): keep the last one only.
				} elseif ( false !== strpos( $trimmed, ':' ) ) {
					$pair = explode( ':', $trimmed, 2 );

					$response_headers[ strtolower( trim( $pair[0] ) ) ] = trim( $pair[1] );
				}
				return strlen( $line );
			},
		);
		if ( 'POST' === $method ) {
			$options[ CURLOPT_POST ] = true;
		} elseif ( 'GET' !== $method ) {
			$options[ CURLOPT_CUSTOMREQUEST ] = $method;
		}
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$options[ CURLOPT_POSTFIELDS ] = (string) $body;
		}
		$ca_bundle = getenv( 'CURL_CA_BUNDLE' ) ? getenv( 'CURL_CA_BUNDLE' ) : getenv( 'SSL_CERT_FILE' );
		if ( $ca_bundle && is_readable( $ca_bundle ) ) {
			$options[ CURLOPT_CAINFO ] = $ca_bundle;
		} elseif ( '\\' === DIRECTORY_SEPARATOR && '' === (string) ini_get( 'curl.cainfo' ) && '' === (string) ini_get( 'openssl.cafile' ) ) {
			// Windows PHP builds usually ship without a CA bundle: verify against the Windows certificate store (libcurl 7.71+).
			$options[ CURLOPT_SSL_OPTIONS ] = defined( 'CURLSSLOPT_NATIVE_CA' ) ? CURLSSLOPT_NATIVE_CA : 16;
		}

		$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array( $handle, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		$raw = curl_exec( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		if ( false === $raw ) {
			$error = 'cURL error ' . curl_errno( $handle ) . ': ' . curl_error( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->close( $handle );
			return array( 'error' => $error );
		}
		$status = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$this->close( $handle );

		return array(
			'status'  => $status,
			'headers' => array(
				'content-type' => isset( $response_headers['content-type'] ) ? $response_headers['content-type'] : '',
				'location'     => isset( $response_headers['location'] ) ? $response_headers['location'] : '',
			),
			'body'    => (string) $raw,
		);
	}

	/**
	 * Close a cURL handle (curl_close() is a no-op since PHP 8 and deprecated in 8.5).
	 *
	 * @param resource|CurlHandle $handle Handle.
	 * @return void
	 */
	private function close( $handle ) {
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		}
	}

	/**
	 * Return a WP_Error (WordPress) or throw a RuntimeException (plain PHP).
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param mixed  $body    Response body.
	 * @return WP_Error
	 * @throws RuntimeException When WP_Error is not available.
	 */
	private function fail( $message, $status = 0, $body = null ) {
		if ( class_exists( 'WP_Error' ) ) {
			return new WP_Error(
				'voicepress_bolna',
				$message,
				array(
					'status' => (int) $status,
					'body'   => $body,
				)
			);
		}
		throw new RuntimeException( $message, (int) $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Error for a missing required argument.
	 *
	 * @param string $what Argument name.
	 * @return WP_Error
	 */
	private function missing( $what ) {
		return $this->fail( 'Bolna API: ' . $what . ' is required.', 400 );
	}

	/**
	 * Build a path with one URL-encoded id, or null when the id is empty.
	 *
	 * @param string $template sprintf template with one %s.
	 * @param string $id       Id.
	 * @return string|null
	 */
	private function id_path( $template, $id ) {
		$id = is_scalar( $id ) ? trim( (string) $id ) : '';
		return '' === $id ? null : sprintf( $template, rawurlencode( $id ) );
	}

	/**
	 * Human-readable error: body.message || body.detail || body.error || raw snippet.
	 *
	 * @param mixed  $decoded Decoded body.
	 * @param string $raw     Raw body.
	 * @return string
	 */
	private function error_message( $decoded, $raw ) {
		if ( is_array( $decoded ) ) {
			foreach ( array( 'message', 'detail', 'error' ) as $key ) {
				if ( ! isset( $decoded[ $key ] ) ) {
					continue;
				}
				$value = $decoded[ $key ];
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					return $this->snippet( $value );
				}
				if ( is_array( $value ) ) {
					// FastAPI validation errors: [{loc: [...], msg: '...'}].
					$parts = array();
					foreach ( $value as $item ) {
						if ( is_array( $item ) && isset( $item['msg'] ) && is_scalar( $item['msg'] ) ) {
							$location = isset( $item['loc'] ) && is_array( $item['loc'] ) ? implode( '.', array_map( 'strval', $item['loc'] ) ) . ': ' : '';
							$parts[]  = $location . $item['msg'];
						} elseif ( is_string( $item ) ) {
							$parts[] = $item;
						}
					}
					if ( isset( $value['message'] ) && is_string( $value['message'] ) ) {
						$parts[] = $value['message'];
					}
					return $this->snippet( $parts ? implode( '; ', $parts ) : (string) $this->encode_json( $value ) );
				}
				if ( is_scalar( $value ) && ! is_bool( $value ) ) {
					return (string) $value;
				}
			}
		}
		$snippet = $this->snippet( $raw );
		return '' !== $snippet ? $snippet : 'empty response';
	}

	/**
	 * First 300 characters of a (possibly HTML) body, whitespace collapsed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function snippet( $text ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 300, 'UTF-8' );
		}
		return substr( $text, 0, 300 );
	}

	/**
	 * JSON-encode with wp_json_encode() when available.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	private function encode_json( $data ) {
		if ( function_exists( 'wp_json_encode' ) ) {
			return wp_json_encode( $data );
		}
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Only Bolna hosts (the configured API host, or https *.bolna.ai) ever receive the API key.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_bolna_url( $url ) {
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( '' === $host ) {
			return false;
		}
		$base_host   = strtolower( (string) parse_url( $this->base_url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$base_scheme = strtolower( (string) parse_url( $this->base_url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( $host === $base_host && $scheme === $base_scheme ) {
			return true;
		}
		return 'https' === $scheme && ( 'bolna.ai' === $host || '.bolna.ai' === substr( $host, -9 ) );
	}

	/**
	 * Resolve a (possibly relative) Location header against the current URL.
	 *
	 * @param string $location Location header.
	 * @param string $current  Current URL.
	 * @return string
	 */
	private function absolute_url( $location, $current ) {
		$location = trim( $location );
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$parts  = parse_url( $current ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $location, '//' ) ) {
			return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		return $origin . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}

	/**
	 * Find a file URL inside a JSON recording response.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return string
	 */
	private function find_url( $data ) {
		if ( is_string( $data ) && preg_match( '#^https?://#i', $data ) ) {
			return $data;
		}
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( array( 'url', 'recording_url', 'presigned_url', 'signed_url', 'download_url', 'data' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$found = $this->find_url( $data[ $key ] );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	/**
	 * A content type an <audio> element can play.
	 *
	 * @param string $content_type Content type from the response.
	 * @param string $url          Final URL.
	 * @param string $body         File contents.
	 * @return string
	 */
	private function audio_type( $content_type, $url, $body ) {
		if ( 0 === strpos( $content_type, 'audio/' ) || 0 === strpos( $content_type, 'video/' ) ) {
			return $content_type;
		}
		$extension = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$by_ext    = array(
			'mp3'  => 'audio/mpeg',
			'wav'  => 'audio/wav',
			'ogg'  => 'audio/ogg',
			'oga'  => 'audio/ogg',
			'm4a'  => 'audio/mp4',
			'mp4'  => 'audio/mp4',
			'webm' => 'audio/webm',
		);
		if ( isset( $by_ext[ $extension ] ) ) {
			return $by_ext[ $extension ];
		}
		$magic = substr( $body, 0, 4 );
		if ( 'RIFF' === $magic ) {
			return 'audio/wav';
		}
		if ( 'OggS' === $magic ) {
			return 'audio/ogg';
		}
		return 'audio/mpeg';
	}

	/**
	 * Header value as a string (the WP HTTP API returns an array for repeated headers).
	 *
	 * @param mixed $value Header value.
	 * @return string
	 */
	private function header_value( $value ) {
		if ( is_array( $value ) ) {
			$value = end( $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * User agent.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'VoicePress/' . ( defined( 'VOICEPRESS_VERSION' ) ? VOICEPRESS_VERSION : self::VERSION ) . ( defined( 'ABSPATH' ) ? ' (WordPress)' : ' (PHP)' );
	}
}
