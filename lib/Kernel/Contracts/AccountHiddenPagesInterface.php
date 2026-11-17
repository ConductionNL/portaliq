<?php

/**
 * Contract of AccountHiddenPages
 *
 * The public surface of AccountHiddenPages that other components call, so they depend
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
 * Contract of AccountHiddenPages.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccountHiddenPagesInterface {

	/**
	 * The portal account with this subject reference, or null.
	 *
	 * @param string $subjectRef The account's subject reference.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function accountBySubjectRef(string $subjectRef): ?array;

	/**
	 * The pages the installed apps contribute for the account's audience,
	 * each with whether it is hidden for this account.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function listFor(array $account): array;

	/**
	 * Store the pages hidden for the account.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param mixed                $hidden  The page keys, as app:pageId.
	 *
	 * @return array<string, mixed>|null The saved account, or null when the write failed.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function save(array $account, mixed $hidden): ?array;
}//end interface
