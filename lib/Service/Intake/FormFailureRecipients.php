<?php

/**
 * Portaliq Form Failure Recipients
 *
 * Who gets a portal's digest of failed submits: the members of the groups the
 * portal names in `adminGroups`, or the instance administrators when it names none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCP\IGroupManager;
use Throwable;

/**
 * The portal row and its administrators' addresses.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class FormFailureRecipients {
	/**
	 * The group whose members get the digest of a portal that names no `adminGroups`.
	 */
	private const INSTANCE_ADMINS = 'admin';

	/**
	 * The published portals by slug, read once.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $published = null;

	/**
	 * Constructor.
	 *
	 * @param PortalResolverInterface $portals The published portals.
	 * @param IGroupManager           $groups  Who is in which group.
	 */
	public function __construct(
		private readonly PortalResolverInterface $portals,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * The published portal with this slug, or a row with only the slug.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed> The portal.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function portal(string $slug): array {
		if ($this->published === null) {
			$this->published = [];
			try {
				$rows = $this->portals->allPublishedPortals();
			} catch (Throwable) {
				$rows = [];
			}

			foreach ($rows as $row) {
				if (is_array($row) === true && is_string($row['slug'] ?? null) === true) {
					$this->published[$row['slug']] = $row;
				}
			}
		}

		return ($this->published[$slug] ?? ['slug' => $slug]);
	}//end portal()

	/**
	 * The e-mail addresses of the portal's administrators, once each.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array<int, string> The addresses.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function addresses(array $portal): array {
		$groups = array_filter((array)($portal['adminGroups'] ?? []), static fn (mixed $gid): bool => is_string($gid) === true && trim($gid) !== '');
		if ($groups === []) {
			$groups = [self::INSTANCE_ADMINS];
		}

		$addresses = [];
		foreach ($groups as $gid) {
			foreach (($this->groups->get(trim($gid))?->getUsers() ?? []) as $user) {
				$address = trim((string)$user->getEMailAddress());
				if ($address !== '') {
					$addresses[$address] = true;
				}
			}
		}

		return array_keys($addresses);
	}//end addresses()
}//end class
