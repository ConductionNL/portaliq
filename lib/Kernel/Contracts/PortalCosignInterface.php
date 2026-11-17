<?php

/**
 * Contract of PortalCosign
 *
 * The public surface of PortalCosign that other components call, so they depend
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
 * Contract of PortalCosign.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalCosignInterface {

	/**
	 * Co-sign states.
	 *
	 * @var string
	 */
	public const AWAITING = 'awaiting';

	/**
	 * Co-sign states.
	 *
	 * @var string
	 */
	public const SIGNED = 'signed';

	/**
	 * Co-sign states.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * Co-sign states.
	 *
	 * @var string
	 */
	public const EXPIRED = 'expired';

	/**
	 * The resident's own requests on a portal that wait for a co-signer or were not co-signed.
	 *
	 * @param string $subjectRef The resident's own portal identity.
	 * @param string $portal     The portal's slug.
	 *
	 * @return array<int, array<string, mixed>> Each with reference, route, submittedAt, state and the cosign line.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function heldBy(string $subjectRef, string $portal): array;

	/**
	 * The held submission behind a code, read-only, for the co-signer's review page.
	 *
	 * @param string            $code       The code the co-signer typed.
	 * @param string            $subjectRef The co-signer's DigiD subject.
	 * @param DateTimeImmutable $now        The moment.
	 *
	 * @return array{ok: bool, reason: string, submission: array<string, mixed>}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function open(string $code, string $subjectRef, DateTimeImmutable $now): array;

	/**
	 * Co-sign: record who signed, when and at what assurance, and release the submission for delivery.
	 *
	 * @param string            $code             The code.
	 * @param string            $subjectRef       The co-signer's DigiD subject.
	 * @param string            $levelOfAssurance The level the sign-in stated.
	 * @param DateTimeImmutable $now              The moment.
	 *
	 * @return array{ok: bool, reason: string, reference: string, portal?: string, cosign?: array<string, mixed>}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function sign(string $code, string $subjectRef, string $levelOfAssurance, DateTimeImmutable $now): array;
}//end interface
