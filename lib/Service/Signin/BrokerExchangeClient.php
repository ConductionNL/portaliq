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

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Redeems integriq's one-time code for the subject envelope, server to server
 * (REQ-BEL-003, design D4).
 *
 * Over HTTP because that is integriq's contract: the redemption half of an
 * authentication handoff, authenticated with a per-consumer secret, so the
 * broker can run on another instance. Not a command on shared data, which is
 * what ADR-041's events are for.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-callback-redeems-the-code-once-over-the-authenticated-exchange-req-bel-003
 */
class BrokerExchangeClient {

	/**
	 * Seconds before the exchange is given up, as for the OIDC token exchange.
	 */
	private const HTTP_TIMEOUT = 10;


	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService Nextcloud's HTTP client.
	 * @param LoggerInterface $logger        Records a failed exchange, never the code or the secret.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * The envelope integriq returns for the code, or null for anything but a
	 * 200 carrying an `envelope` string.
	 *
	 * @param string $exchangeUrl The configured exchange address.
	 * @param string $consumerId  Portaliq's consumer id.
	 * @param string $secret      Portaliq's consumer secret.
	 * @param string $code        The one-time code from the browser.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-callback-redeems-the-code-once-over-the-authenticated-exchange-req-bel-003
	 */
	public function redeem(string $exchangeUrl, string $consumerId, string $secret, string $code): ?string {
		try {
			$response = $this->clientService->newClient()->post(
				$exchangeUrl,
				[
					'timeout' => self::HTTP_TIMEOUT,
					'http_errors' => false,
					'headers' => ['Authorization' => 'Bearer ' . $secret, 'Accept' => 'application/json'],
					'json' => ['code' => $code, 'consumer' => $consumerId],
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: the integriq envelope exchange could not be reached', ['reason' => $e->getMessage()]);
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			$this->logger->info('Portaliq: integriq refused the envelope exchange', ['status' => $response->getStatusCode()]);
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		$envelope = null;
		if (is_array($decoded) === true) {
			$envelope = ($decoded['envelope'] ?? null);
		}

		if (is_string($envelope) === false || $envelope === '') {
			return null;
		}

		return $envelope;
	}//end redeem()
}//end class
