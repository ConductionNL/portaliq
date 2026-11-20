<?php

/**
 * Contract of OidcClientService
 *
 * The public surface of OidcClientService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of OidcClientService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OidcClientServiceInterface {

	/**
	 * Build the authorization-request URL (RFC 6749 + PKCE).
	 *
	 * @param string $authorizeEndpoint The broker's authorization endpoint.
	 * @param string $clientId This RP's client id.
	 * @param string $redirectUri The callback URL.
	 * @param array<int, string> $scopes Requested scopes.
	 * @param string $state The CSRF state token.
	 * @param string $nonce The replay nonce.
	 * @param string $codeChallenge The PKCE S256 code challenge.
	 * @param string $prompt The OIDC `prompt` value (`none` for a silent sign-in), or '' to send none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T03
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T07
	 */
	public function buildAuthorizationUrl(
		string $authorizeEndpoint,
		string $clientId,
		string $redirectUri,
		array $scopes,
		string $state,
		string $nonce,
		string $codeChallenge,
		string $prompt = '',
	): string;

	/**
	 * Resolve the broker's OIDC discovery document (`.well-known/openid-
	 * configuration`), cached. Fails closed to null on any HTTP/JSON error,
	 * or when the required endpoints are absent from the document.
	 *
	 * A failed request is reported to integriq's connection registry,
	 * throttled (adopt-connection-registry). A cache hit makes no call, so
	 * it reports nothing.
	 *
	 * @param string $issuer The configured issuer base URL.
	 *
	 * The broker's `end_session_endpoint` is kept too, '' when it announces
	 * none (signin-session-idle-warning-and-sso D6). A document cached before
	 * that may lack the key, so a reader uses `?? ''`.
	 *
	 * @return array{authorization_endpoint: string, token_endpoint: string, jwks_uri: string, end_session_endpoint: string}|null
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T03
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
	 */
	public function discover(string $issuer): ?array;

	/**
	 * Exchange an authorization code for tokens at the broker's token endpoint.
	 * Fails closed to null on any transport/HTTP/JSON error or a non-2xx
	 * response — never surfaces the broker's own error detail to the caller.
	 *
	 * What the broker answered is reported to integriq's connection registry,
	 * throttled, where only admins read it (adopt-connection-registry). An
	 * answer about one login, such as `invalid_grant`, is not reported.
	 *
	 * @param string $tokenEndpoint The broker's token endpoint.
	 * @param string $code The authorization code from the callback.
	 * @param string $codeVerifier The PKCE code verifier matching the original challenge.
	 * @param string $clientId This RP's client id.
	 * @param string $clientSecret This RP's client secret (never logged/returned).
	 * @param string $redirectUri The SAME redirect_uri used at `start`.
	 *
	 * @return array<string, mixed>|null The decoded token response, or null.
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T03
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
	 */
	public function exchangeCode(
		string $tokenEndpoint,
		string $code,
		string $codeVerifier,
		string $clientId,
		string $clientSecret,
		string $redirectUri,
	): ?array;

	/**
	 * Generate a PKCE (S256) code verifier + its code challenge (RFC 7636).
	 *
	 * @return array{verifier: string, challenge: string}
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T03
	 */
	public function generatePkce(): array;

	/**
	 * Generate a fresh, cryptographically secure `state`/`nonce` token.
	 *
	 * @return string URL-safe, ~43 characters.
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T03
	 */
	public function generateToken(): string;

	/**
	 * Full network-integrated ID-token verification: fetches (cached) JWKS
	 * and validates the token against it, retrying ONCE with a forced cache
	 * refresh on failure (key-rotation tolerance) before giving up.
	 *
	 * @param string $idToken The ID token from the token response.
	 * @param string $jwksUri The broker's JWKS endpoint.
	 * @param string $issuer The configured issuer (MUST equal `iss`).
	 * @param string $clientId This RP's client id (MUST be in `aud`).
	 * @param string $expectedNonce The nonce stored at `start`.
	 *
	 * @return array<string, mixed>|null The validated claim set, or null on
	 *                                   ANY failure (fail-closed, no oracle).
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T04
	 * @spec openspec/specs/supplier-portal/spec.md#oidc-callback-validates-the-id-token-and-fails-closed-on-every-error
	 */
	public function verifyIdToken(string $idToken, string $jwksUri, string $issuer, string $clientId, string $expectedNonce): ?array;
}//end interface
