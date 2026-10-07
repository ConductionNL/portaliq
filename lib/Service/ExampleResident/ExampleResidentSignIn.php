<?php

/**
 * Portaliq Example Resident Sign In
 *
 * What the site may offer for a portal's example resident: the one-click
 * demo sign-in while the switch `example_resident_demo_login` is `yes`, and,
 * while it is off, the name of the way in the resident's install added, so
 * the site leaves that demo card out.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleResident
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
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use OCP\IConfig;

/**
 * Reads the demo switch and the installed example resident of a portal.
 *
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */
class ExampleResidentSignIn {

	/**
	 * Constructor.
	 *
	 * @param IConfig|null                  $config    The app config, for the switch.
	 * @param ExampleResidentRecord|null    $records   The install records.
	 * @param ExampleResidentCatalogue|null $catalogue The shipped declarations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IConfig $config,
		private readonly ?ExampleResidentRecord $records,
		private readonly ?ExampleResidentCatalogue $catalogue,
	) {
	}//end __construct()

	/**
	 * The id of the example resident the one-click demo sign-in offers on
	 * this portal: the switch is `yes`, the resident is installed (its record
	 * names a user id) and declares this portal as its own. '' otherwise, so
	 * the site keeps the account path.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string The resident's id, or ''.
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function offered(array $portal): string {
		if ($this->switchedOn() === false) {
			return '';
		}

		return $this->installedFor(portal: $portal);
	}//end offered()

	/**
	 * The sign-in mode the installed example resident of this portal added
	 * to it, while the switch is off; '' while it is on, when no resident is
	 * installed here, or when its install added no mode.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string The mode, or ''.
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	public function hiddenWayIn(array $portal): string {
		if ($this->switchedOn() === true || $this->records === null) {
			return '';
		}

		$id = $this->installedFor(portal: $portal);
		if ($id === '') {
			return '';
		}

		return $this->records->read(id: $id)['signIn']['mode'];
	}//end hiddenWayIn()

	/**
	 * Whether the administrator switched the one-click demo sign-in on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	private function switchedOn(): bool {
		return $this->config !== null
			&& $this->config->getAppValue('portaliq', 'example_resident_demo_login', 'no') === 'yes';
	}//end switchedOn()

	/**
	 * The id of the example resident installed for this portal: its
	 * declaration names this portal and its record names a user id.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string The resident's id, or ''.
	 *
	 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
	 */
	private function installedFor(array $portal): string {
		$slug = (string)($portal['slug'] ?? '');
		if ($this->records === null || $this->catalogue === null || $slug === '') {
			return '';
		}

		foreach ($this->catalogue->ids() as $id) {
			$declared = $this->catalogue->find(id: $id);
			if ($declared !== null && (string)($declared['portal'] ?? '') === $slug
				&& $this->records->read(id: $id)['userId'] !== ''
			) {
				return $id;
			}
		}

		return '';
	}//end installedFor()
}//end class
