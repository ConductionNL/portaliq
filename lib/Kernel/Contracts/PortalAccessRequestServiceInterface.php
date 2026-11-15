<?php

/**
 * Contract of PortalAccessRequestService
 *
 * The public surface of PortalAccessRequestService that other components call, so they depend
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
 * Contract of PortalAccessRequestService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAccessRequestServiceInterface {

	/**
	 * The request was answered as asked.
	 */
	public const OUTCOME_DONE = 'done';

	/**
	 * The request was already answered.
	 */
	public const OUTCOME_NOT_PENDING = 'not_pending';

	/**
	 * The grant could not record its mandate, so the request stays pending.
	 */
	public const OUTCOME_MANDATE_FAILED = 'mandate_failed';

	/**
	 * The requests an owner has to answer, for their tenant.
	 *
	 * @param string $organisation The tenant.
	 * @param string $state The state to list, or '' for every state.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function forOwner(string $organisation, string $state = 'pending'): array;

	/**
	 * Grant a request and record the mandate it opens, in one act.
	 *
	 * A request never reads `granted` without the mandate behind it: the
	 * mandate is written first, and when it cannot be written the request is
	 * left `pending` and the answer says so (identity-access-requests D2). When
	 * the request can no longer be answered after all, the new mandate is
	 * revoked again, so no access stands without a granted request.
	 *
	 * @param string $id The request's id.
	 * @param string $organisation The tenant, re-checked against the row.
	 * @param string $decidedBy The staff user granting it.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-a-granted-request-opens-the-cases-req-iar-003
	 */
	public function grant(string $id, string $organisation, string $decidedBy): string;

	/**
	 * The requests one asker has made, with the answers they were given.
	 *
	 * @param string $subjectRef The asker.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function madeBy(string $subjectRef): array;

	/**
	 * Refuse a request, keeping the reason the asker will read.
	 *
	 * @param string $id The request's id.
	 * @param string $organisation The tenant, re-checked against the row.
	 * @param string $reason Why it is refused.
	 * @param string $decidedBy The staff user refusing it.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
	 */
	public function refuse(string $id, string $organisation, string $reason, string $decidedBy): string;

	/**
	 * Ask for access.
	 *
	 * @param string $subjectRef Who asks.
	 * @param string $organisation The tenant whose cases are asked for.
	 * @param string $onBehalfOf The party whose cases are asked for.
	 * @param string $reason What they say they need it for.
	 * @param string $displayName The name the owner sees.
	 *
	 * @return array<string, mixed>|null The recorded request, or null when the
	 *         ask carries neither an identity nor a reason.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function request(string $subjectRef, string $organisation, string $onBehalfOf = '', string $reason = '', string $displayName = ''): ?array;
}//end interface
