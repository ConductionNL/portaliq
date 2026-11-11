<?php

/**
 * Portaliq Subject Collection Finder
 *
 * Finds the collection a route names among the subject's own aggregated
 * contributions, with the app that contributes it.
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
 * @spec exclude Shared lookup; the @spec of each calling route covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;

/**
 * The subject's collection on a register and schema.
 *
 * @spec exclude Shared lookup; the @spec of each calling route covers the behaviour it reads for.
 */
final class SubjectCollectionFinder {
	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The contribution registry.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
	) {
	}//end __construct()

	/**
	 * The first collection of the subject's contributions on the requested
	 * register and schema, with its app, or null when the subject is not
	 * entitled to one.
	 *
	 * When a collection id is supplied it MUST match — this is what
	 * distinguishes two collections that share a register+schema (e.g. a
	 * direct view and a scopeClaim/via view of the same schema). An empty
	 * id keeps the first-match fallback.
	 *
	 * @param array<string, mixed>                                      $subject   The resolved subject.
	 * @param array{register: string, schema: string, collection: string} $requested The register, schema and collection id ('' for any).
	 *
	 * @return array{collection: array<string, mixed>, app: string}|null
	 *
	 * @spec exclude Shared lookup; the @spec of each calling route covers the behaviour it reads for.
	 */
	public function find(array $subject, array $requested): ?array {
		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			foreach (($contribution['collections'] ?? []) as $collection) {
				if (self::isRequested(collection: $collection, requested: $requested) === true) {
					return ['collection' => $collection, 'app' => (string)($contribution['app'] ?? '')];
				}
			}
		}

		return null;
	}//end find()

	/**
	 * Whether a collection is the requested one: its register and schema,
	 * and its id when one is requested.
	 *
	 * @param array<string, mixed>                                      $collection The collection.
	 * @param array{register: string, schema: string, collection: string} $requested The request.
	 *
	 * @return bool
	 */
	private static function isRequested(array $collection, array $requested): bool {
		return ($collection['register'] ?? '') === $requested['register']
			&& ($collection['schema'] ?? '') === $requested['schema']
			&& ($requested['collection'] === '' || ($collection['id'] ?? '') === $requested['collection']);
	}//end isRequested()
}//end class
