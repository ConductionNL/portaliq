<?php

/**
 * Portaliq Mail Site
 *
 * The portal a tenant's mail speaks for: its record, the name the mail is
 * sent in, and the language it is written in.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Mail
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Mail;

use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use Throwable;

/**
 * Resolves the portal, name and language a tenant's mail is sent with.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */
class MailSite {
	/**
	 * The language when the portal names none.
	 */
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * The name when neither the portal nor the organisation has one.
	 */
	public const DEFAULT_NAME = 'Portaliq';

	/**
	 * Constructor.
	 *
	 * @param PortalResolver                  $portals       Finds the tenant's portal.
	 * @param PortalOrganisationConfigService $organisations Names the tenant.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly PortalOrganisationConfigService $organisations,
	) {
	}//end __construct()

	/**
	 * The tenant's portal, or null when it has none or the lookup fails.
	 *
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
	 */
	public function portal(string $organisation): ?array {
		try {
			return $this->portals->resolveByOrganisation(organisation: $organisation);
		} catch (Throwable) {
			return null;
		}
	}//end portal()

	/**
	 * The name the mail is sent in: the portal's title, else the tenant's.
	 *
	 * @param array<string, mixed>|null $portal       The portal, or null.
	 * @param string                    $organisation The tenant.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
	 */
	public function name(?array $portal, string $organisation): string {
		$title = trim((string)($portal['title'] ?? ''));
		if ($title !== '') {
			return $title;
		}

		try {
			$name = trim((string)($this->organisations->resolve(orgSlug: $organisation)['organisationName'] ?? ''));
		} catch (Throwable) {
			$name = '';
		}

		if ($name === '') {
			return self::DEFAULT_NAME;
		}

		return $name;
	}//end name()

	/**
	 * The portal's first language, else Dutch.
	 *
	 * @param array<string, mixed>|null $portal The portal, or null.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
	 */
	public function language(?array $portal): string {
		$locales = ($portal['locales'] ?? []);
		if (is_array($locales) === true && is_string($locales[0] ?? null) === true && trim($locales[0]) !== '') {
			return trim($locales[0]);
		}

		return self::DEFAULT_LANGUAGE;
	}//end language()
}//end class
