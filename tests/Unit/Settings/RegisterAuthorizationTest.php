<?php

declare(strict_types=1);

/**
 * Register authorization guard.
 *
 * 🔴 THIS TEST EXISTS BECAUSE THE REGISTER LOOKS HALF-FINISHED.
 *
 * Every schema in `portaliq_register.json` grants `read` and lists no write
 * action at all. A reader who does not know OpenRegister's rule sees an
 * incomplete block and completes it — and completing it hands every
 * authenticated user the ability to rewrite portal sessions, portal accounts
 * and OIDC state by PUTing the OpenRegister objects API directly.
 *
 * I am that reader. On 2026-08-15 I measured that declaring a `read`-only
 * block flips a non-admin write from 201 to 403, concluded it was an
 * accidental side effect of the `publicRead` sweep, and opened five PRs
 * granting `create`/`update`/`delete` to `authenticated` across the fleet.
 * hermiq's `AgentAuthorizationTest` — which exists for exactly this reason —
 * failed and stopped it. portaliq had no such guard. Now it does.
 *
 * THE RULE: OpenRegister fails closed on an action a NON-EMPTY authorization
 * block does not list. `MagicRbacHandler` — "an omitted action yields
 * owner-only rows"; `PermissionHandler` — "if authorization is configured but
 * the action is not granted, access is denied". Owners and admins are admitted
 * before that check, so the owner keeps full control of their own rows.
 * **Omission IS the mechanism**, not an oversight.
 *
 * @category Tests
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
 */

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards a declarative register file, not a PHP class.
 */
class RegisterAuthorizationTest extends TestCase {


	/**
	 * The register's schemas.
	 *
	 * @return array<string, mixed> The schemas.
	 */
	private function schemas(): array {
		$path = __DIR__.'/../../../lib/Settings/portaliq_register.json';
		$this->assertFileExists($path, 'The register file must exist.');
		$register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($register, 'The register must be valid JSON.');

		return (array)($register['components']['schemas'] ?? []);

	}//end schemas()


	/**
	 * No schema may grant a WRITE action to a broad group.
	 *
	 * The schemas holding a citizen's session, account and OIDC state are the
	 * whole reason this matters: a `create`/`update`/`delete` grant to
	 * `authenticated` or `public` lets any signed-in visitor rewrite another
	 * visitor's session record through the generic objects API, with no
	 * portaliq code involved and nothing in portaliq's logs.
	 *
	 * @return void
	 */
	public function testNoSchemaGrantsAWriteActionToABroadGroup(): void {
		$broad = ['authenticated', 'public'];
		$offenders = [];

		foreach ($this->schemas() as $name => $body) {
			$authorization = ($body['authorization'] ?? null);
			if (is_array($authorization) === false) {
				continue;
			}

			foreach (['create', 'update', 'delete'] as $verb) {
				foreach ((array)($authorization[$verb] ?? []) as $rule) {
					$group = $rule;
					if (is_array($rule) === true) {
						$group = ($rule['group'] ?? '');
					}

					if (in_array($group, $broad, true) === true) {
						$offenders[] = "{$name}.{$verb} -> {$group}";
					}
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"A write action was granted to a broad group. The omission of write "
			."actions is DELIBERATE — OpenRegister fails closed on an action a "
			."non-empty authorization block does not list, while owners and "
			."admins are admitted before that check. Completing the block "
			."hands every authenticated user write access to these rows "
			."through the generic objects API. If a specific schema genuinely "
			."needs a broad write grant, change THIS TEST in the same commit "
			."and say why.\nOffenders: ".implode(', ', $offenders)
		);

	}//end testNoSchemaGrantsAWriteActionToABroadGroup()


	/**
	 * Every schema still declares a READ rule.
	 *
	 * The pair to the assertion above. A register that granted nothing at all
	 * would satisfy the write check trivially while serving no content — the
	 * failure that looks exactly like a working security control.
	 *
	 * @return void
	 */
	public function testEverySchemaStillDeclaresAReadRule(): void {
		$missing = [];
		foreach ($this->schemas() as $name => $body) {
			$read = (($body['authorization'] ?? [])['read'] ?? null);
			if (is_array($read) === false || $read === []) {
				$missing[] = $name;
			}
		}

		$this->assertSame([], $missing, 'Schemas with no read rule: '.implode(', ', $missing));

	}//end testEverySchemaStillDeclaresAReadRule()


}//end class
