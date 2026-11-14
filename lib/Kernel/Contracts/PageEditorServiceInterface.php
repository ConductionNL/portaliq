<?php

/**
 * Contract of PageEditorService
 *
 * The public surface of PageEditorService that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of PageEditorService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PageEditorServiceInterface {

	/**
	 * The app config key holding the editor groups, as a JSON array of gids.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'editor_groups';

	/**
	 * Every group on the instance, for the admin settings picker.
	 *
	 * @return array<array{id: string, label: string}> The groups, id-sorted.
	 *
	 * @spec openspec/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function availableGroups(): array;

	/**
	 * The configured editor groups.
	 *
	 * @return array<string> The group ids, possibly empty.
	 *
	 * @spec openspec/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function getEditorGroups(): array;

	/**
	 * Whether a user may edit portal pages.
	 *
	 * Fail-closed in both directions that matter: no session is never an
	 * editor, and a configured-groups list that is empty leaves only the
	 * administrators — it does not fall back to "everyone signed in", which is
	 * what an empty authorization list would mean if this predicate were
	 * written as "not restricted".
	 *
	 * @param IUser|null $user The user to test, or null for the current session.
	 *
	 * @return bool True when the user may edit.
	 *
	 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function mayEdit(?IUser $user = null): bool;
}//end interface
