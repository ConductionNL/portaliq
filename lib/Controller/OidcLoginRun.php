<?php

/**
 * Portaliq OIDC Login Run
 *
 * The state of one OIDC broker callback while `SessionController` walks it:
 * the consumed state row and the authorization code, then the resolved
 * provider configuration, the discovered endpoints and the verified claims.
 * It never leaves the controller that builds it.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One OIDC callback in progress.
 *
 * @spec openspec/specs/supplier-portal/spec.md#oidc-callback-validates-the-id-token-and-fails-closed-on-every-error
 */
final class OidcLoginRun {

	/**
	 * The organisation's resolved OIDC provider configuration.
	 *
	 * @var array<string, mixed>
	 */
	public array $config = [];

	/**
	 * The provider's discovered endpoints.
	 *
	 * @var array<string, string>
	 */
	public array $endpoints = [];

	/**
	 * The verified ID-token claims.
	 *
	 * @var array<string, mixed>
	 */
	public array $claims = [];

	/**
	 * The claims mapped onto the portal identity.
	 *
	 * @var array<string, mixed>
	 */
	public array $mapped = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $pending The consumed OIDC state row.
	 * @param string               $code    The authorization code.
	 */
	public function __construct(
		public readonly array $pending,
		public readonly string $code,
	) {
	}//end __construct()
}//end class
