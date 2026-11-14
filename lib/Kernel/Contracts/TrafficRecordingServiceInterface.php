<?php

/**
 * Contract of TrafficRecordingService
 *
 * The public surface of TrafficRecordingService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of TrafficRecordingService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficRecordingServiceInterface {

	/**
	 * Store one chunk for a resolved portal.
	 *
	 * @param array<string, mixed> $portal  The portal record.
	 * @param array<string, mixed> $body    The posted chunk: `recording`, `events`, `page`, `sessionId`, `elapsed`.
	 * @param array<string, mixed> $context `consent` => bool.
	 *
	 * @return array{ok: bool, reason: string} The outcome; the reason is '' when stored.
	 *
	 * @spec openspec/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
	 */
	public function ingest(array $portal, array $body, array $context = []): array;
}//end interface
