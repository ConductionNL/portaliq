<?php

/**
 * Portaliq case type column settings
 *
 * Reads and saves, per portal, how the columns of each case type read in
 * Mijn zaken (`portal.caseTypeColumns`), and names the fields the case apps
 * show there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\CaseTypeColumns
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\CaseTypeColumns;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * The portal's column settings per case type.
 *
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
class CaseTypeColumnSettings {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $writer Writes the portal.
	 * @param PortalContributionRegistry $registry The contributing apps.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly PortalContributionRegistry $registry,
	) {
	}//end __construct()

	/**
	 * The stored columns of a portal, per case type id.
	 *
	 * @param array<string, mixed>|null $portal The portal.
	 *
	 * @return array<string, array<int, array{field: string, render: string, linkLabel?: string}>>
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function storedFor(?array $portal): array {
		return (new CaseTypeColumnsNormaliser())->byType(portal: $portal);
	}//end storedFor()

	/**
	 * The fields the case apps show in Mijn zaken, each once, with its label.
	 *
	 * @return array<int, array{field: string, label: string}>
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function fields(): array {
		$fields = [];
		foreach ((new CasesCollections(registry: $this->registry))->all() as $collection) {
			foreach ($this->columnsOf(collection: $collection) as $column) {
				$fields[$column['field']] = ($fields[$column['field']] ?? $column);
			}
		}

		return array_values($fields);
	}//end fields()

	/**
	 * A collection's columns that name a field, as field and label.
	 *
	 * @param array<string, mixed> $collection The `cases` collection.
	 *
	 * @return array<int, array{field: string, label: string}>
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	private function columnsOf(array $collection): array {
		$out = [];
		foreach (array_filter((array)($collection['columns'] ?? []), 'is_array') as $column) {
			$field = (string)($column['field'] ?? '');
			if ($field !== '') {
				$out[] = ['field' => $field, 'label' => (string)($column['label'] ?? $field)];
			}
		}

		return $out;
	}//end columnsOf()

	/**
	 * Save the columns of one case type, leaving the other types as stored.
	 * An empty list removes the type's settings.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param array{typeId: string, columns: array<int|string, mixed>} $change The case type and its columns.
	 *
	 * @return array<string, mixed>|null The saved portal, or null when the write failed.
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function save(array $portal, array $change): ?array {
		return $this->writer->updateObject(
			register: 'portaliq',
			schema: 'portal',
			scopeField: 'slug',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			id: (string)($portal['id'] ?? $portal['uuid'] ?? ($portal['@self']['id'] ?? '')),
			data: fn (array $existing): array => ['caseTypeColumns' => $this->merged(existing: $existing, change: $change)]
		);
	}//end save()

	/**
	 * The stored settings with one case type replaced.
	 *
	 * @param array<string, mixed> $existing The portal as stored.
	 * @param array{typeId: string, columns: array<int|string, mixed>} $change The case type and its columns.
	 *
	 * @return array<string, mixed>|\stdClass An empty object when no type is left, so it stores as `{}`.
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	private function merged(array $existing, array $change): array|\stdClass {
		$stored = $this->storedFor(portal: $existing);
		$typeId = $change['typeId'];
		unset($stored[$typeId]);
		$columns = (new CaseTypeColumnsNormaliser())->normalise(columns: $change['columns']);
		if ($columns !== []) {
			$stored[$typeId] = $columns;
		}

		if ($stored === []) {
			return new \stdClass();
		}

		return $stored;
	}//end merged()
}//end class
