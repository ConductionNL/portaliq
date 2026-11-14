<?php

/**
 * Contract of PortalProviderLocator
 *
 * The public surface of PortalProviderLocator that other components call, so they depend
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
 * Contract of PortalProviderLocator.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalProviderLocatorInterface {

	/**
	 * Ask a provider for its contribution, in the portal's language.
	 *
	 * Every caller asks through here, so every provider call runs inside
	 * ContributionLanguage: the request forces the portal's language while
	 * the provider translates its labels, and is put back afterwards. The
	 * provider interface stays `getContribution(array $subject)`.
	 *
	 * @param object               $provider The provider `locate()` returned.
	 * @param array<string, mixed> $subject  The subject to ask for.
	 *
	 * @return mixed What the provider returned; null when it has no `getContribution`.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-the-portal-api-must-ask-contributing-apps-in-the-portals-language
	 */
	public function contributionOf(object $provider, array $subject): mixed;

	/**
	 * Resolve one app's contribution provider by convention FQCN, or null.
	 *
	 * An app contributes by shipping `OCA\{Namespace}\Portal\PortalContributionProvider`
	 * with `getAudience()` + `getContribution()` — no need to implement (and thus
	 * depend on) Portaliq's interface.
	 *
	 * @param string $appId The app id.
	 *
	 * @return object|null The provider instance, or null when the app ships none.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 */
	public function locate(string $appId): ?object;
}//end interface
