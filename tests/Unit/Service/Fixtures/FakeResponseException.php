<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Fixtures;

use OCP\Http\Client\IResponse;
use RuntimeException;

/**
 * A failure that carries an HTTP response, like Guzzle's RequestException
 * when `http_errors` is on: the server answered, so it is not transport.
 */
class FakeResponseException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param IResponse $response The answer that came with the failure.
	 */
	public function __construct(private readonly IResponse $response) {
		parent::__construct('cURL error 7: wrapped around a real answer');
	}

	/**
	 * The response the server gave.
	 *
	 * @return IResponse
	 */
	public function getResponse(): IResponse {
		return $this->response;
	}

	/**
	 * A context that would otherwise read as a connect failure.
	 *
	 * @return array<string, mixed>
	 */
	public function getHandlerContext(): array {
		return ['errno' => 7];
	}
}
