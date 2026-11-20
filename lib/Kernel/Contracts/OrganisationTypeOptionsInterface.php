<?php

/**
 * Contract of OrganisationTypeOptions
 *
 * The public surface of OrganisationTypeOptions that other components call, so they depend
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
 * Contract of OrganisationTypeOptions.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganisationTypeOptionsInterface {

	/**
	 * Settle a portal's organisation type on save.
	 *
	 * An empty type removes the type and its label. A type of the scheme stores the
	 * concept's label beside it. Anything else is refused, so no free text is
	 * stored; when the list is not installed only the stored type is kept.
	 * Every save also stores the installed scheme's uuid as
	 * `organisationTypeSchemeId`, which the details form's type picker filters
	 * the concepts on (a concept names its scheme by uuid, and the uuid differs
	 * per instance); without the scheme the field is removed and the picker
	 * offers nothing.
	 *
	 * @param array<string, mixed> $portal The portal as it is saved.
	 * @param array<string, mixed> $old    The portal as it was stored, or [] when new.
	 *
	 * @return array{refusal: string|null, portal: array<string, mixed>}
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function settle(array $portal, array $old): array;
}//end interface
