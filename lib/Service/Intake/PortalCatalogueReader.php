<?php

/**
 * Portaliq Portal Catalogue Reader
 *
 * The requests a citizen can start, read from the catalogue opencatalogi
 * publishes, at the moment the entry point is opened.
 *
 * Portaliq keeps no copy. An entry withdrawn from the published catalogue is
 * gone from the portal on the next request, with no portal change and nothing
 * to clean up.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\PortalCatalogueReaderInterface;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use Throwable;

/**
 * Lists the published catalogue entries, grouped by their topic.
 *
 * @spec openspec/specs/portal-intake-form/spec.md
 */
class PortalCatalogueReader implements PortalCatalogueReaderInterface {
	/**
	 * The register opencatalogi publishes into.
	 */
	private const REGISTER = 'opencatalogi';

	/**
	 * The schema a published request entry uses.
	 */
	private const SCHEMA = 'publication';

	/**
	 * Row cap for one entry point render.
	 */
	private const ROW_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Reads the published catalogue.
	 * @param PortalFormBindingResolver|null $bindings The portal's published
	 *                                                 bindings, to find the
	 *                                                 routes of hidden types.
	 * @param CaseTypeVisibility|null $caseTypes The case types a portal hides
	 *                                           (operate-show-per-case-type).
	 *                                           Either absent hides nothing.
	 * @param PortalFee|null $fees The fee a binding's case type declares;
	 *                             absent, no entry carries a fee.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly ?PortalFormBindingResolver $bindings = null,
		private readonly ?CaseTypeVisibility $caseTypes = null,
		private readonly ?PortalFee $fees = null,
	) {
	}//end __construct()

	/**
	 * The published entries for one portal, grouped by topic.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> Topics, each with its entries.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function topicsFor(string $portal): array {
		if ($portal === '') {
			return [];
		}

		$published = $this->publishedRows(portal: $portal);
		$bindings  = $this->publishedBindings(portal: $portal);
		$rows      = $this->listedRows(rows: $published, hiddenRoutes: $this->hiddenRoutes(bindings: $bindings), portal: $portal);

		return array_values($this->byTopic(rows: $rows, bindings: $bindings));
	}//end topicsFor()

	/**
	 * The portal's published form bindings; none without a binding resolver.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function publishedBindings(string $portal): array {
		if ($this->bindings === null) {
			return [];
		}

		return $this->bindings->publishedBindings(portal: $portal);
	}//end publishedBindings()

	/**
	 * The portal's catalogue rows the register holds as published.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<mixed>
	 */
	private function publishedRows(string $portal): array {
		return $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			limit: self::ROW_LIMIT,
			filter: ['status' => 'published']
		);
	}//end publishedRows()

	/**
	 * The rows of this portal that are published and do not start a form for
	 * a case type the portal hides (operate-show-per-case-type REQ-OSC-002).
	 * A withdrawn entry is simply not listed. Nothing is deleted here,
	 * because nothing was kept.
	 *
	 * @param array<mixed>       $rows         The rows read.
	 * @param array<int, string> $hiddenRoutes The routes of hidden case types.
	 * @param string             $portal       The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listedRows(array $rows, array $hiddenRoutes, string $portal): array {
		$listed = [];
		foreach ($rows as $row) {
			if ($this->isPublishedOn(row: $row, portal: $portal) === true && in_array((string)($row['route'] ?? ''), $hiddenRoutes, true) === false) {
				$listed[] = $row;
			}
		}

		return $listed;
	}//end listedRows()

	/**
	 * Whether a row is a published entry of this portal.
	 *
	 * @param mixed  $row    The row.
	 * @param string $portal The portal slug.
	 *
	 * @return bool
	 */
	private function isPublishedOn(mixed $row, string $portal): bool {
		return is_array($row) === true && ($row['portal'] ?? '') === $portal && (string)($row['status'] ?? '') === 'published';
	}//end isPublishedOn()

	/**
	 * The rows grouped by topic, each topic where its first entry was.
	 *
	 * @param array<int, array<string, mixed>> $rows     The listed rows.
	 * @param array<int, array<string, mixed>> $bindings The portal's published bindings.
	 *
	 * @return array<string, array{topic: string, entries: array<int, array<string, mixed>>}>
	 */
	private function byTopic(array $rows, array $bindings): array {
		$topics = [];
		foreach ($rows as $row) {
			$topic = (string)($row['topic'] ?? '');
			if (isset($topics[$topic]) === false) {
				$topics[$topic] = ['topic' => $topic, 'entries' => []];
			}

			$topics[$topic]['entries'][] = $this->entry(row: $row, bindings: $bindings);
		}

		return $topics;
	}//end byTopic()

	/**
	 * One entry: its title, its summary, the route that starts its form and
	 * the fee its binding's case type declares (null when none). Conditions
	 * are added when the publication carries them as a list of lines. The
	 * entry point hands the visitor to the binding, it does not describe the
	 * form itself.
	 *
	 * @param array<string, mixed>             $row      The row.
	 * @param array<int, array<string, mixed>> $bindings The portal's published bindings.
	 *
	 * @return array<string, mixed> title, summary, route, fee (amount, currency, description; null when none) and, when listed, conditions.
	 *
	 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
	 */
	private function entry(array $row, array $bindings): array {
		$route = (string)($row['route'] ?? '');
		$entry = [
			'title' => (string)($row['title'] ?? ''),
			'summary' => (string)($row['summary'] ?? ''),
			'route' => $route,
			'fee' => $this->feeFor(route: $route, bindings: $bindings),
		];

		$conditions = self::conditions(value: ($row['conditions'] ?? null));
		if ($conditions !== null) {
			$entry['conditions'] = $conditions;
		}

		return $entry;
	}//end entry()

	/**
	 * The fee the case type of the binding for a route declares, read on the
	 * server; null when there is no fee reader, no binding, no usable fee, or
	 * the case type cannot be read.
	 *
	 * @param string                           $route    The entry's route.
	 * @param array<int, array<string, mixed>> $bindings The portal's published bindings.
	 *
	 * @return array{amount: string, currency: string, description: string}|null
	 *
	 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
	 */
	private function feeFor(string $route, array $bindings): ?array {
		if ($this->fees === null || $route === '') {
			return null;
		}

		foreach ($bindings as $binding) {
			if ((string)($binding['route'] ?? '') !== $route) {
				continue;
			}

			try {
				$fee = $this->fees->forBinding(binding: $binding);
			} catch (Throwable) {
				return null;
			}

			if ($fee === null) {
				return null;
			}

			return ['amount' => $fee['amount'], 'currency' => $fee['currency'], 'description' => $fee['description']];
		}

		return null;
	}//end feeFor()

	/**
	 * A publication's conditions when they are a list of non-empty strings,
	 * trimmed; null for any other shape.
	 *
	 * @param mixed $value The `conditions` value.
	 *
	 * @return list<string>|null
	 *
	 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
	 */
	private static function conditions(mixed $value): ?array {
		if (is_array($value) === false || $value === [] || array_is_list($value) === false) {
			return null;
		}

		$lines = [];
		foreach ($value as $line) {
			if (is_string($line) === false || trim($line) === '') {
				return null;
			}

			$lines[] = trim($line);
		}

		return $lines;
	}//end conditions()

	/**
	 * The routes of a portal whose published binding names a case type the
	 * portal hides.
	 *
	 * @param array<int, array<string, mixed>> $bindings The portal's published bindings.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	private function hiddenRoutes(array $bindings): array {
		if ($this->caseTypes === null) {
			return [];
		}

		$routes = [];
		foreach ($bindings as $binding) {
			if ($this->caseTypes->hidesBinding(binding: $binding) === true) {
				$routes[] = (string)($binding['route'] ?? '');
			}
		}

		return $routes;
	}//end hiddenRoutes()
}//end class
