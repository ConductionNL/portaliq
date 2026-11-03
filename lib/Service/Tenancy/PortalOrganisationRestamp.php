<?php

/**
 * Portaliq PortalOrganisationRestamp
 *
 * Gives existing rows of portaliq's organisation-scoped schemas the
 * OpenRegister organisation their data names. Portaliq stamps a row's tenant
 * as the organisation slug in the data property `organisation`; rows it wrote
 * as the system carry the default organisation in `@self.organisation`, so a
 * read that acts for the portal's organisation would not see them.
 *
 * `report()` only reads and counts. `apply()` writes `@self.organisation` for
 * each row whose slug resolves and whose stored organisation differs. A row
 * without a slug, or whose slug names no organisation, is listed and left as
 * it is: nothing is guessed.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Service\ObjectServiceLookup;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Restamps `@self.organisation` of portaliq rows from their organisation slug.
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
 */
class PortalOrganisationRestamp {
	use ObjectServiceLookup;

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * OpenRegister's object mapper, which writes an entity as it is.
	 */
	private const OBJECT_MAPPER = 'OCA\\OpenRegister\\Db\\MagicMapper';

	/**
	 * The register the rows live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * Rows per page.
	 */
	private const PAGE = 500;

	/**
	 * Pages per schema before the run stops listing it and says so.
	 */
	private const MAX_PAGES = 200;

	/**
	 * Organisation slug to uuid, '' when it does not resolve.
	 *
	 * @var array<string, string>
	 */
	private array $uuids = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface              $container     Resolves OpenRegister's object service and mapper.
	 * @param PortalOrganisationConfigService $organisations Maps an organisation slug to its uuid.
	 * @param LoggerInterface                 $logger        The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalOrganisationConfigService $organisations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Count what a restamp would do, and write nothing.
	 *
	 * @return array<string, mixed> See RestampTally::report().
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function report(): array {
		return $this->walk(tally: new RestampTally(apply: false));
	}//end report()

	/**
	 * Restamp every row whose organisation slug resolves and differs.
	 *
	 * @return array<string, mixed> See RestampTally::report().
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function apply(): array {
		return $this->walk(tally: new RestampTally(apply: true));
	}//end apply()

	/**
	 * List every organisation-scoped schema, then settle each row.
	 *
	 * A schema is listed completely before any of its rows is written, so a
	 * write cannot move a row between pages.
	 *
	 * @param RestampTally $tally The run.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function walk(RestampTally $tally): array {
		$objectService = $this->objectService();
		if ($objectService === null || $this->mapper() === null) {
			throw new RuntimeException('OpenRegister is not available, so nothing was read or written.');
		}

		foreach (SchemaTenancy::MAP as $schema => $declaration) {
			if ($declaration['scope'] !== SchemaTenancy::ORGANISATION) {
				continue;
			}

			try {
				$rows = $this->rowsOf(objectService: $objectService, schema: $schema);
			} catch (Throwable $e) {
				$tally->unreadable(schema: $schema, reason: $e->getMessage());
				continue;
			}

			if (count($rows) >= (self::PAGE * self::MAX_PAGES)) {
				$tally->unreadable(schema: $schema, reason: 'More than '.(self::PAGE * self::MAX_PAGES).' rows: only the first were handled.');
			}

			foreach ($rows as $row) {
				$this->settle(row: ['schema' => $schema] + $this->facts(row: $row), tally: $tally);
			}
		}//end foreach

		return $tally->report();
	}//end walk()

	/**
	 * Every row of a schema, page by page.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $schema        The schema slug.
	 *
	 * @return array<int, mixed>
	 */
	private function rowsOf(object $objectService, string $schema): array {
		$rows = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$found = $objectService->findAll(
				config: ['limit' => self::PAGE, 'offset' => ($page * self::PAGE)],
				_rbac: false,
				_multitenancy: false
			);
			$found = array_values((array)$found);
			array_push($rows, ...$found);
			if (count($found) < self::PAGE) {
				break;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The row's uuid, data organisation and stored organisation.
	 *
	 * @param mixed $row A listed row: an entity or an array.
	 *
	 * @return array{uuid: string, slug: mixed, stored: string}
	 */
	private function facts(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		$row  = (array)$row;
		$self = (array)($row['@self'] ?? []);

		return [
			'uuid'   => (string)($self['id'] ?? $self['uuid'] ?? $row['id'] ?? ''),
			'slug'   => ($row['organisation'] ?? null),
			'stored' => (string)($self['organisation'] ?? ''),
		];
	}//end facts()

	/**
	 * Count, restamp or list one row.
	 *
	 * @param array{schema: string, uuid: string, slug: mixed, stored: string} $row   The row's facts.
	 * @param RestampTally                                                     $tally The run.
	 *
	 * @return void
	 */
	private function settle(array $row, RestampTally $tally): void {
		$slug = $row['slug'];
		$uuid = '';
		if (is_string($slug) === true && $slug !== '' && $row['uuid'] !== '') {
			$uuid = $this->uuidFor(slug: $slug);
		}

		if ($uuid === '') {
			$tally->unresolved(row: ['schema' => $row['schema'], 'uuid' => $row['uuid'], 'organisation' => $this->shown(value: $slug)]);
			return;
		}

		$organisation = ['slug' => $slug, 'uuid' => $uuid];
		if ($row['stored'] === $uuid) {
			$tally->count(organisation: $organisation, outcome: RestampTally::ALREADY);
			return;
		}

		if ($tally->apply === false) {
			$tally->count(organisation: $organisation, outcome: RestampTally::TO_RESTAMP);
			return;
		}

		$tally->count(organisation: $organisation, outcome: $this->restamp(row: $row, uuid: $uuid));
	}//end settle()

	/**
	 * Write the organisation on one row.
	 *
	 * @param array{schema: string, uuid: string, slug: mixed, stored: string} $row  The row's facts.
	 * @param string                                                           $uuid The organisation uuid.
	 *
	 * @return string RestampTally::RESTAMPED or RestampTally::FAILED.
	 */
	private function restamp(array $row, string $uuid): string {
		try {
			$entity = $this->objectService()?->find(
				id: $row['uuid'],
				register: self::REGISTER,
				schema: $row['schema'],
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
			if (is_object($entity) === false) {
				throw new RuntimeException('the row was not found again');
			}

			$entity->setOrganisation($uuid);
			$this->mapper()?->update(entity: $entity);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: a row could not be given its organisation',
				['schema' => $row['schema'], 'uuid' => $row['uuid'], 'reason' => $e->getMessage()]
			);
			return RestampTally::FAILED;
		}

		return RestampTally::RESTAMPED;
	}//end restamp()

	/**
	 * The uuid of the organisation with this slug, or ''.
	 *
	 * @param string $slug The slug as the row holds it.
	 *
	 * @return string
	 */
	private function uuidFor(string $slug): string {
		if (isset($this->uuids[$slug]) === false) {
			$this->uuids[$slug] = (string)($this->organisations->findOrganisationBySlug(slug: $slug)['uuid'] ?? '');
		}

		return $this->uuids[$slug];
	}//end uuidFor()

	/**
	 * A row's organisation value as the report shows it.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function shown(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if ($value === null) {
			return '(none)';
		}

		return '('.get_debug_type($value).')';
	}//end shown()

	/**
	 * OpenRegister's object mapper, or null.
	 *
	 * @return object|null
	 */
	private function mapper(): ?object {
		try {
			$mapper = $this->container->get(self::OBJECT_MAPPER);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($mapper) === false) {
			return null;
		}

		return $mapper;
	}//end mapper()
}//end class
