<?php

/**
 * Portal Notice Language
 *
 * The language a notice to a resident is written in: the first locale of
 * their organisation's portal, else Dutch. One language per notice, never
 * Dutch and English glued into one string (woo-inbox-notices).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
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
 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-a-change-notice-is-written-in-the-portals-language-only-req-nap-010
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Service\PortalResolver;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Throwable;

/**
 * Picks the language of a notice to a resident.
 *
 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-a-change-notice-is-written-in-the-portals-language-only-req-nap-010
 */
class PortalNoticeLanguage {

	/**
	 * The language of a notice when the portal names none.
	 *
	 * @var string
	 */
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param IFactory       $l10nFactory The translations, independent of any session locale.
	 * @param PortalResolver $portals     Finds the organisation's portal.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly PortalResolver $portals,
	) {
	}//end __construct()

	/**
	 * Portaliq's translations in the language of the organisation's portal.
	 *
	 * @param string $organisation The resident's organisation.
	 *
	 * @return IL10N
	 *
	 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-a-change-notice-is-written-in-the-portals-language-only-req-nap-010
	 */
	public function forOrganisation(string $organisation): IL10N {
		return $this->l10nFactory->get('portaliq', $this->language(organisation: $organisation));
	}//end forOrganisation()

	/**
	 * The language code of the organisation's portal: its first locale, else
	 * Dutch. A notice whose text the contributing app declares is picked in
	 * this language (claim-addressed-change-notices).
	 *
	 * @param string $organisation The resident's organisation.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function languageFor(string $organisation): string {
		return $this->language(organisation: $organisation);
	}//end languageFor()

	/**
	 * The first locale of the organisation's portal, else Dutch.
	 *
	 * @param string $organisation The resident's organisation.
	 *
	 * @return string
	 */
	private function language(string $organisation): string {
		try {
			$locales = ($this->portals->resolveByOrganisation(organisation: $organisation)['locales'] ?? null);
		} catch (Throwable) {
			// No portal to ask is no reason to lose the notice.
			$locales = null;
		}

		if (is_array($locales) === true && is_string($locales[0] ?? null) === true && trim($locales[0]) !== '') {
			return trim($locales[0]);
		}

		return self::DEFAULT_LANGUAGE;
	}//end language()
}//end class
