<?php

/**
 * Portaliq Portal Ways In Resolver
 *
 * Which doors the sign-in screen shows besides the sign-in buttons
 * (identity-ways-in-screens D3): "Create an account" when the registration
 * policy is on and the portal offers an e-mail based sign-in to finish with,
 * and "Follow a case with its case number" when a case type the portal binds
 * admits the reference kind. A door that leads nowhere is not shown.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-the-sign-in-screen-shows-only-the-doors-that-lead-somewhere-req-iwi-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;

/**
 * Decides the `waysIn` block of the portal SPA's runtime config.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-the-sign-in-screen-shows-only-the-doors-that-lead-somewhere-req-iwi-005
 */
class PortalWaysInResolver {

	/**
	 * The sign-in provider that carries a verified e-mail address. DigiD,
	 * eHerkenning and eIDAS sign people in on a BSN, a KvK number or a
	 * foreign identity, never on an address.
	 */
	public const EMAIL_PROVIDER = 'generic';

	/**
	 * Constructor.
	 *
	 * @param PortalRegistrationPolicyService $policy The portal's registration policy.
	 * @param PortalFormBindingResolver $bindings The case types the portal binds.
	 * @param CaseTypeReader $caseTypes Reads a bound case type.
	 * @param PortalReferenceLinkService $references Whether a case type admits the reference kind.
	 */
	public function __construct(
		private readonly PortalRegistrationPolicyService $policy,
		private readonly PortalFormBindingResolver $bindings,
		private readonly CaseTypeReader $caseTypes,
		private readonly PortalReferenceLinkService $references,
	) {
	}//end __construct()

	/**
	 * The doors of a portal.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 * @param array<int, array<string, mixed>> $oidcProviders The sign-in buttons it offers.
	 *
	 * @return array{register: bool, reference: bool, emailSignIn: string, referenceCaseTypes: array<int, array<string, string>>}
	 *         `referenceCaseTypes` holds `register`, `schema`, `caseType` and `label` per case type.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T07
	 */
	public function waysIn(array $portal, array $oidcProviders): array {
		$emailSignIn = '';
		foreach ($oidcProviders as $provider) {
			if (is_array($provider) === true && ($provider['provider'] ?? '') === self::EMAIL_PROVIDER) {
				$emailSignIn = (string)($provider['label'] ?? self::EMAIL_PROVIDER);
				break;
			}
		}

		$caseTypes = $this->referenceCaseTypes(portal: $portal);

		return [
			'register' => $emailSignIn !== '' && $this->policy->isOffered(site: $portal) === true,
			'reference' => $caseTypes !== [],
			'emailSignIn' => $emailSignIn,
			'referenceCaseTypes' => $caseTypes,
		];
	}//end waysIn()

	/**
	 * The portal's bound case types that admit the reference kind, by their
	 * public label.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return array<int, array{register: string, schema: string, caseType: string, label: string}>
	 */
	private function referenceCaseTypes(array $portal): array {
		$offered = [];
		foreach ($this->bindings->declaredCaseTypes(portal: (string)($portal['slug'] ?? '')) as [$register, $schema, $typeId]) {
			$type = $this->caseTypes->readCaseType(register: $register, schema: $schema, id: $typeId);
			if ($type === null || $this->references->admitsReference(caseType: $type) === false) {
				continue;
			}

			$offered[] = [
				'register' => $register,
				'schema' => $schema,
				'caseType' => $typeId,
				'label' => (string)($type['title'] ?? ($type['name'] ?? $typeId)),
			];
		}

		return $offered;
	}//end referenceCaseTypes()
}//end class
