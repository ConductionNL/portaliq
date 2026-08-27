<?php

/**
 * Portaliq OIDC State Store Service
 *
 * Short-lived, single-use, TTL-bounded storage for one OIDC authorization-code
 * + PKCE round trip: `SessionController::oidcStart()` writes a `state` →
 * `{nonce, codeVerifier, org, provider, returnTo}` row; `oidcCallback()`
 * consumes it EXACTLY ONCE. Backed by the `portalOidcState` OpenRegister
 * schema (same durability model as `portalSession`) rather than an in-process
 * cache, so the round trip survives across app-server instances.
 *
 * Fail-closed on every path (design.md): an unknown, expired, or
 * already-consumed `state` returns null from `consume()` — CSRF/replay guard —
 * and a write failure at `create()` also fails the whole start closed (no
 * redirect is issued without a durable state row to match it against).
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
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * Single-use, TTL-bounded OIDC `state` storage (CSRF + replay guard).
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T02
 */
class OidcStateStoreService {
	/**
	 * The OpenRegister register the `portalOidcState` schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The OpenRegister schema recording pending OIDC round trips.
	 */
	private const SCHEMA = 'portalOidcState';

	/**
	 * How long a `state` row remains valid — comfortably covers a human
	 * completing a broker login page, short enough to bound replay exposure.
	 */
	private const TTL_SECONDS = 600;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $writer Persists/updates the state row.
	 * @param PortalObjectReader $reader Looks the row up by `state`.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * Store one pending OIDC round trip, keyed by `$state`.
	 *
	 * @param string $state The OIDC `state` parameter (single-use key).
	 * @param string $nonce The OIDC `nonce` this request carries.
	 * @param string $codeVerifier The PKCE code verifier.
	 * @param string $org The `?org=` slug the login was started for.
	 * @param string $provider The provider preset.
	 * @param string $returnTo The SPA path to redirect to once minted.
	 *
	 * @return bool True when the row was written; false on any write failure
	 *              (the caller — `oidcStart()` — fails the whole request
	 *              closed rather than issue a redirect with no matching state).
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T02
	 */
	public function create(
		string $state,
		string $nonce,
		string $codeVerifier,
		string $org,
		string $provider,
		string $returnTo,
	): bool {
		if ($state === '') {
			return false;
		}

		$now = new DateTimeImmutable();
		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'state' => $state,
				'nonce' => $nonce,
				'codeVerifier' => $codeVerifier,
				'org' => $org,
				'provider' => $provider,
				'returnTo' => $returnTo,
				'expiresAt' => $now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S'))->format(DATE_ATOM),
				'used' => false,
			]
		);

		return $created !== null;
	}//end create()

	/**
	 * Consume a `state` EXACTLY ONCE. Fails closed to null on: unknown state,
	 * an already-used row (replay), an expired row, or an OpenRegister read/
	 * write failure — all indistinguishable to the caller (no oracle).
	 *
	 * Marks the row `used` BEFORE returning it, so a concurrent or replayed
	 * consumption of the SAME state can never both succeed.
	 *
	 * @param string $state The OIDC `state` parameter to consume.
	 *
	 * @return array{nonce: string, codeVerifier: string, org: string, provider: string, returnTo: string}|null
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T02
	 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
	 */
	public function consume(string $state): ?array {
		if ($state === '') {
			return null;
		}

		$row = $this->findByState(state: $state);
		if ($row === null) {
			return null;
		}

		if (($row['used'] ?? false) === true) {
			// Replay — a state can only ever be consumed once.
			return null;
		}

		if ($this->isExpired(row: $row) === true) {
			return null;
		}

		$uuid = $this->rowId(row: $row);
		if ($uuid === null) {
			return null;
		}

		// Mark used BEFORE returning — a concurrent replay of the same state
		// that races this read will find `used: true` and fail closed too.
		$marked = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: ['used' => true]
		);
		if ($marked === null) {
			return null;
		}

		return [
			'nonce' => (string)($row['nonce'] ?? ''),
			'codeVerifier' => (string)($row['codeVerifier'] ?? ''),
			'org' => (string)($row['org'] ?? ''),
			'provider' => (string)($row['provider'] ?? ''),
			'returnTo' => (string)($row['returnTo'] ?? ''),
		];
	}//end consume()

	/**
	 * Whether a row's `expiresAt` has passed. A missing/unparseable
	 * `expiresAt` is treated as expired — fail closed, never "no expiry".
	 *
	 * @param array<string, mixed> $row The state row.
	 *
	 * @return bool
	 */
	private function isExpired(array $row): bool {
		$expiresAt = (string)($row['expiresAt'] ?? '');
		if ($expiresAt === '') {
			return true;
		}

		try {
			$expiry = new DateTimeImmutable($expiresAt);
		} catch (Throwable) {
			return true;
		}

		return $expiry->getTimestamp() < time();
	}//end isExpired()

	/**
	 * Find the `portalOidcState` row for a `state`, or null when absent/unreachable.
	 *
	 * @param string $state The OIDC `state` parameter.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findByState(string $state): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'state',
			subjectRef: $state,
			limit: 2
		);

		return ($rows[0] ?? null);
	}//end findByState()

	/**
	 * Extract a row's identifier (`id`/`uuid`, flat or in `@self`), or null.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		$self = ($row['@self'] ?? null);
		$selfUuid = null;
		$selfId = null;
		if (is_array($self) === true) {
			$selfUuid = ($self['uuid'] ?? null);
			$selfId = ($self['id'] ?? null);
		}

		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), $selfUuid, $selfId];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end rowId()
}//end class
