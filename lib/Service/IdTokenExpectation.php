<?php

/**
 * Portaliq ID Token Expectation
 *
 * What a validated ID token must name: the configured issuer, this relying
 * party's client id, and the nonce stored when the login started.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The issuer, audience and nonce an ID token must carry.
 *
 * @spec openspec/specs/supplier-portal/spec.md#oidc-callback-validates-the-id-token-and-fails-closed-on-every-error
 */
final class IdTokenExpectation {

	/**
	 * The nonce stored at `start`.
	 *
	 * @var string
	 */
	public string $nonce = '';

	/**
	 * Constructor.
	 *
	 * @param string $issuer   The configured issuer (MUST equal `iss`).
	 * @param string $clientId This RP's client id (MUST be in `aud`).
	 */
	public function __construct(
		public readonly string $issuer,
		public readonly string $clientId,
	) {
	}//end __construct()
}//end class
