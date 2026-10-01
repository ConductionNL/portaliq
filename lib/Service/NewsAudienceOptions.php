<?php

/**
 * News Audience Options
 *
 * The schools and groups a staff member can pick as the audience of a news
 * item on the News screen (staff-news-screen). A news item's target names a
 * school or groups by the school app's own references, so the choices come
 * from the school app too, never from a list portaliq keeps.
 *
 * A contribution that declares `guardianAudience` (news-audience-from-the-
 * school-app) already says which collection holds the guardian's children,
 * which child field names the school, and which collection and field name
 * the groups. This class turns that into option lists:
 *
 * - groups: `guardianAudience.groups.options` (`{register?, schema}`) when
 *   declared, else the `$ref` the groups field carries in its own schema
 *   (learniq's `enrolment.cohortId` points at `Cohort`);
 * - schools: `guardianAudience.schoolOptions` (`{register?, schema}`) when
 *   declared, else the `$ref` of the school field on the children's schema.
 *
 * The option objects are read as the signed-in staff member, with
 * OpenRegister's own access rules on, so a staff member is only offered the
 * schools and groups they may read.
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
 * @spec openspec/changes/staff-news-screen/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the school and group choices for a news item's audience.
 *
 * @spec openspec/changes/staff-news-screen/tasks.md#T2
 */
class NewsAudienceOptions {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The audience whose contributions declare `guardianAudience`.
	 */
	private const AUDIENCE = 'parent';

	/**
	 * The most options one list offers.
	 */
	private const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The contributions for the parent audience.
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The schools and groups on offer, each `{id, label}`, sorted by label.
	 * Empty lists when no school app declares where they come from; the
	 * screen then asks for a reference instead.
	 *
	 * @return array{schools: array<int, array{id: string, label: string}>, groups: array<int, array{id: string, label: string}>}
	 *
	 * @spec openspec/changes/staff-news-screen/tasks.md#T2
	 */
	public function options(): array {
		$options = ['schools' => [], 'groups' => []];
		foreach ($this->declaringContributions() as $contribution) {
			$declaration = $contribution['guardianAudience'];
			$groups = (array)($declaration['groups'] ?? []);

			$groupSource = $this->source(
				contribution: $contribution,
				explicit: ($groups['options'] ?? null),
				collectionId: (string)($groups['collection'] ?? ''),
				field: (string)($groups['field'] ?? '')
			);
			$schoolSource = $this->source(
				contribution: $contribution,
				explicit: ($declaration['schoolOptions'] ?? null),
				collectionId: (string)($declaration['children'] ?? ''),
				field: (string)($declaration['schoolField'] ?? '')
			);

			$options['groups'] = array_merge($options['groups'], $this->listOptions(source: $groupSource));
			$options['schools'] = array_merge($options['schools'], $this->listOptions(source: $schoolSource));
		}

		return ['schools' => $this->sorted(options: $options['schools']), 'groups' => $this->sorted(options: $options['groups'])];
	}//end options()

	/**
	 * The parent-audience contributions that declare a `guardianAudience`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function declaringContributions(): array {
		$subject = ['subjectRef' => '', 'audience' => self::AUDIENCE, 'organisation' => '', 'trust' => 'substantial'];
		try {
			$aggregate = $this->registry->aggregateFor(subject: $subject);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: news audience options lookup failed', ['reason' => $e->getMessage()]);
			return [];
		}

		return array_values(
			array_filter(
				(array)($aggregate['contributions'] ?? []),
				static fn ($contribution): bool => is_array($contribution['guardianAudience'] ?? null) === true
			)
		);
	}//end declaringContributions()

	/**
	 * Where one list's options live: the explicit `{register?, schema}`, else
	 * the `$ref` the field carries on the named collection's schema.
	 *
	 * @param array<string, mixed> $contribution The declaring contribution.
	 * @param mixed $explicit The declared `{register?, schema}`, if any.
	 * @param string $collectionId The collection the field belongs to.
	 * @param string $field The field naming the school or group.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function source(array $contribution, mixed $explicit, string $collectionId, string $field): ?array {
		$collection = $this->collection(contribution: $contribution, collectionId: $collectionId);
		$register = (string)($collection['register'] ?? '');

		if (is_array($explicit) === true && is_string($explicit['schema'] ?? null) === true && $explicit['schema'] !== '') {
			return $this->explicitSource(explicit: $explicit, register: $register);
		}

		if ($register === '' || is_string($collection['schema'] ?? null) === false) {
			return null;
		}

		$target = $this->referencedSchema(register: $register, schema: $collection['schema'], field: $field);
		if ($target === null) {
			return null;
		}

		return ['register' => $register, 'schema' => $target];
	}//end source()

	/**
	 * A declared `{register?, schema}` source; the register defaults to the
	 * register of the collection the field belongs to.
	 *
	 * @param array<string, mixed> $explicit The declared source, with a schema.
	 * @param string $register The collection's register.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function explicitSource(array $explicit, string $register): ?array {
		$explicitRegister = ($explicit['register'] ?? $register);
		if (is_string($explicitRegister) === false || $explicitRegister === '') {
			return null;
		}

		return ['register' => $explicitRegister, 'schema' => (string)$explicit['schema']];
	}//end explicitSource()

	/**
	 * One declared collection by id.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param string $collectionId The collection id.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(array $contribution, string $collectionId): array {
		foreach ((array)($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === true && ($collection['id'] ?? null) === $collectionId && $collectionId !== '') {
				return $collection;
			}
		}

		return [];
	}//end collection()

	/**
	 * The schema slug a property's `$ref` names, within the same register.
	 * `Cohort` and `LearnerProfile` are written as component names in a
	 * register file; their slugs are `cohort` and `learner-profile`.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug holding the field.
	 * @param string $field The field.
	 *
	 * @return string|null
	 */
	private function referencedSchema(string $register, string $schema, string $field): ?string {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		// An OpenRegister without getCurrentSchemaEntity() throws an Error,
		// caught below like any other failed lookup.
		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
			$entity = $objectService->getCurrentSchemaEntity();
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: news audience schema lookup failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($entity) === false || method_exists($entity, 'getProperties') === false) {
			return null;
		}

		$properties = $entity->getProperties();
		$ref = ($properties[$field]['$ref'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		$name = basename(str_replace('#/components/schemas/', '', $ref));
		return strtolower((string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name));
	}//end referencedSchema()

	/**
	 * The `{id, label}` options of one source, read as the signed-in user.
	 *
	 * @param array{register: string, schema: string}|null $source The source.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function listOptions(?array $source): array {
		if ($source === null) {
			return [];
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: $source['register']);
			$objectService->setSchema(schema: $source['schema']);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => self::LIMIT, 'offset' => 0]);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: news audience options read failed', ['schema' => $source['schema'], 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$options = [];
		foreach ($rows as $row) {
			$option = $this->option(row: $row);
			if ($option !== null) {
				$options[] = $option;
			}
		}

		return $options;
	}//end listOptions()

	/**
	 * One row as `{id, label}`, or null without an id.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array{id: string, label: string}|null
	 */
	private function option(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		$id = ($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? null)));
		if (is_string($id) === false || $id === '') {
			return null;
		}

		foreach (['name', 'title', 'label'] as $key) {
			if (is_string($row[$key] ?? null) === true && $row[$key] !== '') {
				return ['id' => $id, 'label' => $row[$key]];
			}
		}

		return ['id' => $id, 'label' => $id];
	}//end option()

	/**
	 * Distinct by id, sorted by label (natural order, so "Groep 10" follows
	 * "Groep 9").
	 *
	 * @param array<int, array{id: string, label: string}> $options The options.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function sorted(array $options): array {
		$byId = [];
		foreach ($options as $option) {
			$byId[$option['id']] = $option;
		}

		$list = array_values($byId);
		usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

		return $list;
	}//end sorted()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
