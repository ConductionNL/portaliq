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
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- one recorded fact of the parse, set only by fromPortal().
	 */
	private function __construct(
		private readonly array $modes,
		private readonly ?string $minTrust,
		private readonly bool $malformed,
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
		if ($modes === null || ($trust !== null && in_array($trust, self::TRUST_LEVELS, true) === false)) {
			return self::malformed();
		}

		return new self(modes: $modes, minTrust: $trust, malformed: false);
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
}//end class
