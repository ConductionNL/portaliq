<?php

/**
 * Contract of PortalThemeChoice
 *
 * The public surface of PortalThemeChoice that other components call, so they depend
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
 * Contract of PortalThemeChoice.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalThemeChoiceInterface {

	/**
	 * Save a theme for the portal.
	 *
	 * A set the resolver would not render is refused. A set whose tokens fail
	 * AA on a portal surface is refused with its verdict, so the administrator
	 * sees why; saving it anyway is its own method, taken deliberately.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param string               $theme  The set id.
	 *
	 * @return array<string, mixed> `{portal, verdict}` or `{error, verdict?}`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function choose(array $portal, string $theme): array;

	/**
	 * Save a theme the administrator was shown the contrast findings for and
	 * confirmed. A set that does not resolve is still refused.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param string               $theme  The set id.
	 *
	 * @return array<string, mixed> `{portal, verdict}` or `{error}`.
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function chooseConfirmingFindings(array $portal, string $theme): array;

	/**
	 * The portal's theme and every set it can adopt, each with its verdict.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array{current: string, currentResolves: bool, sets: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function listFor(array $portal): array;

	/**
	 * The portal with this slug, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
	 */
	public function portalBySlug(string $slug): ?array;
}//end interface
