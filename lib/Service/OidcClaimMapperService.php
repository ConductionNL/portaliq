<?php

/**
 * Portaliq OIDC Claim Mapper Service
 *
 * Broker-agnostic provider presets (digid/eherkenning/eidas/generic) merged
 * with a per-organisation override, plus the two pure mapping functions the
 * OIDC callback needs after the ID token has already been fully validated:
 * claim → identityType/identityRef/subjectRef/audience, and broker LoA/`acr`
 * → the portal's existing `low|substantial|high` trust vocabulary.
 *
 * Under-privilege on ambiguity (ADR-005, design.md): an unmapped or missing
 * LoA maps to `low`, never higher — the final safety net is
 * {@see PortalSessionService::normaliseTrust()} itself, so even a malformed
 * `loaMap` VALUE in the org's own config cannot mint a session above `low`.
 * `subjectRef` is either the literal keyword `derive` (the caller — {@see
 * PortalAccountService} — mints/reuses a stable server-side reference) or a
 * `claim:<name>` reference into the ALREADY-VALIDATED ID token claims; it is
 * NEVER read from a request parameter (design.md, contract-v2 IDOR discipline).
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
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Provider presets + claim/LoA mapping for the OIDC broker login edge.
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
 */
class OidcClaimMapperService {
	/**
	 * The special `subjectRef` claim-map keyword: PortalAccountService derives
	 * (mints once, reuses thereafter) the subjectRef server-side rather than
	 * reading it from any claim.
	 */
	public const SUBJECT_REF_DERIVE = 'derive';

	/**
	 * Prefix marking a claim-map value as a claim NAME to read from the
	 * validated ID token, rather than a fixed literal (`audience`) or the
	 * derive keyword (`subjectRef`).
	 */
	private const CLAIM_PREFIX = 'claim:';

	/**
	 * `portalAccount.identityType` is a CLOSED enum (register schema) — an
	 * override outside this set is a misconfiguration and must fail closed,
	 * never silently widen to an arbitrary string.
	 */
	private const VALID_IDENTITY_TYPES = ['eherkenning', 'digid', 'eidas', 'generic', 'nextcloud', 'dev'];

	/**
	 * Broker-agnostic provider presets (design.md). `generic` has no natural
	 * fixed identityType of its own — it defaults to the literal `generic`
	 * enum member but MAY be overridden per-org to any valid identityType.
	 *
	 * @var array<string, array{identityType: string, label: string, scopes: array<int, string>, audience: string, loaClaim: string}>
	 */
	private const PRESETS = [
		'digid' => [
			'identityType' => 'digid',
			'label' => 'DigiD',
			'scopes' => ['openid'],
			'audience' => 'client',
			'loaClaim' => 'acr',
		],
		'eherkenning' => [
			'identityType' => 'eherkenning',
			'label' => 'eHerkenning',
			'scopes' => ['openid'],
			'audience' => 'supplier',
			'loaClaim' => 'acr',
		],
		'eidas' => [
			'identityType' => 'eidas',
			'label' => 'eIDAS',
			'scopes' => ['openid'],
			'audience' => 'client',
			'loaClaim' => 'acr',
		],
		'generic' => [
			'identityType' => 'generic',
			'label' => 'Inloggen',
			'scopes' => ['openid'],
			'audience' => 'supplier',
			'loaClaim' => 'acr',
		],
	];

	/**
	 * Merge a provider preset with a per-organisation raw config override.
	 *
	 * Fails closed (null) on an unrecognised provider — the caller (
	 * `PortalOrganisationConfigService::resolveOidcConfig()`) treats this
	 * identically to "no OIDC config for this org/provider".
	 *
	 * @param string $provider One of `digid|eherkenning|eidas|generic`.
	 * @param array<string, mixed> $rawConfig The org's raw override (`issuer`,
	 *                                        `clientId`, `clientSecret`, and
	 *                                        optionally `scopes`, `claimMap`,
	 *                                        `loaMap`, `identityType`, `audience`,
	 *                                        `loaClaim`).
	 *
	 * @return array<string, mixed>|null The merged config, or null when the
	 *                                   provider is unknown.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
	 */
	public function applyPreset(string $provider, array $rawConfig): ?array {
		$preset = (self::PRESETS[$provider] ?? null);
		if ($preset === null) {
			return null;
		}

		$identityType = $this->resolveIdentityType(preset: $preset, override: ($rawConfig['identityType'] ?? null));
		if ($identityType === null) {
			return null;
		}

		$scopes = ($rawConfig['scopes'] ?? null);
		if (is_array($scopes) === false || $scopes === []) {
			$scopes = $preset['scopes'];
		}

		$claimMap = ($rawConfig['claimMap'] ?? []);
		if (is_array($claimMap) === false) {
			$claimMap = [];
		}

		$loaMap = ($rawConfig['loaMap'] ?? []);
		if (is_array($loaMap) === false) {
			$loaMap = [];
		}

		return [
			'provider' => $provider,
			'label' => (string)($rawConfig['label'] ?? $preset['label']),
			'issuer' => (string)($rawConfig['issuer'] ?? ''),
			'clientId' => (string)($rawConfig['clientId'] ?? ''),
			'clientSecret' => (string)($rawConfig['clientSecret'] ?? ''),
			'scopes' => array_values($scopes),
			'identityType' => $identityType,
			'identityRefClaim' => (string)($claimMap['identityRef'] ?? 'sub'),
			'subjectRefMap' => (string)($claimMap['subjectRef'] ?? self::SUBJECT_REF_DERIVE),
			'audienceMap' => (string)($claimMap['audience'] ?? $preset['audience']),
			'loaClaim' => (string)($rawConfig['loaClaim'] ?? $preset['loaClaim']),
			'loaMap' => $loaMap,
		];
	}//end applyPreset()

	/**
	 * Resolve the fixed `identityType` for a provider: the preset's own value,
	 * or a per-org override — VALIDATED against the closed register enum, so
	 * a typo/malicious override can never mint an out-of-enum identityType
	 * (which OpenRegister would then reject on write, but failing here keeps
	 * the rejection at the mapping boundary rather than a confusing OR error).
	 *
	 * @param array<string, mixed> $preset The provider preset.
	 * @param mixed $override The org's raw `identityType` override, if any.
	 *
	 * @return string|null The identityType, or null when the override is invalid.
	 */
	private function resolveIdentityType(array $preset, mixed $override): ?string {
		if ($override === null || $override === '') {
			return (string)$preset['identityType'];
		}

		if (is_string($override) === false || in_array($override, self::VALID_IDENTITY_TYPES, true) === false) {
			return null;
		}

		return $override;
	}//end resolveIdentityType()

	/**
	 * Map ALREADY-VALIDATED ID token claims to identityType/identityRef/
	 * subjectRef/audience per the merged config. Fails closed (null) on any
	 * unmappable claim — an empty/missing identityRef or audience, or a
	 * `claim:` subjectRef/audience reference to a claim that is absent.
	 *
	 * `subjectRef` is `null` in the returned array when the config says
	 * `derive` — the caller mints/reuses a stable server-side reference; it is
	 * NEVER a request parameter, only ever a validated claim or a server mint.
	 *
	 * @param array<string, mixed> $claims The validated ID token claim set.
	 * @param array<string, mixed> $config The merged provider config (from `applyPreset()`).
	 *
	 * @return array{identityType: string, identityRef: string, subjectRef: string|null, audience: string}|null
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 */
	public function mapClaims(array $claims, array $config): ?array {
		$identityRef = (string)($claims[(string)($config['identityRefClaim'] ?? 'sub')] ?? '');
		if ($identityRef === '') {
			return null;
		}

		$subjectRef = $this->resolveClaimOrDerive(mapping: (string)($config['subjectRefMap'] ?? self::SUBJECT_REF_DERIVE), claims: $claims);
		if ($subjectRef === false) {
			// A `claim:` subjectRef mapping whose claim is absent — unmappable.
			return null;
		}

		$audience = $this->resolveFixedOrClaim(mapping: (string)($config['audienceMap'] ?? ''), claims: $claims);
		if ($audience === null || $audience === '') {
			return null;
		}

		// Null = "derive/reuse server-side"; a non-null string is a
		// validated-claim value, still never a request parameter.
		$resolvedSubjectRef = null;
		if ($subjectRef !== self::SUBJECT_REF_DERIVE) {
			$resolvedSubjectRef = $subjectRef;
		}

		return [
			'identityType' => (string)$config['identityType'],
			'identityRef' => $identityRef,
			'subjectRef' => $resolvedSubjectRef,
			'audience' => $audience,
		];
	}//end mapClaims()

	/**
	 * Map the broker's LoA (an `acr`-style claim, per-org configurable via
	 * `loaClaim`) to the portal trust vocabulary. Unmapped/missing LoA, and
	 * any malformed `loaMap` VALUE, normalise to `low` — never higher
	 * (under-privilege on ambiguity, design.md).
	 *
	 * @param array<string, mixed> $claims The validated ID token claim set.
	 * @param array<string, mixed> $config The merged provider config.
	 *
	 * @return string One of `low`, `substantial`, `high`.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
	 * @spec openspec/specs/supplier-portal/spec.md#broker-loa-maps-to-portal-trust-under-privileging-on-ambiguity
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) -- deliberate reuse of the ONE
	 * canonical trust-normalisation point (PortalSessionService's own
	 * docblock: "the single normalisation point used by the session edge,
	 * the registry, and the contribution controller") — duplicating it here
	 * would risk the trust vocabulary drifting between call sites.
	 */
	public function mapLoaToTrust(array $claims, array $config): string {
		$loaClaim = (string)($config['loaClaim'] ?? 'acr');
		$loaValue = (string)($claims[$loaClaim] ?? '');

		$loaMap = ($config['loaMap'] ?? []);
		if (is_array($loaMap) === false) {
			$loaMap = [];
		}

		$mapped = ($loaMap[$loaValue] ?? 'low');

		return PortalSessionService::normaliseTrust(trust: $mapped);
	}//end mapLoaToTrust()

	/**
	 * Resolve a `subjectRef`-style mapping: the literal `derive` keyword
	 * passes through unchanged (caller derives), a `claim:<name>` reference
	 * reads that claim (empty/absent → unmappable), anything else also
	 * derives (a bare literal can never be a safe per-user subjectRef, so it
	 * is treated as `derive` rather than as a dangerous shared literal).
	 *
	 * @param string $mapping The configured mapping value.
	 * @param array<string, mixed> $claims The validated ID token claim set.
	 *
	 * @return string|false The resolved value (or the `derive` keyword
	 *                      unchanged), or `false` when a `claim:` reference
	 *                      is unmappable.
	 */
	private function resolveClaimOrDerive(string $mapping, array $claims): string|false {
		if ($mapping === self::SUBJECT_REF_DERIVE) {
			return self::SUBJECT_REF_DERIVE;
		}

		if (str_starts_with($mapping, self::CLAIM_PREFIX) === true) {
			$claimName = substr($mapping, strlen(self::CLAIM_PREFIX));
			$value = (string)($claims[$claimName] ?? '');
			if ($value === '') {
				return false;
			}

			return $value;
		}

		// Any other literal is not a safe per-user subjectRef — derive instead.
		return self::SUBJECT_REF_DERIVE;
	}//end resolveClaimOrDerive()

	/**
	 * Resolve an `audience`-style mapping: a `claim:<name>` reference reads
	 * that claim (absent → null, unmappable); anything else is a FIXED
	 * literal value (design.md: `audience: <fixed | claim>`).
	 *
	 * @param string $mapping The configured mapping value.
	 * @param array<string, mixed> $claims The validated ID token claim set.
	 *
	 * @return string|null The resolved audience, or null when unmappable.
	 */
	private function resolveFixedOrClaim(string $mapping, array $claims): ?string {
		if (str_starts_with($mapping, self::CLAIM_PREFIX) === true) {
			$claimName = substr($mapping, strlen(self::CLAIM_PREFIX));
			$value = (string)($claims[$claimName] ?? '');
			if ($value === '') {
				return null;
			}

			return $value;
		}

		if ($mapping === '') {
			return null;
		}

		return $mapping;
	}//end resolveFixedOrClaim()

	/**
	 * The provider labels available for the SPA (no secrets, no issuer/config
	 * — just the enum + human label), used to validate a provider string and
	 * to build the SPA's login-button labels.
	 *
	 * @return array<string, string> provider => label.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T05
	 */
	public function providerLabels(): array {
		$labels = [];
		foreach (self::PRESETS as $provider => $preset) {
			$labels[$provider] = (string)$preset['label'];
		}

		return $labels;
	}//end providerLabels()
}//end class
