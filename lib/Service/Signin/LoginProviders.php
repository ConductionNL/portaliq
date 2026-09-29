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

use OCA\Portaliq\Service\OidcClaimMapperService;

/**
 * The login buttons an organisation offers, each with its route
 * (signin-integriq-broker-login REQ-BEL-001), and the integriq broker
 * settings of one provider.
 *
 * A provider routed to integriq is listed only when the broker settings are
 * complete; one on the OIDC route only when its OIDC config resolves. The
 * route is the organisation's choice: a complete OIDC config does not bring
 * back a provider routed to an incomplete broker.
 *
 * Pure: the caller hands in the override, the secret and the OIDC resolver.
 *
 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
class LoginProviders {

	/**
	 * The prefix of the sensitive app config key holding an organisation's
	 * broker secret; the organisation's uuid follows it.
	 */
	public const SECRET_KEY_PREFIX = 'broker_secret_';


	/**
	 * Constructor.
	 *
	 * @param OidcClaimMapperService $claimMapper The provider presets (label, audience).
	 * @param BrokerLoginRoute       $route       Reads the routes and the broker settings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OidcClaimMapperService $claimMapper = new OidcClaimMapperService(),
		private readonly BrokerLoginRoute $route = new BrokerLoginRoute(),
	) {
	}//end __construct()


	/**
	 * The login buttons, in the order of the known providers.
	 *
	 * @param array<string, mixed>                  $overrides    The organisation's presentation override.
	 * @param string                                $brokerSecret Its broker secret, or ''.
	 * @param callable(string): (array<string, mixed>|null) $oidcConfig The OIDC config of a provider, or null.
	 *
	 * @return array<int, array{provider: string, label: string, route: string}>
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function list(array $overrides, string $brokerSecret, callable $oidcConfig): array {
		$providers = [];
		foreach (['digid', 'eherkenning', 'eidas', 'generic'] as $provider) {
			$entry = $this->entry(overrides: $overrides, brokerSecret: $brokerSecret, provider: $provider, oidcConfig: $oidcConfig);
			if ($entry !== null) {
				$providers[] = $entry;
			}
		}

		return $providers;
	}//end list()


	/**
	 * The integriq broker settings of one provider, with its preset's label
	 * and audience, or null when it is not routed to a complete broker.
	 *
	 * @param array<string, mixed> $overrides    The organisation's presentation override.
	 * @param string               $brokerSecret Its broker secret, or ''.
	 * @param string               $provider     The provider.
	 *
	 * @return array{startUrl: string, exchangeUrl: string, consumerId: string, secret: string, label: string, audience: string}|null
	 *
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function brokerSettings(array $overrides, string $brokerSecret, string $provider): ?array {
		if ($this->route->routeFor(overrides: $overrides, provider: $provider) !== BrokerLoginRoute::ROUTE_BROKER) {
			return null;
		}

		$settings = $this->route->settings(overrides: $overrides, secret: $brokerSecret);
		$preset = $this->claimMapper->applyPreset(provider: $provider, rawConfig: []);
		if ($settings === null || $preset === null) {
			return null;
		}

		return $settings + ['label' => (string)($preset['label'] ?? $provider), 'audience' => (string)($preset['audience'] ?? 'client')];
	}//end brokerSettings()


	/**
	 * One provider's button, or null when its chosen route is not configured.
	 *
	 * @param array<string, mixed> $overrides    The override.
	 * @param string               $brokerSecret The broker secret.
	 * @param string               $provider     The provider.
	 * @param callable             $oidcConfig   The OIDC resolver.
	 *
	 * @return array{provider: string, label: string, route: string}|null
	 */
	private function entry(array $overrides, string $brokerSecret, string $provider, callable $oidcConfig): ?array {
		if ($this->route->routeFor(overrides: $overrides, provider: $provider) === BrokerLoginRoute::ROUTE_BROKER) {
			$broker = $this->brokerSettings(overrides: $overrides, brokerSecret: $brokerSecret, provider: $provider);
			if ($broker === null) {
				return null;
			}

			return ['provider' => $provider, 'label' => $broker['label'], 'route' => BrokerLoginRoute::ROUTE_BROKER];
		}

		$merged = $oidcConfig($provider);
		if (is_array($merged) === false) {
			return null;
		}

		return ['provider' => $provider, 'label' => (string)($merged['label'] ?? $provider), 'route' => BrokerLoginRoute::ROUTE_OIDC];
	}//end entry()
}//end class
