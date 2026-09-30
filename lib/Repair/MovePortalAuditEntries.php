<?php

/**
 * Portaliq repair step: move the portal's old proof records into OpenRegister's audit trail
 *
 * Until change consume-or-audit-trail-proof-records the portal kept its proof
 * records (login, logout, refresh, create, update, forward, download,
 * complete) as `portalAuditEntry` objects in its own register. They now live
 * in OpenRegister's hash-chained audit trail. This step moves every old
 * record there, keeping its uuid and its time, and deletes the old object
 * only after its row is in the trail. It is safe to run again: a record whose
 * uuid is already in the trail is not written twice, only its old object is
 * removed. On an install that never had the schema it does nothing.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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
 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use DateTime;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves `portalAuditEntry` objects into OpenRegister's audit trail.
 *
 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T02
 */
class MovePortalAuditEntries implements IRepairStep {
	/**
	 * The retired schema the old records live in.
	 */
	public const OLD_SCHEMA = 'portalAuditEntry';

	/**
	 * The register the old schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Records read per page.
	 */
	private const PAGE = 100;

	/**
	 * A bound on the pages read, so a delete OpenRegister reports but does not
	 * carry out can never keep the upgrade running.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service, which may be absent.
	 * @param PortalRegisterContext $context Points the object service at the old schema.
	 * @param AuditTrailService $trail Writes the rows into OpenRegister's audit trail.
	 * @param LoggerInterface $logger Names a record that could not be moved.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContext $context,
		private readonly AuditTrailService $trail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Move the portal proof records into the OpenRegister audit trail';
	}//end getName()

	/**
	 * Move every old record, a page at a time.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T02
	 */
	public function run(IOutput $output): void {
		$moved = 0;
		$kept = 0;
		$pages = 0;
		do {
			$pages++;
			$page = $this->page(offset: $kept);
			foreach ($page as $row) {
				if ($this->move(row: $row) === true) {
					$moved++;
					continue;
				}

				$kept++;
			}
		} while (count($page) === self::PAGE && $pages < self::MAX_PAGES);

		$output->info('MovePortalAuditEntries: moved ' . $moved . ', could not move ' . $kept . '.');
	}//end run()

	/**
	 * Move one record: into the trail when it is not there yet, then delete the old object.
	 *
	 * @param array<string, mixed> $row The old record.
	 *
	 * @return bool True when the old object is gone and its row is in the trail.
	 */
	private function move(array $row): bool {
		$uuid = (string)($row['@self']['uuid'] ?? $row['uuid'] ?? $row['id'] ?? '');
		if ($uuid === '') {
			return false;
		}

		try {
			if ($this->trail->has(uuid: $uuid) === false) {
				$created = new DateTime((string)($row['timestamp'] ?? $row['@self']['created'] ?? 'now'));
				$this->trail->append(fact: $row, uuid: $uuid, created: $created);
			}

			$this->objectService()->deleteObject(uuid: $uuid, register: self::REGISTER, schema: self::OLD_SCHEMA, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a proof record could not be moved into the audit trail', ['uuid' => $uuid, 'reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}//end move()

	/**
	 * One page of old records, or none when OpenRegister or the old schema is absent.
	 *
	 * @param int $offset Records to skip (the ones that could not be moved).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function page(int $offset): array {
		try {
			$objectService = $this->objectService();
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::OLD_SCHEMA) === false) {
				return [];
			}

			$rows = $objectService->findAll(config: ['limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: no old proof records to move', ['reason' => $e->getMessage()]);

			return [];
		}

		$out = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end page()

	/**
	 * OpenRegister's object service.
	 *
	 * @return object
	 *
	 * @throws Throwable When OpenRegister is not installed.
	 */
	private function objectService(): object {
		$service = $this->container->get(self::OBJECT_SERVICE);
		if (is_object($service) === false) {
			throw new \RuntimeException('OpenRegister object service unavailable');
		}

		return $service;
	}//end objectService()
}//end class
