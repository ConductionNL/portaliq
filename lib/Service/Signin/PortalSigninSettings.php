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

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * The sign-in settings an administrator edits on a portal's page: per
 * provider the route (the organisation's own OIDC broker or integriq), and
 * the integriq broker's addresses, consumer id and secret
 * (signin-integriq-broker-login T11).
 *
 * The settings belong to the portal's organisation, so every portal of that
 * organisation shares them. A `broker` route is refused until the broker
 * settings are complete: a route nobody can finish would show residents no
 * button, silently. The secret is write-only.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
class PortalSigninSettings {

	/**
	 * The broker fields an administrator sets.
	 *
	 * @var string[]
	 */
	private const BROKER_FIELDS = ['startUrl', 'exchangeUrl', 'consumerId'];


	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader              $reader    Reads the portal.
	 * @param OrganisationLoginConfig $orgConfig The organisation's override and secret.
	 * @param BrokerLoginRoute                $route     Reads routes and broker settings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly OrganisationLoginConfig $orgConfig,
		private readonly BrokerLoginRoute $route = new BrokerLoginRoute(),
	) {
	}//end __construct()


	/**
	 * The portal with this slug, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function portalBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$rows = $this->reader->readCollection(register: 'portaliq', schema: 'portal', scopeField: 'slug', subjectRef: $slug, organisation: '', limit: 2);
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portalBySlug()


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
	public function view(array $portal): ?array {
		$organisation = trim((string)($portal['organisation'] ?? ''));
		$presentation = $this->orgConfig->presentationFor(orgSlug: $organisation);
		if ($presentation === null) {
			return null;
		}

		$overrides = $presentation['overrides'];
		$providers = [];
		foreach (BrokerLoginRoute::BROKERED_PROVIDERS as $provider) {
			$providers[] = ['provider' => $provider, 'route' => $this->route->routeFor(overrides: $overrides, provider: $provider)];
		}

		$broker = [];
		foreach (self::BROKER_FIELDS as $field) {
			$broker[$field] = (string)(($overrides['broker'][$field] ?? ''));
		}

		return [
			'organisation' => $organisation,
			'providers' => $providers,
			'broker' => $broker,
			'hasSecret' => $presentation['hasBrokerSecret'],
		];
	}//end view()


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
	public function save(array $portal, array $routes, array $broker, string $secret): array {
		$presentation = $this->orgConfig->presentationFor(orgSlug: trim((string)($portal['organisation'] ?? '')));
		if ($presentation === null) {
			return ['error' => 'no_organisation'];
		}

		$overrides = $presentation['overrides'];
		$overrides['loginRoutes'] = $this->routesFrom(routes: $routes);
		$overrides['broker'] = $this->brokerFrom(broker: $broker);

		// The secret counts when one is stored or one is given now; its value
		// is not what is judged here.
		$knownSecret = '';
		if ($presentation['hasBrokerSecret'] === true || trim($secret) !== '') {
			$knownSecret = 'set';
		}

		$brokerComplete = ($this->route->settings(overrides: $overrides, secret: $knownSecret) !== null);
		if (in_array(BrokerLoginRoute::ROUTE_BROKER, $overrides['loginRoutes'], true) === true && $brokerComplete === false) {
			return ['error' => 'broker_incomplete'];
		}

		if ($this->orgConfig->writePresentation(organisationUuid: $presentation['uuid'], overrides: $overrides) === false) {
			return ['error' => 'save_failed'];
		}

		if (trim($secret) !== '' && $this->orgConfig->setBrokerSecret(organisationUuid: $presentation['uuid'], secret: trim($secret)) === false) {
			return ['error' => 'save_failed'];
		}

		return (array)$this->view(portal: $portal);
	}//end save()


	/**
	 * The routes map: only brokered providers, only the two routes, and only
	 * the `broker` entries (a missing entry is `oidc`).
	 *
	 * @param array<string, mixed> $routes The submitted map.
	 *
	 * @return array<string, string>
	 */
	private function routesFrom(array $routes): array {
		$clean = [];
		foreach (BrokerLoginRoute::BROKERED_PROVIDERS as $provider) {
			if (($routes[$provider] ?? null) === BrokerLoginRoute::ROUTE_BROKER) {
				$clean[$provider] = BrokerLoginRoute::ROUTE_BROKER;
			}
		}

		return $clean;
	}//end routesFrom()


	/**
	 * The broker fields as trimmed text; anything else is dropped.
	 *
	 * @param array<string, mixed> $broker The submitted fields.
	 *
	 * @return array<string, string>
	 */
	private function brokerFrom(array $broker): array {
		$clean = [];
		foreach (self::BROKER_FIELDS as $field) {
			if (is_string($broker[$field] ?? null) === true && trim($broker[$field]) !== '') {
				$clean[$field] = trim($broker[$field]);
			}
		}

		return $clean;
	}//end brokerFrom()
}//end class
