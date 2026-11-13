<?php

/**
 * Contract of ActivityContributionService
 *
 * The public surface of ActivityContributionService that other components call, so they depend
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

use OCA\Portaliq\Service\ShillinqContributionRaiser;

/**
 * Contract of ActivityContributionService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityContributionServiceInterface {

	/**
	 * The activity does not exist.
	 */
	public const REASON_NOT_FOUND = 'not_found';

	/**
	 * The activity asks no contribution.
	 */
	public const REASON_NOT_REQUESTED = 'payment_not_requested';

	/**
	 * The sign-ups could not be read.
	 */
	public const REASON_UNAVAILABLE = 'activity_unavailable';

	/**
	 * The raise answers the service passes on from shillinq, named here so a
	 * caller maps one vocabulary.
	 */
	public const ERROR_INVALID = ShillinqContributionRaiser::ERROR_INVALID;

	public const ERROR_FORBIDDEN = ShillinqContributionRaiser::ERROR_FORBIDDEN;

	public const ERROR_SHILLINQ_UNAVAILABLE = ShillinqContributionRaiser::ERROR_UNAVAILABLE;

	public const ERROR_FAILED = ShillinqContributionRaiser::ERROR_FAILED;

	/**
	 * Raise the contribution for every confirmed place without a reference.
	 *
	 * @param string $activityId The activity id or slug.
	 * @param array<string, mixed> $params The request: amount, voluntary, administrationId, and the optional keys.
	 *
	 * @return array<string, mixed> `{raised, skipped, failed, results}`, or `['error' => <code>]`.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
	 */
	public function raise(string $activityId, array $params): array;
}//end interface
