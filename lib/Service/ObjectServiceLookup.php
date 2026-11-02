<?php

/**
 * Object Service Lookup
 *
 * Resolves OpenRegister's ObjectService from the container on first use, so a
 * class that reads or writes objects still constructs when OpenRegister is
 * absent and degrades to "no service" instead.
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
 * @spec exclude Shared lookup helper; the @spec of each calling method covers the behaviour it reads or writes for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Throwable;

/**
 * OpenRegister's ObjectService, or null when it is not available.
 *
 * The using class holds the container in `$this->container` and names the
 * service in its `OBJECT_SERVICE` constant.
 *
 * @spec exclude Shared lookup helper; the @spec of each calling method covers the behaviour it reads or writes for.
 */
trait ObjectServiceLookup {

	/**
	 * OpenRegister's ObjectService, or null when it is not available.
	 *
	 * @return object|null
	 *
	 * @spec exclude Shared lookup helper; the @spec of each calling method covers the behaviour it reads or writes for.
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end objectService()
}//end trait
