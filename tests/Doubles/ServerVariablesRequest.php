<?php

/**
 * Request double that answers the magic `server` property.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Doubles;

/**
 * A request whose server variables are the ones a test gives.
 */
class ServerVariablesRequest extends UrlParameterRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $server The server variables.
	 */
	public function __construct(
		private readonly array $server,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The server variables under `server`, else what the parent answers.
	 *
	 * @param string $name The property.
	 *
	 * @return mixed
	 */
	public function __get(string $name): mixed {
		if ($name === 'server') {
			return $this->server;
		}

		return parent::__get($name);
	}//end __get()
}//end class
