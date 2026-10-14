<?php

/**
 * The portaliq register is closed to everybody outside its staff.
 *
 * Found live on 2026-10-14: a Nextcloud account in no group, and a
 * self-registered account in the resident group, read every access request
 * (other residents' names and reasons) and every submission through
 * OpenRegister's objects API, because 44 read rules named `authenticated`.
 * A schema's own block replaces the register's for that schema, so the
 * register block alone closes nothing: both are swept here.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
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
 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use OCA\Portaliq\Service\AdminMenuAccess;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
 */
class RegisterAccessDeclaredTest extends TestCase {

	/**
	 * The schemas the public site may read anonymously, and nothing else.
	 */
	private const PUBLIC_CONTENT = ['glossaryTerm', 'menu', 'page'];

	/**
	 * The files that read OpenRegister with its checks on, and why that is safe.
	 *
	 * Every other call in lib/ is a portal or system path and passes
	 * `_rbac: false`, so it keeps working while the register is closed.
	 */
	private const CHECKED_READS = [
		// Staff authoring: the audience options a news author picks from.
		'Service/NewsAudienceOptions.php',
		// Counts for the install repair step (CLI, a trusted system caller) and the admin metrics.
		'Service/PortalObjectWriter.php',
		// The admin's demo-data screen.
		'Service/DemoDataService.php',
	];

	/**
	 * The shipped register.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 3).'/lib/Settings/portaliq_register.json'), true);
	}//end register()

	/**
	 * The register grants every verb to the staff group only, at a version OpenRegister will write.
	 *
	 * @return void
	 */
	public function testTheRegisterIsClosedToEverybodyButItsStaff(): void {
		$register = $this->register()['components']['registers']['portaliq'];

		foreach (['read', 'create', 'update', 'delete'] as $verb) {
			$this->assertSame([AdminMenuAccess::STAFF_GROUP], $register['authorization'][$verb], $verb);
		}

		$this->assertTrue(
			version_compare((string)$register['version'], '0.96.0', '>'),
			'OpenRegister writes a register\'s own fields only when its version is newer'
		);
	}//end testTheRegisterIsClosedToEverybodyButItsStaff()

	/**
	 * No schema opens any verb to every signed-in account.
	 *
	 * @return void
	 */
	public function testNoSchemaGrantsEverySignedInAccount(): void {
		$open = [];
		foreach ($this->register()['components']['schemas'] as $slug => $schema) {
			foreach ((array)($schema['authorization'] ?? []) as $verb => $rules) {
				foreach ((array)$rules as $rule) {
					$group = is_array($rule) ? ($rule['group'] ?? null) : $rule;
					if ($group === 'authenticated') {
						$open[] = $slug.'.'.$verb;
					}
				}
			}
		}

		$this->assertSame([], $open, 'these rules hand every signed-in Nextcloud account the records');
	}//end testNoSchemaGrantsEverySignedInAccount()

	/**
	 * Only the public site content is anonymously readable; resident and staff data is not.
	 *
	 * @return void
	 */
	public function testOnlyThePublicSiteContentIsPublic(): void {
		$public = [];
		foreach ($this->register()['components']['schemas'] as $slug => $schema) {
			foreach ((array)($schema['authorization']['read'] ?? []) as $rule) {
				$group = is_array($rule) ? ($rule['group'] ?? null) : $rule;
				if ($group === 'public') {
					$public[] = $slug;
				}
			}
		}

		sort($public);
		$this->assertSame(self::PUBLIC_CONTENT, $public);
	}//end testOnlyThePublicSiteContentIsPublic()

	/**
	 * Every OpenRegister call on a portal path passes `_rbac: false`, so the portal keeps working.
	 *
	 * A resident reaches their own data through portaliq's portal API with a
	 * portal session, never with a staff Nextcloud account, so a portal read
	 * that kept OpenRegister's checks on would return nothing once the
	 * register is closed. The scan names every call that keeps the checks on
	 * and holds it to the reviewed list above.
	 *
	 * @return void
	 */
	public function testEveryPortalPathReadsWithTheChecksOff(): void {
		$lib     = dirname(__DIR__, 3).'/lib/';
		$pattern = '/\$\w*[oO]bjectService->(findAll|find|saveObject|deleteObject|searchObjects|searchObjectsPaginated|count)\s*\(/';
		$checked = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
				continue;
			}

			foreach ($matches[0] as [$call, $offset]) {
				$end = strpos($source, ';', $offset);
				if (stripos(substr($source, $offset, ($end - $offset)), 'rbac') === false) {
					$checked[] = substr($file->getPathname(), strlen($lib));
				}
			}
		}

		$checked = array_values(array_unique($checked));
		sort($checked);
		$expected = self::CHECKED_READS;
		sort($expected);

		$this->assertSame($expected, $checked, 'a call that keeps OpenRegister\'s checks on: a portal path here is refused by the closed register');
	}//end testEveryPortalPathReadsWithTheChecksOff()
}//end class
