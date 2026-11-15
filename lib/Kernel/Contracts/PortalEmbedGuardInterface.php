<?php

/**
 * Contract of PortalEmbedGuard
 *
 * The public surface of PortalEmbedGuard that other components call, so they depend
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
 * Contract of PortalEmbedGuard.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalEmbedGuardInterface {

	/**
	 * The height the frame starts at when no message has arrived yet.
	 */
	public const MINIMUM_HEIGHT = 480;

	/**
	 * The origins a binding allows, normalised to scheme and host.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function allowedOrigins(array $binding): array;

	/**
	 * Whether a framing origin is on the list.
	 *
	 * The comparison is on scheme and host together, so `http://` is not the
	 * same origin as `https://`, and a host that merely ends with an allowed
	 * one does not pass.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 * @param string $origin The framing origin, as the request carries it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function allows(array $binding, string $origin): bool;
}//end interface
