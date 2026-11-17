<?php

/**
 * Contract of OnBehalfFilling
 *
 * The public surface of OnBehalfFilling that other components call, so they depend
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
 * Contract of OnBehalfFilling.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OnBehalfFillingInterface {

	/**
	 * The provider a desk session carries, telling it from a resident's.
	 */
	public const PROVIDER = 'desk';

	/**
	 * The audience of a desk session.
	 */
	public const AUDIENCE = 'desk';

	/**
	 * The audit verb of a BSN or KvK lookup by an employee.
	 */
	public const LOOKUP_VERB = 'desk.lookup';

	/**
	 * Whether the form allows an employee to fill it in.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function offered(array $binding): bool;

	/**
	 * The employee, when this Nextcloud user may fill this form in on this
	 * portal: the form allows it, the portal names a desk group, and the user
	 * is in it. Null otherwise, so a portal without a desk group has no staff
	 * mode at all.
	 *
	 * @param array<string, mixed> $site    The portal.
	 * @param array<string, mixed> $binding The form binding.
	 * @param string               $uid     The Nextcloud user id.
	 *
	 * @return array{uid: string, displayName: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function employee(array $site, array $binding, string $uid): ?array;

	/**
	 * The applicant an employee named, normalised, or null when it is not one:
	 * a BSN that passes the elfproef, an 8-digit KvK number, or a name of at
	 * most 200 characters for an employee who fills it in under their own name.
	 *
	 * @param mixed $raw The request's `onBehalf`: `{kind, value}`.
	 *
	 * @return array{kind: string, value: string}|null
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function applicant(mixed $raw): ?array;

	/**
	 * Look the applicant up and prefill the form's applicant fields.
	 *
	 * A BSN is read from the BRP, a KvK number from the KvK, each through
	 * OpenRegister's providers, and every such lookup writes an audit line
	 * naming the employee and the number. A named applicant is not looked up.
	 *
	 * @param array<string, mixed>             $site      The portal.
	 * @param array{uid: string, displayName: string} $employee The employee.
	 * @param array{kind: string, value: string} $applicant The applicant.
	 * @param array<int, array<string, mixed>> $fields    The form's fields.
	 *
	 * @return array{available: bool, prefill: array<string, string>, reason: string}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function lookUp(array $site, array $employee, array $applicant, array $fields): array;

	/**
	 * What the server records about who filled the form in, for the
	 * submission and the delivery: `filledBy` and `applicant`.
	 *
	 * @param array{uid: string, displayName: string} $employee  The employee.
	 * @param array{kind: string, value: string}      $applicant The applicant.
	 *
	 * @return array{filledBy: array{uid: string, displayName: string}, applicant: array{kind: string, value: string}}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
	 */
	public function serverValues(array $employee, array $applicant): array;
}//end interface
