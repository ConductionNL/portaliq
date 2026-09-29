<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Signin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Signin;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\IAppConfig;

/**
 * An organisation's login route per provider and its integriq broker
 * settings, by organisation slug (signin-integriq-broker-login, design D1
 * and D8). Server-side: `resolveBrokerConfig()` carries the consumer secret,
 * which lives in its own sensitive app config entry and never in the
 * presentation override.
 *
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
class OrganisationLoginConfig {


	/**
	 * Constructor.
	 *
	 * @param PortalOrganisationConfigService $orgConfig The organisation and its override.
	 * @param IAppConfig                      $appConfig The broker secret.
	 * @param LoginProviders                  $providers The broker settings per provider.
	 * @param BrokerLoginRoute                $route     Reads the route.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalOrganisationConfigService $orgConfig,
		private readonly IAppConfig $appConfig,
		private readonly LoginProviders $providers = new LoginProviders(),
		private readonly BrokerLoginRoute $route = new BrokerLoginRoute(),
	) {
	}//end __construct()


	/**
	 * The route an organisation chose for a provider: `oidc` unless its
	 * `loginRoutes` map says `broker`.
	 *
	 * @param string $orgSlug  The organisation slug.
	 * @param string $provider The provider.
	 *
	 * @return string `oidc` or `broker`.
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function loginRouteFor(string $orgSlug, string $provider): string {
		$presentation = $this->presentation(orgSlug: $orgSlug);
		if ($presentation === null) {
			return BrokerLoginRoute::ROUTE_OIDC;
		}

		return $this->route->routeFor(overrides: $presentation['overrides'], provider: $provider);
	}//end loginRouteFor()


	/**
	 * The broker settings with the secret, the organisation's uuid, and the
	 * preset's label and audience, or null when the provider is not routed to
	 * a complete broker.
	 *
	 * @param string $orgSlug  The organisation slug.
	 * @param string $provider The provider.
	 *
	 * @return array<string, string>|null `startUrl`, `exchangeUrl`, `consumerId`, `secret`, `label`, `audience`, `organisationUuid`.
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function resolveBrokerConfig(string $orgSlug, string $provider): ?array {
		$presentation = $this->presentation(orgSlug: $orgSlug);
		if ($presentation === null) {
			return null;
		}

		$settings = $this->providers->brokerSettings(
			overrides: $presentation['overrides'],
			brokerSecret: $this->brokerSecret(organisationUuid: $presentation['uuid']),
			provider: $provider
		);
		if ($settings === null) {
			return null;
		}

		return $settings + ['organisationUuid' => $presentation['uuid']];
	}//end resolveBrokerConfig()


	/**
	 * The organisation's uuid, its override and whether a broker secret is
	 * stored, for the settings screen, or null.
	 *
	 * @param string $orgSlug The organisation slug.
	 *
	 * @return array{uuid: string, overrides: array<string, mixed>, hasBrokerSecret: bool}|null
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function presentationFor(string $orgSlug): ?array {
		$presentation = $this->presentation(orgSlug: $orgSlug);
		if ($presentation === null) {
			return null;
		}

		return $presentation + ['hasBrokerSecret' => ($this->brokerSecret(organisationUuid: $presentation['uuid']) !== '')];
	}//end presentationFor()


	/**
	 * Replace an organisation's presentation override.
	 *
	 * @param string               $organisationUuid The organisation's uuid.
	 * @param array<string, mixed> $overrides        The whole override.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function writePresentation(string $organisationUuid, array $overrides): bool {
		if ($organisationUuid === '') {
			return false;
		}

		return $this->orgConfig->writePresentation(organisationUuid: $organisationUuid, overrides: $overrides);
	}//end writePresentation()


	/**
	 * Store the broker secret in its own sensitive entry (design D8).
	 *
	 * @param string $organisationUuid The organisation's uuid.
	 * @param string $secret           The consumer secret.
	 *
	 * @return bool Whether it was written.
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function setBrokerSecret(string $organisationUuid, string $secret): bool {
		if ($organisationUuid === '') {
			return false;
		}

		return $this->appConfig->setValueString(Application::APP_ID, LoginProviders::SECRET_KEY_PREFIX . $organisationUuid, $secret, false, true);
	}//end setBrokerSecret()


	/**
	 * The organisation's uuid and override, or null for an empty or unknown slug.
	 *
	 * @param string $orgSlug The slug.
	 *
	 * @return array{uuid: string, overrides: array<string, mixed>}|null
	 */
	private function presentation(string $orgSlug): ?array {
		if (trim($orgSlug) === '') {
			return null;
		}

		return $this->orgConfig->presentationFor(orgSlug: $orgSlug);
	}//end presentation()


	/**
	 * The organisation's broker secret, or ''.
	 *
	 * @param string $organisationUuid The organisation's uuid.
	 *
	 * @return string
	 */
	private function brokerSecret(string $organisationUuid): string {
		return $this->appConfig->getValueString(Application::APP_ID, LoginProviders::SECRET_KEY_PREFIX . $organisationUuid, '');
	}//end brokerSecret()
}//end class
