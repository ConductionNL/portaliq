<?php

/**
 * Portaliq Publication Report Switch
 *
 * Whether a portal takes error reports on its publications: switched on with
 * a literal true and an owner named. Kept apart from PublicationReportService
 * so the public site record can ask without building the whole service.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Publication
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Publication;

use OCA\Portaliq\Service\PortalReportOwnerCheck;

/**
 * Reads whether a portal's error reports are switched on.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */
final class PublicationReportSwitch {
	/**
	 * Whether the portal takes error reports. The owner's existence is
	 * checked when the portal is saved (PortalReportOwnerCheck).
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
	 */
	public function isOn(array $portal): bool {
		$owner = $portal[PortalReportOwnerCheck::OWNER] ?? '';

		return ($portal[PortalReportOwnerCheck::SWITCH] ?? false) === true && is_string($owner) === true && trim($owner) !== '';
	}//end isOn()
}//end class
