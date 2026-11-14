<?php

/**
 * Contract of RevealService
 *
 * The public surface of RevealService that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of RevealService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RevealServiceInterface {

	/**
	 * Answer a request: allow it and show what the reporter gave, or refuse it.
	 *
	 * @param array<string, mixed> $request The pending request.
	 * @param array<string, mixed> $report The report it is about.
	 * @param IUser $custodian The user answering.
	 * @param array<string, mixed> $caseType The case type, for the declaration.
	 * @param bool $allow Whether it is allowed.
	 * @param string $reason What the custodian says about their answer.
	 *
	 * @return array{state: string, contact?: array<string, mixed>}|array{error: string}
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each parameter is part
	 * of the record this decision writes.
	 */
	public function decide(array $request, array $report, IUser $custodian, array $caseType, bool $allow, string $reason = ''): array;

	/**
	 * Whether this user is a custodian for this case type's reports.
	 *
	 * @param IUser $user The user.
	 * @param array<string, mixed> $caseType The case type carrying the declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function isCustodian(IUser $user, array $caseType): bool;

	/**
	 * Whether this user may handle this case type's reports: read one, write
	 * on its thread and ask for a reveal. A member of the declared
	 * `handlerGroup` may, and so may a custodian. When no handler group is
	 * declared only the custodian group may. Being signed in, or being an
	 * instance administrator, is not enough (intake-report-pages D5,
	 * portaliq#799).
	 *
	 * @param IUser $user The user.
	 * @param array<string, mixed> $caseType The case type carrying the declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md
	 */
	public function mayHandle(IUser $user, array $caseType): bool;

	/**
	 * Ask to learn who filed a report.
	 *
	 * @param string $reportId The report.
	 * @param string $requestedBy Who asks.
	 * @param string $motivation Why they need to know.
	 *
	 * @return array<string, mixed>|null The recorded request, or null when it
	 *         carries no motivation and is therefore refused outright.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function request(string $reportId, string $requestedBy, string $motivation): ?array;
}//end interface
