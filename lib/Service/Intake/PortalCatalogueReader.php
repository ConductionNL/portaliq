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

use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;

/**
 * Lists the published catalogue entries, grouped by their topic.
 *
 * @spec openspec/specs/portal-intake-form/spec.md
 */
class PortalCatalogueReader {
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
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly ?PortalFormBindingResolver $bindings = null,
		private readonly ?CaseTypeVisibility $caseTypes = null,
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

		$rows = $this->listedRows(rows: $this->publishedRows(portal: $portal), portal: $portal);

		return array_values($this->byTopic(rows: $rows));
	}//end topicsFor()

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
	 * @param array<mixed> $rows   The rows read.
	 * @param string       $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listedRows(array $rows, string $portal): array {
		$hiddenRoutes = $this->hiddenRoutes(portal: $portal);
		$listed       = [];
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
	 * @param array<int, array<string, mixed>> $rows The listed rows.
	 *
	 * @return array<string, array{topic: string, entries: array<int, array<string, string>>}>
	 */
	private function byTopic(array $rows): array {
		$topics = [];
		foreach ($rows as $row) {
			$topic = (string)($row['topic'] ?? '');
			if (isset($topics[$topic]) === false) {
				$topics[$topic] = ['topic' => $topic, 'entries' => []];
			}

			$topics[$topic]['entries'][] = $this->entry(row: $row);
		}

		return $topics;
	}//end byTopic()

	/**
	 * One entry: its title, its summary and the route that starts its form.
	 * The entry point hands the visitor to the binding, it does not describe
	 * the form itself.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array{title: string, summary: string, route: string}
	 */
	private function entry(array $row): array {
		return [
			'title' => (string)($row['title'] ?? ''),
			'summary' => (string)($row['summary'] ?? ''),
			'route' => (string)($row['route'] ?? ''),
		];
	}//end entry()

	/**
	 * The routes of a portal whose published binding names a case type the
	 * portal hides.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	private function hiddenRoutes(string $portal): array {
		if ($this->bindings === null || $this->caseTypes === null) {
			return [];
		}

		$routes = [];
		foreach ($this->bindings->publishedBindings(portal: $portal) as $binding) {
			if ($this->caseTypes->hidesBinding(binding: $binding) === true) {
				$routes[] = (string)($binding['route'] ?? '');
			}
		}

		return $routes;
	}//end hiddenRoutes()
}//end class
