<?php

/**
 * Portaliq portal case type catalogue
 *
 * What an administrator sees on a portal's "Case types" page: every case
 * type the portal can name, each shown or hidden, and the save that writes
 * the portal's hidden list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;

/**
 * Lists and saves the case types a portal shows.
 *
 * A portal can name a case type three ways: a published form binding for it,
 * a case app that declares where its case types live (`caseTypeSource` on a
 * `cases` collection), or the portal's own hidden list. The last one keeps a
 * hidden type on the page even when nothing else names it any more.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
class PortalCaseTypeCatalogue {
	/**
	 * The register the portal objects live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The portal schema.
	 */
	private const SCHEMA = 'portal';

	/**
	 * The fields a case type's name is looked for in, in order.
	 */
	private const LABEL_FIELDS = ['title', 'name', 'label', 'omschrijving'];

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the portal.
	 * @param PortalObjectWriter $writer Writes the portal's hidden list.
	 * @param PortalFormBindingResolver $bindings The portal's published bindings.
	 * @param CaseTypeReader $caseTypes Reads case types for their names.
	 * @param CaseTypeVisibility $visibility Reads the portal's hidden list.
	 * @param PortalContributionRegistry $registry The case apps' declarations.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalFormBindingResolver $bindings,
		private readonly CaseTypeReader $caseTypes,
		private readonly CaseTypeVisibility $visibility,
		private readonly PortalContributionRegistry $registry,
	) {
	}//end __construct()

	/**
	 * One portal by slug, in any status, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function portalBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'slug',
			subjectRef: $slug,
			organisation: '',
			limit: 2
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portalBySlug()

	/**
	 * Every case type the portal can name, each with whether it is shown.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array<int, array{register: string, schema: string, typeId: string, label: string, shown: bool}>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function listFor(array $portal): array {
		$listed = [];
		foreach ($this->bindings->declaredCaseTypes(portal: (string)($portal['slug'] ?? '')) as [$register, $schema, $typeId]) {
			$type = ($this->caseTypes->readCaseType(register: $register, schema: $schema, id: $typeId) ?? []);
			$label = $this->labelOf(type: $type, labelField: '');
			$listed[$typeId] = $this->entry(register: $register, schema: $schema, typeId: $typeId, label: $label);
		}

		foreach ($this->declaredSources() as $source) {
			foreach ($this->caseTypes->listCaseTypes(register: $source['register'], schema: $source['schema']) as $type) {
				$typeId = $this->idOf(type: $type);
				if ($typeId === '' || isset($listed[$typeId]) === true) {
					continue;
				}

				$label = $this->labelOf(type: $type, labelField: $source['labelField']);
				$listed[$typeId] = $this->entry(register: $source['register'], schema: $source['schema'], typeId: $typeId, label: $label);
			}
		}

		foreach ($this->normalise(hidden: (array)($portal['hiddenCaseTypes'] ?? [])) as $hidden) {
			$typeId = $hidden['typeId'];
			if (isset($listed[$typeId]) === false) {
				$listed[$typeId] = $this->entry(register: $hidden['register'], schema: $hidden['schema'], typeId: $typeId, label: $hidden['label']);
			}
		}

		$hiddenIds = $this->visibility->hiddenTypeIds(portal: $portal);
		foreach (array_keys($listed) as $typeId) {
			$listed[$typeId]['shown'] = (in_array((string)$typeId, $hiddenIds, true) === false);
		}

		return array_values($listed);
	}//end listFor()

	/**
	 * Write the portal's hidden list; nothing else on the portal changes.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param array<int, mixed> $hidden The case types to hide.
	 *
	 * @return array<string, mixed>|null The saved portal, or null when the write failed.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-nothing-is-deleted-by-hiding-req-osc-003
	 */
	public function save(array $portal, array $hidden): ?array {
		$id = (string)($portal['id'] ?? $portal['uuid'] ?? ($portal['@self']['id'] ?? ''));

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'slug',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			id: $id,
			data: ['hiddenCaseTypes' => $this->normalise(hidden: $hidden)]
		);
	}//end save()

	/**
	 * The hidden list in its stored shape: one entry per case type id, with
	 * only the four schema fields.
	 *
	 * @param array<int|string, mixed> $hidden The entries as given.
	 *
	 * @return array<int, array{register: string, schema: string, typeId: string, label: string}>
	 */
	private function normalise(array $hidden): array {
		$out = [];
		foreach ($hidden as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$typeId = trim((string)($entry['typeId'] ?? ''));
			if ($typeId === '' || isset($out[$typeId]) === true) {
				continue;
			}

			$out[$typeId] = [
				'register' => (string)($entry['register'] ?? ''),
				'schema' => (string)($entry['schema'] ?? ''),
				'typeId' => $typeId,
				'label' => (string)($entry['label'] ?? ''),
			];
		}

		return array_values($out);
	}//end normalise()

	/**
	 * Where the case apps say their case types live: `caseTypeSource` on a
	 * `cases` collection, for every audience any app serves.
	 *
	 * @return array<int, array{register: string, schema: string, labelField: string}>
	 */
	private function declaredSources(): array {
		$sources = [];
		foreach ($this->registry->servedAudiences() as $audience) {
			$aggregate = $this->registry->aggregateFor(
				subject: ['audience' => $audience, 'trust' => 'high', 'subjectRef' => '', 'organisation' => '']
			);
			foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
				if (is_array($contribution) === false) {
					continue;
				}

				foreach ((array)($contribution['collections'] ?? []) as $collection) {
					$source = $this->sourceOf(collection: $collection);
					if ($source !== null) {
						$sources[$source['register'] . '/' . $source['schema']] = $source;
					}
				}
			}
		}

		return array_values($sources);
	}//end declaredSources()

	/**
	 * A `cases` collection's declared case type source, or null.
	 *
	 * @param mixed $collection The collection as declared.
	 *
	 * @return array{register: string, schema: string, labelField: string}|null
	 */
	private function sourceOf(mixed $collection): ?array {
		if (is_array($collection) === false || ($collection['kind'] ?? '') !== 'cases' || is_array($collection['caseTypeSource'] ?? null) === false) {
			return null;
		}

		$source = $collection['caseTypeSource'];
		$register = (string)($source['register'] ?? '');
		$schema = (string)($source['schema'] ?? '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['register' => $register, 'schema' => $schema, 'labelField' => (string)($source['labelField'] ?? '')];
	}//end sourceOf()

	/**
	 * One listed case type, shown until the hidden list says otherwise.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string $typeId The case type id.
	 * @param string $label The name; the id when there is none.
	 *
	 * @return array{register: string, schema: string, typeId: string, label: string, shown: bool}
	 */
	private function entry(string $register, string $schema, string $typeId, string $label): array {
		if ($label === '') {
			$label = $typeId;
		}

		return ['register' => $register, 'schema' => $schema, 'typeId' => $typeId, 'label' => $label, 'shown' => true];
	}//end entry()

	/**
	 * A case type object's id.
	 *
	 * @param array<string, mixed> $type The case type.
	 *
	 * @return string
	 */
	private function idOf(array $type): string {
		return (string)($type['id'] ?? $type['uuid'] ?? ($type['@self']['id'] ?? ''));
	}//end idOf()

	/**
	 * A case type object's name: the declared label field first.
	 *
	 * @param array<string, mixed> $type The case type.
	 * @param string $labelField The field the case app named, or ''.
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
