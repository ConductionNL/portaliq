<?php

/**
 * Contract of PortalBindingPreview
 *
 * The public surface of PortalBindingPreview that other components call, so they depend
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
 * Contract of PortalBindingPreview.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalBindingPreviewInterface {

	/**
	 * What this binding resolves to today.
	 *
	 * Calls the SAME resolution the render calls. A preview that reimplements
	 * it drifts, and the drift shows up as an administrator being told a
	 * binding is healthy while the citizen-facing render disagrees, which is
	 * worse than having no preview at all.
	 *
	 * @param array<string, mixed> $binding The binding as configured.
	 *
	 * @return array<string, mixed> The preview.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
	 */
	public function describe(array $binding): array;
}//end interface
