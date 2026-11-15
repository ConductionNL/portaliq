<?php

/**
 * Contract of PortalRegisteredDetailsService
 *
 * The public surface of PortalRegisteredDetailsService that other components call, so they depend
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
 * Contract of PortalRegisteredDetailsService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRegisteredDetailsServiceInterface {

	/**
	 * The registered details of the account behind one subject.
	 *
	 * @param string $subjectRef The caller's subject, from the bearer.
	 *
	 * @return array<string, mixed> `{available: true, kind, person|company}` or
	 *                              `{available: false, reason}` with reason one of
	 *                              no_account, no_registration_identifier,
	 *                              source_unavailable, not_found.
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-sees-their-own-brp-record-req-ird-001
	 */
	public function forSubject(string $subjectRef): array;

	/**
	 * The person record the BRP holds for one BSN.
	 *
	 * @param string $bsn The BSN.
	 *
	 * @return array<string, mixed> `{available: true, kind: person, person}` or `{available: false, reason}`.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
	 */
	public function forBsn(string $bsn): array;

	/**
	 * The company record the KvK holds for one KvK number.
	 *
	 * @param string $kvkNumber The 8-digit KvK number.
	 *
	 * @return array<string, mixed> `{available: true, kind: company, company}` or `{available: false, reason}`.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#T06
	 */
	public function forKvkNumber(string $kvkNumber): array;
}//end interface
