<?php

/**
 * Portaliq Example Resident Sign In
 *
 * What the site may offer for a portal's example residents: one one-click
 * demo sign-in per resident while the switch `example_resident_demo_login`
 * is `yes`, and nothing while it is off. The residents are the ones
 * OpenRegister's example-set loader installed (ExampleSetResidents). The
 * cards sit beside the portal's own ways in; they never replace one.
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
 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use OCP\IConfig;

/**
 * Reads the demo switch and the installed example residents of a portal.
 *
 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
 */
class ExampleResidentSignIn {

	/**
	 * Constructor.
	 *
	 * @param IConfig|null             $config    Holds the switch.
	 * @param ExampleSetResidents|null $residents The installed example residents.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?IConfig $config,
		private readonly ?ExampleSetResidents $residents,
	) {
	}//end __construct()

	/**
	 * The set id of the example residents the one-click demo sign-in offers
	 * on this portal: the switch is `yes` and at least one resident is
	 * installed for it. '' otherwise.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function offered(array $portal): string {
		return (string)($this->residentsOf(portal: $portal)[0]['set'] ?? '');
	}//end offered()

	/**
	 * The one-click cards the site may draw on this portal: per installed
	 * resident its key, its name and the card its set wrote. Empty while the
	 * switch is off. Never an account id: the route finds that itself.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 *
	 * @return list<array{set: string, key: string, name: string, card: array<string, string>}>
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function offeredResidents(array $portal): array {
		$cards = [];
		foreach ($this->residentsOf(portal: $portal) as $resident) {
			$cards[] = [
				'set'  => $resident['set'],
				'key'  => $resident['key'],
				'name' => $resident['name'],
				'card' => $resident['card'],
			];
		}

		return $cards;
	}//end offeredResidents()

	/**
	 * Whether the one-click demo sign-in is open at all: the switch is `yes`
	 * and the install records can be read.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
	 */
	public function open(): bool {
		return $this->switchedOn() === true && $this->residents !== null && $this->residents->available() === true;
	}//end open()

	/**
	 * The Nextcloud account id the one-click demo sign-in may mint for: the
	 * sign-in is open, and set `$id` installed resident `$resident` of this
	 * portal. An empty `$resident` means the first resident the set declares
	 * for the portal, so a link made before there were several still works.
	 * '' otherwise. The caller checks that the portal offers the `nextcloud` mode.
	 *
	 * @param string               $id       The example set id the site asks for.
	 * @param array<string, mixed> $portal   The portal row.
	 * @param string               $resident The resident's key, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function userFor(string $id, array $portal, string $resident = ''): string {
		foreach ($this->residentsOf(portal: $portal) as $installed) {
			if ($installed['set'] !== $id) {
				continue;
			}

			if ($resident === '' || $installed['key'] === $resident) {
				return $installed['uid'];
			}
		}

		return '';
	}//end userFor()

	/**
	 * Whether the administrator switched the one-click demo sign-in on.
	 *
	 * @return bool
	 */
	private function switchedOn(): bool {
		return $this->config !== null
			&& $this->config->getAppValue('portaliq', 'example_resident_demo_login', 'no') === 'yes';
	}//end switchedOn()

	/**
	 * The installed example residents of this portal while the switch is on.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 *
	 * @return list<array{set: string, key: string, uid: string, name: string, card: array<string, string>}>
	 */
	private function residentsOf(array $portal): array {
		$slug = (string)($portal['slug'] ?? '');
		if ($this->switchedOn() === false || $this->residents === null || $slug === '') {
			return [];
		}

		return $this->residents->residentsOf(portal: $slug);
	}//end residentsOf()
}//end class
