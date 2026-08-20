<?php

/**
 * Portaliq Contribution Manifest Normaliser (contribution-manifest-v3)
 *
 * The single, fail-closed validation point for the v3 UI-configuration
 * vocabulary (ADR-046 / ADR-063). Runs per contribution AFTER trust filtering
 * and BEFORE the aggregate is returned, so that:
 *
 *  - trust-dropped collections/actions can never be referenced by a surviving
 *    page block (references resolve against the already-filtered contribution);
 *  - the frontend engine receives a canonical, safe config and never has to
 *    defend against malformed manifests itself;
 *  - a buggy or hostile contributing provider cannot widen data access through
 *    presentation config.
 *
 * This class is the ORCHESTRATOR of that one validation point; the rules
 * themselves live in three cohesive collaborators — CollectionConfigNormaliser,
 * ActionConfigNormaliser (with ActionOptionsNormaliser) and PortalPageResolver
 * — over the shared ManifestValueNormaliser primitives. The ordering here is
 * the security-relevant part: collections and actions are sanitised FIRST, so
 * both the `rowActions` resolution and the page-block references can only ever
 * resolve against entries that already survived.
 *
 * INVARIANT: this pipeline is presentation-only. It NEVER adds a field to an
 * action's `fields` whitelist, never alters a collection's scope / scopeClaim /
 * via / projection, and never throws — every reject path returns the safe
 * subset. The data-access authorities (whitelist, scope, projection) established
 * by contract v2 remain untouched; they are read-only inputs here.
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
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-and-elevated-trust-must-not-combine-on-one-entry
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\PortalSchemaReader;

/**
 * Validates and sanitises the v3 UI-configuration vocabulary, fail-closed.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
 */
class PortalManifestNormaliser {

	/**
	 * The collection-half validator.
	 *
	 * @var CollectionConfigNormaliser
	 */
	private readonly CollectionConfigNormaliser $collections;

	/**
	 * The action-half validator.
	 *
	 * @var ActionConfigNormaliser
	 */
	private readonly ActionConfigNormaliser $actions;

	/**
	 * The page/block resolver.
	 *
	 * @var PortalPageResolver
	 */
	private readonly PortalPageResolver $pages;

	/**
	 * Constructor.
	 *
	 * The collaborators are pure value transformers with no I/O of their own,
	 * so they are composed here rather than injected — this keeps the class
	 * constructible with no arguments (its pre-existing default-value call site
	 * in PortalContributionRegistry and every direct unit-test instantiation).
	 *
	 * @param PortalSchemaReader|null $schemaReader Resolves an action's schema
	 *                                              `required` set for the WMEBV
	 *                                              data-minimisation guard.
	 *                                              Optional/nullable; a null
	 *                                              reader simply means the guard
	 *                                              always fails closed (drops
	 *                                              `required`).
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#form-data-minimisation-no-non-mandatory-field-may-be-required
	 */
	public function __construct(?PortalSchemaReader $schemaReader = null) {
		$values = new ManifestValueNormaliser();

		$this->collections = new CollectionConfigNormaliser($values);
		$this->actions = new ActionConfigNormaliser(
			$values,
			new ActionOptionsNormaliser($values),
			$schemaReader
		);
		$this->pages = new PortalPageResolver(new PortalBlockResolver());
	}//end __construct()

	/**
	 * Normalise one (already trust-filtered) contribution manifest.
	 *
	 * @param array<string, mixed> $contribution The trust-filtered contribution.
	 *
	 * @return array<string, mixed> The contribution with sanitised v3 config and
	 *                              a resolved/synthesised `pages` array.
	 *
	 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T3
	 */
	public function normalise(array $contribution): array {
		$collections = $this->collections->normaliseCollections(collections: (array)($contribution['collections'] ?? []));
		$actions = $this->actions->normaliseActions(actions: (array)($contribution['actions'] ?? []));

		// Resolve each collection's `rowActions` against the update actions in
		// THIS contribution — a per-row transition button (approve/reject/close)
		// may only reference a `type: update` action the subject is entitled to.
		$collections = $this->collections->resolveRowActions(collections: $collections, actions: $actions);

		$contribution['collections'] = $collections;
		$contribution['actions'] = $actions;
		$contribution['pages'] = $this->pages->normalisePages(
			pages: ($contribution['pages'] ?? null),
			collections: $collections,
			actions: $actions
		);

		return $contribution;
	}//end normalise()
}//end class
