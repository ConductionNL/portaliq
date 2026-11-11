<?php

/**
 * Portaliq Portal Object Counter
 *
 * Counts the objects of a register and schema through OpenRegister's own
 * `count()`, so only a number travels, never a row. Split out of
 * PortalObjectWriter: counting reads, and the writer's callers write.
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
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts objects over OpenRegister.
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 */
class PortalObjectCounter {
	use ObjectServiceLookup;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Count the objects in a register/schema (portal-session-hardening-v2 —
	 * the count-only exposure). Uses OpenRegister's native `count()` rather
	 * than fetching rows, so the contents never travel through this path —
	 * only a number does. Fails closed to 0 when OpenRegister is unavailable
	 * or errors; a caller must never fail because the register is unreachable.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug.
	 *
	 * @return int The count, or 0 on failure.
	 *
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
	 */
	public function countObjects(string $register, string $schema): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
			return $objectService->count(config: ['filters' => []]);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: OR count failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return 0;
		}
	}//end countObjects()
}//end class
