<?php

/**
 * Portaliq OIDC Start Run
 *
 * The state of one OIDC broker login start while `SessionController` walks
 * it: the organisation and provider, where the login returns, then the
 * resolved provider configuration, the discovered endpoints and the state,
 * nonce and PKCE pair minted for the authorization request. It never leaves
 * the controller that builds it.
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
 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One OIDC login start in progress.
 *
 * @spec openspec/specs/supplier-portal/spec.md#oidc-start-builds-a-state-nonce-pkce-authorization-request
 */
final class OidcStartRun {

	/**
	 * The serving portal, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $site = null;

	/**
	 * The site page to land on once signed in, or ''.
	 *
	 * @var string
	 */
	public string $siteReturn = '';

	/**
	 * The broker prompt: 'none' for a silent start, else ''.
	 *
	 * @var string
	 */
	public string $prompt = '';

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
	 * The single-use state.
	 *
	 * @var string
	 */
	public string $state = '';

	/**
	 * The ID-token nonce.
	 *
	 * @var string
	 */
	public string $nonce = '';

	/**
	 * The PKCE verifier and challenge.
	 *
	 * @var array{verifier: string, challenge: string}
	 */
	public array $pkce = ['verifier' => '', 'challenge' => ''];

	/**
	 * Constructor.
	 *
	 * @param string $org      The organisation slug to log in to.
	 * @param string $provider The login provider.
	 */
	public function __construct(
		public readonly string $org,
		public readonly string $provider,
	) {
	}//end __construct()
}//end class
