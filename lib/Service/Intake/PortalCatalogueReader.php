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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Lists the published catalogue entries, grouped by their topic.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
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
	 * @param PortalObjectReader $reader Reads the published catalogue.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The published entries for one portal, grouped by topic.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> Topics, each with its entries.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function topicsFor(string $portal): array {
		if ($portal === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			limit: self::ROW_LIMIT,
			filter: ['status' => 'published']
		);

		$topics = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['portal'] ?? '') !== $portal) {
				continue;
			}

			if ((string)($row['status'] ?? '') !== 'published') {
				// A withdrawn entry is simply not listed. Nothing is deleted
				// here, because nothing was kept.
				continue;
			}

			$topic = (string)($row['topic'] ?? '');
			if (isset($topics[$topic]) === false) {
				$topics[$topic] = ['topic' => $topic, 'entries' => []];
			}

			$topics[$topic]['entries'][] = [
				'title' => (string)($row['title'] ?? ''),
				'summary' => (string)($row['summary'] ?? ''),
				// The route is what starts the form: the entry point hands the
				// visitor to the binding, it does not describe the form itself.
				'route' => (string)($row['route'] ?? ''),
			];
		}//end foreach

		return array_values($topics);
	}//end topicsFor()
}//end class
