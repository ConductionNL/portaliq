<?php

/**
 * Portaliq Example Resident Store
 *
 * What an example resident's install does to OpenRegister: say whether a
 * register carries a schema, read rows, read one row, write one, change one
 * and delete one. Unlike the example site's store it names the register on
 * every call, because a resident's cases live in the register of the app that
 * handles them. Kept apart from the installer so the installer's rules can be
 * tested without OpenRegister.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleResident
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-install-must-prove-what-arrived
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use OCA\Portaliq\Service\PortalObjectWriter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, writes and deletes rows of any register for an administrator.
 *
 * Every call runs without OpenRegister's access rules, like the example
 * site's install: the caller is `occ`, so there is no user whose rights could
 * be asked.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-install-must-prove-what-arrived
 */
class ExampleResidentStore {
	/**
	 * OpenRegister's ObjectService, fetched by string id: OpenRegister is an
	 * optional sibling app, so its classes are never imported.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The most rows one read returns.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The most pages one read asks for, so a read always ends.
	 */
	private const MAX_PAGES = 20;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out OpenRegister's ObjectService.
	 * @param PortalObjectWriter $writer    Writes a row inside portaliq's write context.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister can be reached at all.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function available(): bool {
		return $this->objectService() !== null;
	}//end available()

	/**
	 * Whether this instance has the register, and the register carries the schema.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function offers(string $register, string $schema): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$objectService->setRegister(register: $register);
			$objectService->setSchema(schema: $schema);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end offers()

	/**
	 * The rows of one schema that match the filters, as plain arrays.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  Property filters; empty reads every row.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function find(string $register, string $schema, array $filters): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$plain = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$objectService->setRegister(register: $register);
				$objectService->setSchema(schema: $schema);
				$rows = (array)$objectService->findAll(
					config: ['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => ($page * self::PAGE_SIZE)],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: example resident read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
				return $plain;
			}

			foreach ($rows as $row) {
				$row = $this->plain(row: $row);
				if ($row !== null) {
					$plain[] = $row;
				}
			}

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $plain;
	}//end find()

	/**
	 * One row by its id, or null when it is not there.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id.
	 *
	 * @return array<string, mixed>|null The row, with `id` set.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	public function get(string $register, string $schema, string $id): ?array {
		$objectService = $this->objectService();
		if ($objectService === null || $id === '') {
			return null;
		}

		try {
			$row = $objectService->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		$row = $this->plain(row: $row);
		if ($row === null || $this->idOf(row: $row) !== $id) {
			return null;
		}

		return (['id' => $id] + $row);
	}//end get()

	/**
	 * Write one row and return its id, or null when the write failed.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $data     The row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function create(string $register, string $schema, array $data): ?string {
		$saved = $this->writer->createAnonymousObject(register: $register, schema: $schema, data: $data);
		if ($saved === null) {
			return null;
		}

		$id = $this->idOf(row: $saved);
		if ($id === '') {
			return null;
		}

		return $id;
	}//end create()

	/**
	 * Change the named keys of one row and leave the rest as it is.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param string               $id       The row's id.
	 * @param array<string, mixed> $changes  The keys to set.
	 *
	 * @return bool True when OpenRegister saved the row.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function update(string $register, string $schema, string $id, array $changes): bool {
		$objectService = $this->objectService();
		$existing      = $this->get(register: $register, schema: $schema, id: $id);
		if ($objectService === null || $existing === null) {
			return false;
		}

		$merged = array_merge($existing, $changes);
		unset($merged['@self'], $merged['id']);

		try {
			$objectService->saveObject(object: $merged, register: $register, schema: $schema, uuid: $id, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: example resident update failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end update()

	/**
	 * Delete one row by its id.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 * @param string $id       The row's id.
	 *
	 * @return bool True when OpenRegister deleted it.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function delete(string $register, string $schema, string $id): bool {
		$objectService = $this->objectService();
		if ($objectService === null || $id === '') {
			return false;
		}

		try {
			$deleted = $objectService->deleteObject(uuid: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: example resident delete failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return false;
		}

		return $deleted !== false;
	}//end delete()

	/**
	 * The id OpenRegister gave a row, wherever it put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when the row names none.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function idOf(array $row): string {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		foreach ([($self['id'] ?? null), ($self['uuid'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $id) {
			if (is_string($id) === true && $id !== '') {
				return $id;
			}
		}

		return '';
	}//end idOf()

	/**
	 * A row as a plain array, or null when it is not one.
	 *
	 * @param mixed $row What OpenRegister handed over.
	 *
	 * @return array<string, mixed>|null
	 */
	private function plain(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return null;
	}//end plain()

	/**
	 * OpenRegister's ObjectService, or null when it is not installed.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
