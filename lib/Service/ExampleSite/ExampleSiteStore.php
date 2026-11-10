<?php

/**
 * Portaliq Example Site Store
 *
 * The three things an example site install does to the portaliq register:
 * read the rows of one schema, write one row, delete one row. Kept apart from
 * the installer so the installer's rules can be tested without OpenRegister.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleSite
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleSite;

use OCA\OpenRegister\Service\Object\ObjectDeletion;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\Portaliq\Service\ObjectRowNormaliser;
use OCA\Portaliq\Service\ObjectServiceLookup;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\RowIdOf;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, writes and deletes rows of the portaliq register for an administrator.
 *
 * Every call runs without OpenRegister's access rules, like the repair step
 * that seeds the first portal: the caller is `occ`, so there is no user whose
 * rights could be asked.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
 */
class ExampleSiteStore {
	use ObjectServiceLookup;
	use ObjectRowNormaliser;
	use RowIdOf;

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
	 * Constructor.
	 *
	 * @param ContainerInterface    $container Hands out OpenRegister's ObjectService.
	 * @param PortalRegisterContext $context   Points that service at a portaliq schema.
	 * @param PortalObjectWriter    $writer    Writes a row the way the repair step does.
	 * @param LoggerInterface       $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContext $context,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister can be reached at all.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function available(): bool {
		return $this->objectService() !== null;
	}//end available()

	/**
	 * The rows of one schema that match the filters, as plain arrays.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Property filters; empty reads every row.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	public function find(string $schema, array $filters): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return [];
			}

			$rows = $objectService->findAll(
				config: ['filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: example site read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$plain = [];
		foreach ($rows as $row) {
			$row = $this->normalise(row: $row);
			if ($row !== null) {
				$plain[] = $row;
			}
		}

		return $plain;
	}//end find()

	/**
	 * Write one row and return its id, or null when the write failed.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function create(string $schema, array $data): ?string {
		$saved = $this->writer->createAnonymousObject(register: PortalRegisterContext::REGISTER, schema: $schema, data: $data);
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
	 * Delete one row by its id.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The row's id.
	 *
	 * @return bool True when OpenRegister deleted it.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function delete(string $schema, string $id): bool {
		$objectService = $this->objectService();
		if ($objectService === null || $id === '') {
			return false;
		}

		try {
			$deleted = $objectService->deleteObject(
				deletion: new ObjectDeletion(uuid: $id, target: new ObjectFindTarget(register: PortalRegisterContext::REGISTER, schema: $schema)),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: example site delete failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return false;
		}

		return $deleted !== false;
	}//end delete()
}//end class
