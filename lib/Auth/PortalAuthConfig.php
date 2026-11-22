<?php

/**
 * Portaliq Portal Auth Config
 *
 * A portal's `authentication` block read once, against the closed set of
 * modes, so every way in asks the same question the same way
 * (portal-scoping-and-auth Task 4).
 *
 * @category Auth
 * @package  OCA\Portaliq\Auth
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
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
 */

declare(strict_types=1);

namespace OCA\Portaliq\Auth;

/**
 * The ways in a portal offers, read against the closed set of modes.
 *
 * A block that cannot be read as declared (not an object, `modes` not a list,
 * a mode outside the set, a `minTrust` outside the eIDAS levels) is malformed,
 * and a malformed block offers no way in at all: no session is issued from
 * it, so nothing can be written through it. An absent block, or one without
 * modes, is a public portal that offers no sign-in either.
 *
 * The `oidc` sub-block names the one provider the portal accepts (its
 * `issuer`, and optionally its `clientId`). The provider's settings and
 * secret stay with the organisation; a portal that names a provider only
 * accepts a login through a provider that matches it, so an assertion from a
 * different provider is refused. A portal that names none accepts the
 * organisation's provider as before. An `oidc` value that is not an object,
 * or an `issuer`/`clientId` that is not a non-empty string, is malformed.
 *
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
 */
final class PortalAuthConfig {

	/**
	 * The closed set of modes, as the portal schema's `authentication.modes` enum.
	 * `oidc` covers Google, Microsoft and Keycloak through one integration.
	 *
	 * @var array<int, string>
	 */
	public const MODES = ['public', 'nextcloud', 'local', 'oidc', 'digid', 'eherkenning', 'eidas', 'yivi', 'email-link'];

	/**
	 * The eIDAS-aligned assurance levels `minTrust` may name.
	 *
	 * @var array<int, string>
	 */
	public const TRUST_LEVELS = ['low', 'substantial', 'high'];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $modes     The declared modes; [] when malformed.
	 * @param string|null        $minTrust  The declared assurance floor, or null.
	 * @param bool               $malformed Whether the block could not be read as declared.
	 * @param array{issuer: string|null, clientId: string|null} $oidc The provider the portal names, nulls for none.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- one recorded fact of the parse, set only by fromPortal().
	 */
	private function __construct(
		private readonly array $modes,
		private readonly ?string $minTrust,
		private readonly bool $malformed,
		private readonly array $oidc = ['issuer' => null, 'clientId' => null],
	) {
	}//end __construct()

	/**
	 * Read a portal's `authentication` block.
	 *
	 * @param array<string, mixed>|null $portal The portal record, or null for none.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public static function fromPortal(?array $portal): self {
		$auth = ($portal['authentication'] ?? null);
		if ($auth === null) {
			return new self(modes: [], minTrust: null, malformed: false);
		}

		if (is_array($auth) === false) {
			return self::malformed();
		}

		$modes = self::declaredModes(value: ($auth['modes'] ?? []));
		$trust = ($auth['minTrust'] ?? null);
		$oidc  = self::declaredOidc(value: ($auth['oidc'] ?? null));
		if ($modes === null || $oidc === null || ($trust !== null && in_array($trust, self::TRUST_LEVELS, true) === false)) {
			return self::malformed();
		}

		return new self(modes: $modes, minTrust: $trust, malformed: false, oidc: $oidc);
	}//end fromPortal()

	/**
	 * Whether the block could not be read as declared.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function isMalformed(): bool {
		return $this->malformed;
	}//end isMalformed()

	/**
	 * The declared modes, [] for a malformed block.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function modes(): array {
		return $this->modes;
	}//end modes()

	/**
	 * Whether the portal offers this way in. A malformed block offers none.
	 *
	 * @param string $mode One of MODES.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function offers(string $mode): bool {
		return in_array($mode, $this->modes, true);
	}//end offers()

	/**
	 * Whether anyone may read the portal's published content without signing
	 * in: a portal without modes, one that offers `public`, and a malformed
	 * block. A malformed block fails closed to public read-only, so it offers
	 * no way in and no write, yet its published content stays readable.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 * @spec openspec/changes/portal-scoping-and-auth/tasks.md#task-4-per-portal-authentication-and-scoped-sessions
	 */
	public function readsPublicly(): bool {
		return $this->malformed === true || $this->modes === [] || $this->offers(mode: 'public') === true;
	}//end readsPublicly()

	/**
	 * The declared assurance floor, or null when none is declared (or the block is malformed).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function minTrust(): ?string {
		return $this->minTrust;
	}//end minTrust()

	/**
	 * Whether a login through this OIDC provider may complete on the portal:
	 * the organisation's resolved provider settings must match the issuer
	 * (and client id) the portal names. A portal that names no provider
	 * accepts the organisation's; a malformed block accepts none.
	 *
	 * @param array<string, mixed> $config The organisation's resolved provider settings.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function acceptsOidcProvider(array $config): bool {
		if ($this->malformed === true) {
			return false;
		}

		$issuer = $this->oidc['issuer'];
		if ($issuer !== null && self::normalIssuer(value: ($config['issuer'] ?? null)) !== $issuer) {
			return false;
		}

		$clientId = $this->oidc['clientId'];
		return $clientId === null || ($config['clientId'] ?? null) === $clientId;
	}//end acceptsOidcProvider()

	/**
	 * Whether the portal names the OIDC provider it accepts.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function namesOidcProvider(): bool {
		return $this->oidc['issuer'] !== null || $this->oidc['clientId'] !== null;
	}//end namesOidcProvider()

	/**
	 * A block that offers nothing.
	 *
	 * @return self
	 */
	private static function malformed(): self {
		return new self(modes: [], minTrust: null, malformed: true);
	}//end malformed()

	/**
	 * The declared modes in order without repeats, or null when the value is
	 * not a list of modes from the closed set.
	 *
	 * @param mixed $value The stored `modes`.
	 *
	 * @return array<int, string>|null
	 */
	private static function declaredModes(mixed $value): ?array {
		if (is_array($value) === false || array_is_list($value) === false) {
			return null;
		}

		foreach ($value as $mode) {
			if (is_string($mode) === false || in_array($mode, self::MODES, true) === false) {
				return null;
			}
		}

		return array_values(array_unique($value));
	}//end declaredModes()

	/**
	 * The provider the `oidc` sub-block names, or null when it cannot be read.
	 *
	 * @param mixed $value The stored `oidc`.
	 *
	 * @return array{issuer: string|null, clientId: string|null}|null
	 */
	private static function declaredOidc(mixed $value): ?array {
		if ($value === null) {
			return ['issuer' => null, 'clientId' => null];
		}

		if (is_array($value) === false || ($value !== [] && array_is_list($value) === true)) {
			return null;
		}

		$named = [];
		foreach (['issuer', 'clientId'] as $field) {
			$given = ($value[$field] ?? null);
			if ($given !== null && (is_string($given) === false || trim($given) === '')) {
				return null;
			}

			$named[$field] = $given;
		}

		if ($named['issuer'] !== null) {
			$named['issuer'] = self::normalIssuer(value: $named['issuer']);
			if ($named['issuer'] === '') {
				return null;
			}
		}

		return ['issuer' => $named['issuer'], 'clientId' => $named['clientId']];
	}//end declaredOidc()

	/**
	 * An issuer compared without surrounding space or a trailing slash, or ''
	 * for a value that is not a string.
	 *
	 * @param mixed $value The issuer.
	 *
	 * @return string
	 */
	private static function normalIssuer(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return rtrim(trim($value), '/');
	}//end normalIssuer()
}//end class
