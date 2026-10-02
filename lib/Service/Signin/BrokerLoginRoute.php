<?php

/**
 * Portaliq Broker Login Route
 *
 * Which login route an organisation chose per provider, and the integriq
 * broker settings that route needs.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Signin
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
 * @spec openspec/specs/portal-broker-envelope-login/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Signin;

/**
 * Reads the `loginRoutes` map and the `broker` settings from an
 * organisation's presentation override (design D1).
 *
 * A provider missing from the map takes the `oidc` route, which is what every
 * organisation did before this route existed, so nothing changes on upgrade.
 * A `broker` route counts only when every field it needs is set: half a
 * configuration shows no button rather than a button that fails.
 *
 * Pure: no container, no config reads. The caller hands in the override and
 * the secret.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md
 */
class BrokerLoginRoute {

	/**
	 * The route every provider takes unless the organisation says otherwise.
	 */
	public const ROUTE_OIDC = 'oidc';

	/**
	 * The integriq broker route.
	 */
	public const ROUTE_BROKER = 'broker';

	/**
	 * The providers integriq brokers. `generic` is an organisation's own
	 * OIDC provider and has no government login behind it.
	 *
	 * @var string[]
	 */
	public const BROKERED_PROVIDERS = ['digid', 'eherkenning', 'eidas'];


	/**
	 * The route an organisation chose for a provider.
	 *
	 * @param array<string, mixed> $overrides The organisation's presentation override.
	 * @param string               $provider  The provider.
	 *
	 * @return string `oidc` or `broker`.
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md
	 */
	public function routeFor(array $overrides, string $provider): string {
		$routes = ($overrides['loginRoutes'] ?? null);
		if (is_array($routes) === true
			&& ($routes[$provider] ?? null) === self::ROUTE_BROKER
			&& in_array($provider, self::BROKERED_PROVIDERS, true) === true
		) {
			return self::ROUTE_BROKER;
		}

		return self::ROUTE_OIDC;
	}//end routeFor()


	/**
	 * The broker settings, with the secret, or null when any is missing.
	 *
	 * Both addresses must be absolute https urls: the envelope's claims are
	 * trusted on the strength of the back channel alone, so a plain http
	 * exchange would let anyone on the network path forge a sign-in. The
	 * secret is never in the override: the caller reads it from its own
	 * sensitive entry.
	 *
	 * @param array<string, mixed> $overrides The organisation's presentation override.
	 * @param string               $secret    The consumer secret.
	 *
	 * @return array{startUrl: string, exchangeUrl: string, consumerId: string, secret: string}|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md
	 */
	public function settings(array $overrides, string $secret): ?array {
		$broker = ($overrides['broker'] ?? null);
		if (is_array($broker) === false || $secret === '') {
			return null;
		}

		$startUrl = $this->address(value: ($broker['startUrl'] ?? null));
		$exchangeUrl = $this->address(value: ($broker['exchangeUrl'] ?? null));
		$consumerId = ($broker['consumerId'] ?? null);
		if ($startUrl === null || $exchangeUrl === null || is_string($consumerId) === false || trim($consumerId) === '') {
			return null;
		}

		return ['startUrl' => $startUrl, 'exchangeUrl' => $exchangeUrl, 'consumerId' => trim($consumerId), 'secret' => $secret];
	}//end settings()


	/**
	 * An absolute https address, or null.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string|null
	 */
	private function address(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$value = trim($value);
		$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
		if ($scheme !== 'https' || (string)parse_url($value, PHP_URL_HOST) === '') {
			return null;
		}

		return $value;
	}//end address()
}//end class
