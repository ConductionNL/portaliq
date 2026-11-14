<?php

/**
 * Portaliq Portal Admin Write Gate
 *
 * Decides whether a write a portal administrator makes stays inside their
 * own portal.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Kernel\Contracts\PageEditorServiceInterface;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * The refusal for one content or portal write, or null.
 *
 * What the schema rules cannot say: a portal's administrator may not move a
 * page, menu, notice or media item to or from a portal they do not look
 * after, and only an instance administrator changes a portal's
 * `adminGroups`. An instance administrator, a member of the instance-wide
 * editor groups and a write without a signed-in user (occ, background jobs)
 * are not this gate's concern; OpenRegister's own rules still apply to them.
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */
class PortalAdminWriteGate {

	/**
	 * Constructor.
	 *
	 * @param IUserSession               $userSession The signed-in user.
	 * @param PortalAdminScope           $scope       Who may administer which portal.
	 * @param PageEditorServiceInterface $pageEditor  The instance-wide editor groups.
	 * @param IL10N                      $l10n        Words the refusal.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly PortalAdminScope $scope,
		private readonly PageEditorServiceInterface $pageEditor,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Why this write is refused, or null when it may go ahead.
	 *
	 * @param string                    $schema The schema slug of the row.
	 * @param array<string, mixed>      $row    The row as it will be (or, on delete, as it is).
	 * @param array<string, mixed>|null $before The row before an update; null on create and delete.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function refusal(string $schema, array $row, ?array $before = null): ?string {
		$field = (PortalAdminGrants::SCHEMAS[$schema] ?? null);
		if ($field === null || $this->userSession->getUser() === null || $this->scope->isInstanceAdmin() === true) {
			return null;
		}

		if ($schema === 'portal') {
			return $this->portalRefusal(row: $row, before: $before);
		}

		if ($this->pageEditor->mayEdit() === true) {
			return null;
		}

		$portals = [(string)($row[$field] ?? '')];
		if ($before !== null) {
			$portals[] = (string)($before[$field] ?? '');
		}

		foreach (array_unique($portals) as $slug) {
			if ($this->scope->mayAdministerSlug(slug: $slug) === false) {
				return $this->l10n->t('You do not look after this portal.');
			}
		}

		return null;
	}//end refusal()

	/**
	 * A portal write by someone who is not an instance administrator: refused
	 * when it names or changes `adminGroups`.
	 *
	 * @param array<string, mixed>      $row    The portal as it will be.
	 * @param array<string, mixed>|null $before The portal before an update.
	 *
	 * @return string|null
	 */
	private function portalRefusal(array $row, ?array $before): ?string {
		$after    = PortalAdminScope::adminGroupsOf(portal: $row);
		$previous = [];
		if ($before !== null) {
			$previous = PortalAdminScope::adminGroupsOf(portal: $before);
		}

		sort($after);
		sort($previous);
		if ($after === $previous) {
			return null;
		}

		return $this->l10n->t('Only an instance administrator can change who looks after a portal.');
	}//end portalRefusal()
}//end class
