<?php

/**
 * Portaliq Loopback Retry
 *
 * One retry of a self-call on the loopback origins, after a transport
 * failure on the absolute URL: the request, the failure, and what each
 * origin answered.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/loopback-tries-the-address-php-was-reached-on/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-on-the-addresses-this-server-answers-on
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Throwable;

/**
 * The state of one InstanceLoopback retry. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/changes/loopback-tries-the-address-php-was-reached-on/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-on-the-addresses-this-server-answers-on
 */
final class LoopbackRetry {
	/**
	 * Each origin's failure message, by origin.
	 *
	 * @var array<string, string>
	 */
	public array $failures = [];

	/**
	 * Whether an origin failed after the connect phase, so no further origin
	 * may be tried: the request reached a server and is never sent twice.
	 *
	 * @var bool
	 */
	public bool $stopped = false;

	/**
	 * Constructor.
	 *
	 * @param string               $method   The HTTP method.
	 * @param string               $absolute The absolute URL that failed.
	 * @param array<string, mixed> $options  The client options.
	 * @param Throwable            $failure  The transport failure on the absolute URL.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $absolute,
		public readonly array $options,
		public readonly Throwable $failure,
	) {
	}//end __construct()
}//end class
