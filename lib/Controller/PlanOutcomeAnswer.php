<?php

/**
 * Portaliq Plan Outcome Answer
 *
 * The HTTP answer for one outcome of the plan and action services.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec exclude Shared answer helper; the @spec of each calling route covers the behaviour it answers for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Maps a PortalPlanService outcome to its answer.
 *
 * @spec exclude Shared answer helper; the @spec of each calling route covers the behaviour it answers for.
 */
final class PlanOutcomeAnswer {
	/**
	 * The answer for a service outcome.
	 *
	 * @param string $result One of the PortalPlanService outcomes.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude Shared answer helper; the @spec of each calling route covers the behaviour it answers for.
	 */
	public function for(string $result): JSONResponse {
		$status = match ($result) {
			PortalPlanService::OK => Http::STATUS_OK,
			PortalPlanService::NOT_FOUND => Http::STATUS_NOT_FOUND,
			PortalPlanService::FORBIDDEN => Http::STATUS_FORBIDDEN,
			PortalPlanService::INVALID => Http::STATUS_BAD_REQUEST,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
		if ($status === Http::STATUS_OK) {
			return new JSONResponse(['ok' => true]);
		}

		return new JSONResponse(['error' => $result], $status);
	}//end for()
}//end class
