<?php

/**
 * Portaliq Container Service
 *
 * An optional service from the container: OpenRegister's, which may not be
 * installed.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Looks a service up in the container, answering null rather than throwing
 * when it is absent or not an object.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
 */
final class ContainerService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container to look in.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The service registered under an id, or null when it is not available.
	 *
	 * @param string $id The service id.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
	 */
	public function get(string $id): ?object {
		try {
			$service = $this->container->get($id);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end get()
}//end class
