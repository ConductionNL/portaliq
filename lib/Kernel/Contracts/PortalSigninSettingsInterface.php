<?php

/**
 * Contract of PortalSigninSettings
 *
 * The public surface of PortalSigninSettings that other components call, so they depend
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
 * Contract of PortalSigninSettings.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSigninSettingsInterface {

	/**
	 * The portal with this slug, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function portalBySlug(string $slug): ?array;

	/**
	 * Save the routes and the broker settings. Answers the new view, or an
	 * `error`: `no_organisation`, `broker_incomplete` (a provider routed to a
	 * broker that is not fully set), or `save_failed`.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param array<string, mixed> $routes Provider to `oidc` or `broker`.
	 * @param array<string, mixed> $broker The broker fields.
	 * @param string               $secret A new consumer secret, or '' to keep the stored one.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function save(array $portal, array $routes, array $broker, string $secret): array;

	/**
	 * What the settings screen shows, secret-free, or null when the portal
	 * names no organisation.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function view(array $portal): ?array;
}//end interface
