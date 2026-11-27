<?php

/**
 * Contract of PortalPartyTreeResolver
 *
 * The public surface of PortalPartyTreeResolver that other components call, so they depend
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
 * Contract of PortalPartyTreeResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPartyTreeResolverInterface {

	/**
	 * The entities a mandate on `$root` reaches, root included.
	 *
	 * @param string $root The entity the mandate names.
	 * @param bool $reachesDown Whether the mandate reaches below it.
	 * @param array<string, mixed> $bounds `maxDepth` and `pageSize` overrides.
	 *
	 * @return array{entities: array<int, string>, refused: bool, bound: array<string, int>}
	 *         `refused` true means the group is past the bound and NOTHING may
	 *         be listed from it; `entities` is then empty.
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function entitiesFor(string $root, bool $reachesDown, array $bounds = []): array;
	/**
	 * A held mandate with the organisations it reaches, when it reaches down:
	 * `reachesTo` lists `{identifier, name, level}` and `reachRefused` is true
	 * when the group is past a bound or could not be read.
	 *
	 * @param array<string, mixed> $mandate The mandate as the held listing shows it.
	 *
	 * @return array<string, mixed> The mandate.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-the-holder-must-see-how-far-a-mandate-reaches-req-smr-008
	 */
	public function withReach(array $mandate): array;
}//end interface
