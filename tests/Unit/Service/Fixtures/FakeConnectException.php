<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Fixtures;

use RuntimeException;

/**
 * A transport failure shaped like Guzzle's ConnectException: no response,
 * and the cURL handler context (errno, connect_time) beside the message.
 */
class FakeConnectException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The cURL message.
	 * @param array<string, mixed> $context The handler context.
	 */
	public function __construct(string $message, private readonly array $context = []) {
		parent::__construct($message);
	}

	/**
	 * The cURL handler context, as Guzzle hands it over.
	 *
	 * @return array<string, mixed>
	 */
	public function getHandlerContext(): array {
		return $this->context;
	}
}
