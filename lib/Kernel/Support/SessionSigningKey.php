<?php

/**
 * Portaliq Session Signing Key
 *
 * The session edge's token minter/validator, built from the dedicated
 * `jwt_signing_secret` app value (>= 16 chars). It never falls back to
 * Nextcloud's shared instance secret: when the secret is unset or too short
 * there is no signer and every issue/resolve call fails closed.
 *
 * @category Service
 * @package  OCA\Portaliq\Kernel\Support
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
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Support;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\PortalJwtService;
use OCP\IAppConfig;

/**
 * Holds the signer built from the dedicated signing secret, or none.
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
 */
final class SessionSigningKey {
	/**
	 * The token minter/validator, or null when no dedicated secret is
	 * configured yet (fail-closed window between install and the repair
	 * step generating one).
	 *
	 * @var PortalJwtService|null
	 */
	private readonly ?PortalJwtService $jwt;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the signing secret, flagged sensitive.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
	 */
	public function __construct(IAppConfig $appConfig) {
		// The secret is a sensitive app value (security review S4): read it
		// through IAppConfig, which decrypts it, never through IConfig.
		$secret = $appConfig->getValueString(AppIdentity::APP_ID, 'jwt_signing_secret', '');
		$jwt = null;
		if ($secret !== '') {
			$jwt = self::buildJwtService(secret: $secret);
		}

		$this->jwt = $jwt;
	}//end __construct()

	/**
	 * Build the token minter/validator from a candidate secret, or null when
	 * it is empty or too short — the single point that decides whether the
	 * auth edge is configured (used once, from the constructor, to keep
	 * `$jwt` a single-assignment readonly property).
	 *
	 * @param string $secret The candidate `jwt_signing_secret` value.
	 *
	 * @return PortalJwtService|null
	 */
	private static function buildJwtService(string $secret): ?PortalJwtService {
		if ($secret === '' || strlen($secret) < 16) {
			return null;
		}

		return new PortalJwtService(signingSecret: $secret);
	}//end buildJwtService()

	/**
	 * The signer, or null when no dedicated signing secret is configured.
	 *
	 * @return PortalJwtService|null
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
	 */
	public function jwt(): ?PortalJwtService {
		return $this->jwt;
	}//end jwt()

	/**
	 * Whether a dedicated signing secret is configured. Surfaced by
	 * `AdminSettings` so an operator can see the auth edge is not yet safe to
	 * use, rather than discovering it via failed logins.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.4
	 */
	public function isConfigured(): bool {
		return $this->jwt !== null;
	}//end isConfigured()
}//end class
