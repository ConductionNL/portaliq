<?php

/**
 * Contract of PortalMandateService
 *
 * The public surface of PortalMandateService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PortalMandateService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalMandateServiceInterface {

	/**
	 * A mandate covering the entity it names and those below it.
	 */
	public const REACH_TREE = 'tree';

	/**
	 * The mandate the identity is acting under, out of the ones it holds.
	 *
	 * A named mandate must be one of their own, so naming somebody else's in
	 * the switch request changes nothing. With none named, the first is used,
	 * which is what a person holding exactly one mandate expects.
	 *
	 * @param array<int, array<string, mixed>> $mandates The live mandates.
	 * @param string $mandateId The mandate named by the switch, or ''.
	 *
	 * @return array<string, mixed>|null The active mandate, or null when the
	 *                                   identity holds none.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function activeMandate(array $mandates, string $mandateId = ''): ?array;

	/**
	 * The words shown beside a case this mandate grants, so a citizen can see
	 * why they may read it.
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 *
	 * @return array<string, mixed> The id, label, organisation and party.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function describe(array $mandate): array;

	/**
	 * The mandates this identity holds that still count.
	 *
	 * @param string $subjectRef The portal identity.
	 * @param string $organisation The tenant, or '' for every tenant.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 * @param array<int, string> $holders The parties the session carries beyond itself (`kvk:<8 digits>`).
	 *
	 * @return array<int, array<string, mixed>> The live mandates, never null.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function mandatesFor(string $subjectRef, string $organisation = '', ?DateTimeImmutable $now = null, array $holders = []): array;

	/**
	 * How far down the party tree this mandate reaches.
	 *
	 * Anything other than the declared wider reach is the narrow one: a
	 * mandate that says nothing, or says something nobody recognises, covers
	 * the entity it names and no other (REQ-PTV-001).
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function reachOf(array $mandate): string;
}//end interface
