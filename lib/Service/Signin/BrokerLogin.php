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

use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalSessionService;

/**
 * A DigiD, eHerkenning or eIDAS login through integriq's broker, from the
 * start redirect to the minted portal session (signin-integriq-broker-login).
 *
 * The start binds the login to one organisation and one provider in a
 * single-use state row. The completion consumes that row, redeems the code
 * over the authenticated exchange, checks every claim it acts on, and mints
 * the same portal session the OIDC callback mints. Every failure answers
 * null, so the caller cannot tell one cause from another and neither can the
 * visitor.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md
 */
class BrokerLogin {

	/**
	 * The trust level asked of integriq. Integriq maps the login it actually
	 * got to the envelope's `trust`, and that answer is what the session gets.
	 */
	private const REQUESTED_TRUST = 'substantial';


	/**
	 * Constructor.
	 *
	 * @param OrganisationLoginConfig $orgConfig  The organisation's route and broker settings.
	 * @param OidcStateStoreService           $stateStore The single-use state rows.
	 * @param BrokerExchangeClient            $exchange   Redeems the code.
	 * @param PortalAccountService            $accounts   Finds or creates the portal account.
	 * @param PortalSessionService            $session    Mints the portal bearer.
	 * @param BrokerEnvelopeCheck             $envelopes  Checks the envelope's claims.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrganisationLoginConfig $orgConfig,
		private readonly OidcStateStoreService $stateStore,
		private readonly BrokerExchangeClient $exchange,
		private readonly PortalAccountService $accounts,
		private readonly PortalSessionService $session,
		private readonly BrokerEnvelopeCheck $envelopes = new BrokerEnvelopeCheck(),
	) {
	}//end __construct()


	/**
	 * The address to send the browser to, or null when this organisation does
	 * not route this provider to a complete broker.
	 *
	 * @param string $org         The organisation slug.
	 * @param string $provider    The provider.
	 * @param string $returnTo    The portal path to land on once signed in.
	 * @param string $callbackUrl Portaliq's absolute broker callback address.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 */
	public function start(string $org, string $provider, string $returnTo, string $callbackUrl): ?string {
		// Asked first, before any secret-bearing read: an OIDC-routed
		// provider cannot be started on this route.
		if ($this->orgConfig->loginRouteFor(orgSlug: $org, provider: $provider) !== BrokerLoginRoute::ROUTE_BROKER) {
			return null;
		}

		$broker = $this->orgConfig->resolveBrokerConfig(orgSlug: $org, provider: $provider);
		if ($broker === null) {
			return null;
		}

		$state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		if ($this->stateStore->createBroker(state: $state, org: $org, provider: $provider, returnTo: $returnTo) === false) {
			return null;
		}

		$query = http_build_query(
			[
				'organisation' => $org,
				'provider' => $provider,
				'trust' => self::REQUESTED_TRUST,
				'consumer' => $broker['consumerId'],
				'state' => $state,
				'returnUrl' => $callbackUrl,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$separator = '?';
		if (str_contains($broker['startUrl'], '?') === true) {
			$separator = '&';
		}

		return $broker['startUrl'] . $separator . $query;
	}//end start()


	/**
	 * Complete a login: the minted bearer and where to land, or null.
	 *
	 * @param string $state The relay state integriq handed back.
	 * @param string $code  The one-time code.
	 *
	 * @return array{token: string, returnTo: string}|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-envelope-becomes-an-ordinary-portal-session-req-bel-005
	 */
	public function complete(string $state, string $code): ?array {
		if ($state === '' || $code === '') {
			return null;
		}

		// Consumed first, so a replayed callback finds a used row and ends
		// here (REQ-BEL-003); a row written for the OIDC route ends here too.
		$pending = $this->stateStore->consume(state: $state);
		if ($pending === null || $pending['route'] !== BrokerLoginRoute::ROUTE_BROKER) {
			return null;
		}

		$claims = $this->redeem(pending: $pending, code: $code);
		if ($claims === null) {
			return null;
		}

		$token = $this->mint(pending: $pending, claims: $claims);
		if ($token === null) {
			return null;
		}

		return ['token' => $token, 'returnTo' => $pending['returnTo']];
	}//end complete()


	/**
	 * Redeem the code and check the envelope's claims against the state row.
	 *
	 * @param array<string, string|bool> $pending The consumed state row.
	 * @param string                $code    The one-time code.
	 *
	 * @return array{sub: string, provider: string, trust: string, audience: string}|null
	 */
	private function redeem(array $pending, string $code): ?array {
		$broker = $this->orgConfig->resolveBrokerConfig(orgSlug: $pending['org'], provider: $pending['provider']);
		if ($broker === null) {
			return null;
		}

		$envelope = $this->exchange->redeem(
			exchangeUrl: $broker['exchangeUrl'],
			consumerId: $broker['consumerId'],
			secret: $broker['secret'],
			code: $code
		);
		if ($envelope === null) {
			return null;
		}

		$claims = $this->envelopes->check(
			envelope: $envelope,
			consumerId: $broker['consumerId'],
			org: $pending['org'],
			provider: $pending['provider'],
			now: time()
		);
		if ($claims === null) {
			return null;
		}

		return $claims + ['audience' => $broker['audience']];
	}//end redeem()


	/**
	 * The envelope as an ordinary portal session (design D6): the provider is
	 * the identity type, the subject the identity reference, the preset's
	 * audience the audience, and integriq's trust the session's trust.
	 *
	 * @param array<string, string|bool> $pending The consumed state row.
	 * @param array<string, string> $claims  The checked claims plus the audience.
	 *
	 * @return string|null The bearer.
	 */
	private function mint(array $pending, array $claims): ?string {
		// The broker supplies no e-mail address, so no waiting account is
		// claimed by address: only the identity reference matches.
		$account = $this->accounts->findOrCreate(
			identityType: $claims['provider'],
			identityRef: $claims['sub'],
			organisation: $pending['org'],
			audience: $claims['audience']
		);
		if ($account === null) {
			return null;
		}

		$issued = $this->session->issueSession(
			subjectRef: (string)$account['subjectRef'],
			audience: $claims['audience'],
			organisation: $pending['org'],
			trust: $claims['trust'],
			roles: [$claims['audience'] . ':read']
		);
		if ($issued === null) {
			return null;
		}

		return (string)$issued['token'];
	}//end mint()
}//end class
