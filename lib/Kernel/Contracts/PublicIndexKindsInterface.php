<?php

/**
 * Contract of PublicIndexKinds
 *
 * The public surface of PublicIndexKinds that other components call, so they depend
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
 * Contract of PublicIndexKinds.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicIndexKindsInterface {

	/**
	 * The filter values a signed-in person resolves to, held to text.
	 *
	 * @param mixed $answer What the app returned.
	 *
	 * @return array<string, array<int, string>> Filter label to values.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function visitorValues(mixed $answer): array;

	/**
	 * Keep each well-formed kind, drop the rest.
	 *
	 * @param string $appId   The app that answered.
	 * @param mixed  $answer  What the app returned.
	 *
	 * @return array<int, array<string, mixed>> Kinds: app, type, label, categories, filters, columns.
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-1
	 */
	public function kinds(string $appId, mixed $answer): array;
}//end interface
