<?php

/**
 * UnitServer
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Stubs\Nextcloud;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * The server container of a run without Nextcloud: a request with a fixed id,
 * the system clock and no signed-in user, which is all an OCP Response reads
 * when it builds its headers. Any other service is not found, as in a
 * container that has no registration for it.
 */
class UnitServer implements ContainerInterface {
	/**
	 * The service for an id.
	 *
	 * @param string $id The service id.
	 *
	 * @return object
	 */
	public function get(string $id) {
		return match ($id) {
			IRequest::class => new class {
				/**
				 * The request id.
				 *
				 * @return string
				 */
				public function getId(): string {
					return 'portaliq-unit-request';
				}
			},
			ITimeFactory::class => new class {
				/**
				 * The current Unix time.
				 *
				 * @return int
				 */
				public function getTime(): int {
					return time();
				}
			},
			IUserSession::class => new class {
				/**
				 * No signed-in user.
				 *
				 * @return object|null
				 */
				public function getUser(): ?object {
					return null;
				}
			},
			default => throw new class('No service ' . $id . ' in a run without Nextcloud.') extends RuntimeException implements NotFoundExceptionInterface {
			},
		};
	}//end get()

	/**
	 * Whether the container answers an id.
	 *
	 * @param string $id The service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return in_array($id, [IRequest::class, ITimeFactory::class, IUserSession::class], true);
	}//end has()
}//end class
