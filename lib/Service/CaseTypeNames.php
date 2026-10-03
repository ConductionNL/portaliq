<?php

/**
 * Portaliq Case Type Names (site-mijn-omgeving-components)
 *
 * "Mijn zaken" names each case's type: the name a `cases` collection's
 * `caseTypeSource` gives it, the same name PortalCaseTypeCatalogue shows an
 * administrator. The types of one source are read once and kept for the rest
 * of the request, so a list of a hundred cases costs one read per source.
 *
 * A type that does not resolve gets no name, never its id.
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
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Stamps `_caseTypeName` on a case row from its collection's case type source.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
 */
class CaseTypeNames {
	/**
	 * The fields a case type's name is read from when the source names none,
	 * the same as PortalCaseTypeCatalogue.
	 */
	private const LABEL_FIELDS = ['title', 'name', 'label', 'omschrijving'];

	/**
	 * The names read so far, by `register/schema/labelField`, then type id.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $names = [];

	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader $reader Reads a source's case types.
	 */
	public function __construct(
		private readonly CaseTypeReader $reader,
	) {
	}//end __construct()

	/**
	 * The row with `_caseTypeName` when its type resolves; unchanged otherwise.
	 *
	 * @param array<string, mixed> $row        The case row.
	 * @param array<string, mixed> $collection The `cases` collection it came from.
	 * @param string               $typeId     The row's case type id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
	 */
	public function stamp(array $row, array $collection, string $typeId): array {
		if ($typeId === '') {
			return $row;
		}

		$name = ($this->namesFor(collection: $collection)[$typeId] ?? '');
		if ($name !== '') {
			$row['_caseTypeName'] = $name;
		}

		return $row;
	}//end stamp()

	/**
	 * The names of a collection's case types, by id; [] without a source.
	 *
	 * @param array<string, mixed> $collection The `cases` collection.
	 *
	 * @return array<string, string>
	 */
	private function namesFor(array $collection): array {
		$source = ($collection['caseTypeSource'] ?? null);
		if (is_array($source) === false) {
			return [];
		}

		$register = (string)($source['register'] ?? '');
		$schema = (string)($source['schema'] ?? '');
		$labelField = (string)($source['labelField'] ?? '');
		if ($register === '' || $schema === '') {
			return [];
		}

		$key = $register . '/' . $schema . '/' . $labelField;
		if (isset($this->names[$key]) === false) {
			$names = [];
			foreach ($this->reader->listCaseTypes(register: $register, schema: $schema) as $type) {
				$typeId = (string)($type['id'] ?? $type['uuid'] ?? ($type['@self']['id'] ?? ''));
				$label = $this->labelOf(type: $type, labelField: $labelField);
				if ($typeId !== '' && $label !== '') {
					$names[$typeId] = $label;
				}
			}

			$this->names[$key] = $names;
		}

		return $this->names[$key];
	}//end namesFor()

	/**
	 * A case type's name: the source's label field first.
	 *
	 * @param array<string, mixed> $type       The case type.
	 * @param string               $labelField The field the source names, or ''.
	 *
	 * @return string
	 */
	private function labelOf(array $type, string $labelField): string {
		$fields = self::LABEL_FIELDS;
		if ($labelField !== '') {
			array_unshift($fields, $labelField);
		}

		foreach ($fields as $field) {
			$value = ($type[$field] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end labelOf()
}//end class
