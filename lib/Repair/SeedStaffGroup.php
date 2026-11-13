<?php

/**
 * Seed Staff Group Repair Step
 *
 * Creates portaliq's staff group and, once, puts into it everybody who could
 * already work in portaliq: the members of the configured editor groups and
 * of every group the action matrix grants an action. The register names only
 * the staff group since close-portaliq-register-to-staff, so without this
 * step those people would lose the records they work with on the upgrade.
 *
 * Administrators are not copied (they bypass OpenRegister's checks), and the
 * group the registration app puts self-registered accounts in is never
 * copied, so a resident never becomes staff through a misconfigured matrix.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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
 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-the-staff-group-exists-and-keeps-everybody-who-already-worked-in-portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Portaliq\Kernel\Contracts\AdminMenuAccessInterface;
use OCA\Portaliq\Kernel\Contracts\NextcloudRegistrationDoorInterface;
use OCA\Portaliq\Kernel\Contracts\PageEditorServiceInterface;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Create the staff group and seed it once from the editor groups and the action matrix.
 *
 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-the-staff-group-exists-and-keeps-everybody-who-already-worked-in-portaliq
 */
class SeedStaffGroup implements IRepairStep {

	/**
	 * The app config flag that records the one-time seeding.
	 */
	public const MARKER = 'staff_group_seeded';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager     $groupManager Creates the group and resolves members.
	 * @param IAppConfig        $appConfig    The seeding flag and the registration app's group.
	 * @param PageEditorServiceInterface $pageEditor   The configured editor groups.
	 * @param ActionAuthServiceInterface $actions      The action matrix.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
		private readonly PageEditorServiceInterface $pageEditor,
		private readonly ActionAuthServiceInterface $actions,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Create the portaliq staff group and put the current editors and action holders in it';
	}//end getName()

	/**
	 * Create the group, then seed it once.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-the-staff-group-exists-and-keeps-everybody-who-already-worked-in-portaliq
	 */
	public function run(IOutput $output): void {
		$group = $this->staffGroup();
		if ($group === null) {
			$output->warning('Staff group: "'.AdminMenuAccessInterface::STAFF_GROUP.'" could not be created, seeding left for the next upgrade.');
			return;
		}

		if ($this->appConfig->getValueBool(AppIdentity::APP_ID, self::MARKER, false) === true) {
			return;
		}

		$added = 0;
		foreach ($this->sourceGroups() as $gid) {
			$source = $this->groupManager->get($gid);
			if ($source === null) {
				continue;
			}

			foreach ($source->getUsers() as $user) {
				if ($group->inGroup($user) === true) {
					continue;
				}

				$group->addUser($user);
				$added++;
			}
		}

		$this->appConfig->setValueBool(AppIdentity::APP_ID, self::MARKER, true);
		$output->info('Staff group: '.$added.' people added to "'.AdminMenuAccessInterface::STAFF_GROUP.'".');
	}//end run()

	/**
	 * The staff group, created when it does not exist yet.
	 *
	 * @return IGroup|null The group, or null when it could not be created.
	 */
	private function staffGroup(): ?IGroup {
		$group = $this->groupManager->get(AdminMenuAccessInterface::STAFF_GROUP);
		if ($group !== null) {
			return $group;
		}

		return $this->groupManager->createGroup(AdminMenuAccessInterface::STAFF_GROUP);
	}//end staffGroup()

	/**
	 * The groups whose members already worked in portaliq.
	 *
	 * The editor groups and every group the action matrix names, without
	 * `admin`, the staff group itself and the registration app's group.
	 *
	 * @return array<string> The group ids.
	 */
	private function sourceGroups(): array {
		$groups = $this->pageEditor->getEditorGroups();
		foreach ($this->actions->getMatrix() as $allowed) {
			foreach ($allowed as $gid) {
				$groups[] = $gid;
			}
		}

		$excluded = ['admin', AdminMenuAccessInterface::STAFF_GROUP, $this->registrationGroup()];

		return array_values(
			array_unique(
				array_filter(
					$groups,
					static fn (string $gid): bool => $gid !== '' && in_array($gid, $excluded, true) === false
				)
			)
		);
	}//end sourceGroups()

	/**
	 * The group the registration app puts self-registered accounts in, or ''.
	 *
	 * @return string
	 */
	private function registrationGroup(): string {
		try {
			$group = trim(
				$this->appConfig->getValueString(
					NextcloudRegistrationDoorInterface::REGISTRATION_APP,
					NextcloudRegistrationDoorInterface::REGISTRATION_GROUP_KEY,
					''
				)
			);
		} catch (Throwable) {
			return '';
		}

		if ($group === 'none') {
			return '';
		}

		return $group;
	}//end registrationGroup()
}//end class
