<?php

/**
 * Portaliq Portal Report Owner Check
 *
 * Error reports on publications are a policy choice an organisation makes,
 * and a report that reaches nobody is worse than no report channel at all.
 * So a portal can only switch them on with an owner: a Nextcloud user or
 * group that exists.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#1-settings
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Whether a portal's error report settings may be saved.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#1-settings
 */
class PortalReportOwnerCheck {
	/**
	 * The portal property that switches error reports on.
	 */
	public const SWITCH = 'publicationErrorReports';

	/**
	 * The portal property naming the user or group that receives them.
	 */
	public const OWNER = 'publicationErrorReportOwner';

	/**
	 * Constructor.
	 *
	 * @param IUserManager  $users  Whether the owner is a user.
	 * @param IGroupManager $groups Whether the owner is a group.
	 * @param IL10N         $l10n   Words the refusal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Why a portal cannot be saved as it is, or null when it can.
	 *
	 * Error reports switched off pass whatever the owner says. Switched on,
	 * the owner must name an existing user or group; otherwise the refusal
	 * says the owner is missing, or names the one that does not exist. Only
	 * a literal true switches reports on, so a stray value fails closed.
	 *
	 * @param array<string, mixed> $portal The portal being written.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
	 */
	public function refusal(array $portal): ?string {
		if (($portal[self::SWITCH] ?? false) !== true) {
			return null;
		}

		$owner = '';
		if (is_string($portal[self::OWNER] ?? null) === true) {
			$owner = trim($portal[self::OWNER]);
		}

		if ($owner === '') {
			return $this->l10n->t('Error reports need an owner: name the user or group that receives them.');
		}

		if ($this->users->userExists($owner) === true || $this->groups->groupExists($owner) === true) {
			return null;
		}

		return $this->l10n->t('Error reports need an owner, and "%s" is not a user or group.', [$owner]);
	}//end refusal()
}//end class
