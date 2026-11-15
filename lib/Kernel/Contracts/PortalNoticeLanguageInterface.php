<?php

/**
 * Contract of PortalNoticeLanguage
 *
 * The public surface of PortalNoticeLanguage that other components call, so they depend
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

use OCP\IL10N;

/**
 * Contract of PortalNoticeLanguage.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalNoticeLanguageInterface {

	/**
	 * Portaliq's translations in the language of the organisation's portal.
	 *
	 * @param string $organisation The resident's organisation.
	 *
	 * @return IL10N
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-change-notice-is-written-in-the-portals-language-only-req-nap-010
	 */
	public function forOrganisation(string $organisation): IL10N;

	/**
	 * The language code of the organisation's portal: its first locale, else
	 * Dutch. A notice whose text the contributing app declares is picked in
	 * this language (claim-addressed-change-notices).
	 *
	 * @param string $organisation The resident's organisation.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 */
	public function languageFor(string $organisation): string;
}//end interface
