<?php
/**
 * The HTTP function Transport uses on a real site: wp_remote_request,
 * normalised to a plain array so Transport can be tested without WordPress.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transport;

final class WpHttp {

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}|string The reply, or an error message.
	 */
	public function __invoke( string $method, string $url, array $headers, string $body ): array|string {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => '' === $body ? null : $body,
				'timeout'     => Transport::TIMEOUT,
				// A redirect would turn a POST into a GET or send a signed
				// request somewhere else. Report it instead.
				'redirection' => 0,
				'user-agent'  => 'Sceneshifter/' . SCENESHIFTER_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $out,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
