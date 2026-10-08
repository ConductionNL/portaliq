<?php

/**
 * Portaliq Action Scope Resolver
 *
 * What an endpoint action's forward carries from the subject's resolved
 * scope: the body with a declared `subjectField` stamped by the server
 * (portal-take-assessment), and the value of a declared `scopeClaim` for the
 * signed assertion (case-actions-sign-a-document D3). Shared by the
 * id-addressed and the row-scoped forward, so both resolve it the same way.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Resolves the subject's scope an endpoint action declares it needs.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-endpoint-bearer-forward-actions
 */
class ActionScopeResolver {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Resolves a scope claim for a subject.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The forward's body and assertion scope value, or null when a declared
	 * `subjectField` or `scopeClaim` does not resolve (the forward stops).
	 *
	 * The body is the given one unless the action declares `subjectField`,
	 * which is then stamped over any client value. The scope value is '' when
	 * the action declares no `scopeClaim`.
	 *
	 * @param array<string, mixed> $action The authorised endpoint action.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $appId The contributing app (the claim namespace).
	 * @param array<string, mixed>|null $body The whitelisted body, or null to relay raw.
	 *
	 * @return array{body: array<string, mixed>|null, scopeValue: string}|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	public function prepare(array $action, array $subject, string $appId, ?array $body): ?array {
		if (is_string($action['subjectField'] ?? null) === true) {
			$stamp = $this->resolve(scopeClaim: (string)($action['scopeClaim'] ?? ''), appId: $appId, subject: $subject);
			if ($stamp === null) {
				return null;
			}

			$body = ($body ?? []);
			$body[$action['subjectField']] = $stamp;
		}

		$scopeValue = '';
		$scopeClaim = ($action['scopeClaim'] ?? '');
		if (is_string($scopeClaim) === true && $scopeClaim !== '') {
			$scopeValue = $this->resolve(scopeClaim: $scopeClaim, appId: $appId, subject: $subject);
			if ($scopeValue === null) {
				return null;
			}
		}

		return ['body' => $body, 'scopeValue' => $scopeValue];
	}//end prepare()

	/**
	 * What a create action stamps into its scope field: the declared
	 * `scopeClaim` resolved from the subject's own portal account, else the
	 * subject's subjectRef; null when a declared claim is absent
	 * (claim-scoped-create-stamps-the-claim).
	 *
	 * @param array<string, mixed> $action The create action.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $appId The contributing app.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
	 */
	public function createStamp(array $action, array $subject, string $appId): ?string {
		return $this->resolve(scopeClaim: (string)($action['scopeClaim'] ?? ''), appId: $appId, subject: $subject);
	}//end createStamp()

	/**
	 * The subject's value for a scope claim, or null when it does not resolve.
	 *
	 * @param string $scopeClaim The claim ('' reads the subject reference).
	 * @param string $appId The contributing app.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return string|null
	 */
	private function resolve(string $scopeClaim, string $appId, array $subject): ?string {
		$value = $this->reader->resolveScopeValue(scopeClaim: $scopeClaim, contributingApp: $appId, subject: $subject);
		if ($value === null || $value === '') {
			return null;
		}

		return $value;
	}//end resolve()
}//end class
