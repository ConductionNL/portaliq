<?php

/**
 * Portaliq Branch Choice
 *
 * Which branches a whole-company business session may narrow to
 * (signin-eherkenning-branch T05, T06, REQ-SEB-003): the branches the KvK
 * holds for the KvK number on the caller's own eHerkenning account, read
 * through identity-registered-details. A session the login restricted to a
 * branch is offered nothing and allowed nothing. Without a readable branch
 * list no branch is allowed: fail closed.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Branch
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
 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Branch;

use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\PortalAccountService;

/**
 * The branches a session may choose.
 *
 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
 */
class BranchChoice {

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService           $accounts Finds the caller's account.
	 * @param PortalRegisteredDetailsService $details  Reads the company's KvK branches.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly PortalRegisteredDetailsService $details,
	) {
	}//end __construct()

	/**
	 * The branches a session may choose; empty for a restricted session, a
	 * person, or a company whose branches cannot be read.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	public function branchesFor(array $subject): array {
		if (($subject['branchRestricted'] ?? false) === true) {
			return [];
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$account    = null;
		if ($subjectRef !== '') {
			$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		}

		if ($account === null || (string)($account['identityType'] ?? '') !== 'eherkenning') {
			return [];
		}

		return ($this->details->companyBranches(kvkNumber: trim((string)($account['identityRef'] ?? ''))) ?? []);
	}//end branchesFor()

	/**
	 * Whether a session may choose one branch, or '' for the whole company.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string               $branch  The branch number, or ''.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	public function allows(array $subject, string $branch): bool {
		if (($subject['branchRestricted'] ?? false) === true) {
			return false;
		}

		if ($branch === '') {
			return true;
		}

		return in_array($branch, array_column($this->branchesFor(subject: $subject), 'number'), true);
	}//end allows()
}//end class
