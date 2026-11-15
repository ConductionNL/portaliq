<?php

/**
 * Expired Row Purge
 *
 * The expiry purge the traffic stores share.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Traffic
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
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use Throwable;

/**
 * The purge of rows past their `expires`. The using
 * store carries `$logger`, `objectService()`, the `REGISTER` constant and
 * its own one-by-one delete for a platform without the raw purge.
 *
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
 */
trait ExpiredRowPurge {
	/**
	 * Delete the expired rows through the ordinary path, one by one.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 *
	 * @return int How many were removed.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
	 */
	abstract protected function deleteExpired(object $objectService): int;

	/**
	 * Delete a schema's rows past their `expires`: in one raw statement when
	 * the platform has it, else one by one. A failure is logged and removes
	 * nothing more.
	 *
	 * @param string $schema  The schema.
	 * @param string $failure The log line for a failed purge.
	 *
	 * @return int How many were removed, or 0 when the platform cannot say.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-raw-events-must-be-retained-for-a-finite-configured-period
	 */
	private function purgeExpiredOf(string $schema, string $failure): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			if (method_exists($objectService, 'purgeExpiredObjectsRaw') === true) {
				return (int)$objectService->purgeExpiredObjectsRaw(self::REGISTER, $schema);
			}

			return $this->deleteExpired(objectService: $objectService);
		} catch (Throwable $e) {
			$this->logger->error($failure, ['reason' => $e->getMessage()]);

			return 0;
		}
	}//end purgeExpiredOf()
}//end trait
