<?php

/**
 * Contract of PortalFormBindingResolver
 *
 * The public surface of PortalFormBindingResolver that other components call, so they depend
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
 * Contract of PortalFormBindingResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFormBindingResolverInterface {

	/**
	 * A binding naming a start form on another host.
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * Every published binding, of every portal.
	 *
	 * For the one caller that asks about the whole instance: a destination
	 * schema is about to change and its forms must be found, whichever
	 * portal shows them. Never reached from a visitor's request.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 */
	public function allPublishedBindings(): array;

	/**
	 * The binding a portal route carries, or null.
	 *
	 * @param string $portal The portal slug.
	 * @param string $route The in-portal route.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	public function bindingFor(string $portal, string $route): ?array;

	/**
	 * Whether a case type is inside the scope a portal declared.
	 *
	 * The reference-link route takes its register, schema and case type from
	 * an anonymous request. Without this, naming any three values would reach
	 * any object on the instance, because the read behind it runs with RBAC
	 * and multitenancy off. The answer is false unless a published binding of
	 * THIS portal names exactly that triple.
	 *
	 * @param string $portal The portal slug.
	 * @param string $register The register named in the request.
	 * @param string $schema The schema named in the request.
	 * @param string $typeId The case type named in the request.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function caseTypeIsInPortalScope(string $portal, string $register, string $schema, string $typeId): bool;

	/**
	 * What the page should render for a binding.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array<string, mixed> The render payload: `kind`, and either the
	 *         resolved form (`fields`, `order`, `confirmationText`, the intake
	 *         settings) or `resolvesToNoForm` true with the reason the admin
	 *         surface prints.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-condition-the-portal-cannot-check-refuses-the-form-req-icq-003
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
	 */
	public function render(array $binding): array;

	/**
	 * The sign-in level a submission of this form needs, or null for none.
	 *
	 * The strictest of the portal's, the binding's and the form's own level;
	 * see PortalFormTrustLevel for what each declared value means.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render What render() returned for it.
	 *
	 * @return string|null `low`, `substantial`, `high`,
	 *                     PortalFormTrustLevel::UNRECOGNISED, or null when an
	 *                     anonymous visitor may fill the form in.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function requiredTrust(array $site, array $binding, array $render): ?string;
}//end interface
