<?php
/**
 * Portaliq repair step: give every access request the sort rank of its state
 *
 * Until change access-requests-open-first an access request carried only its
 * `state`, which OpenRegister sorts alphabetically. This step writes the
 * request's `stateRank` (0 pending, 1 granted, 2 refused) where it is missing
 * or does not match the state, so the list sorts open requests first. A
 * request whose rank already matches stays as it is, so the step is safe to
 * run on every upgrade.
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
 * @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes `stateRank` on access requests whose rank is missing or stale.
 *
 * @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first
 */
class BackfillAccessRequestStateRank implements IRepairStep {
	/**
	 * The access request schema.
	 */
	private const SCHEMA = 'portalAccessRequest';

	/**
	 * The register it lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Items read per page.
	 */
	private const PAGE = 100;

	/**
	 * A bound on the pages read.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service, which may be absent.
	 * @param PortalRegisterContext $context Points the object service at the access request schema.
	 * @param LoggerInterface $logger Names a request that could not be ranked.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalRegisterContext $context,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first
	 */
	public function getName(): string {
		return 'Give access requests the sort rank of their state';
	}//end getName()

	/**
	 * Rank every request whose rank is missing or stale, a page at a time.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first
	 */
	public function run(IOutput $output): void {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return;
		}

		$stamped = 0;
		$offset  = 0;
		$pages   = 0;
		do {
			$pages++;
			$page    = $this->page(objectService: $objectService, offset: $offset);
			$read    = count($page);
			$offset += $read;
			foreach ($page as $row) {
				if ($this->stamp(objectService: $objectService, row: $row) === true) {
					$stamped++;
				}
			}
		} while ($read === self::PAGE && $pages < self::MAX_PAGES);

		$output->info('BackfillAccessRequestStateRank: ranked ' . $stamped . ' access requests.');
	}//end run()

	/**
	 * Rank one request when its rank is missing or does not match its state.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $row           The stored request.
	 *
	 * @return bool True when the request was written.
	 */
	private function stamp(object $objectService, array $row): bool {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$uuid  = (string)($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');
		$state = (string)($row['state'] ?? '');
		$rank  = PortalAccessRequestService::STATE_RANKS[$state] ?? null;
		if ($uuid === '' || $rank === null || ($row['stateRank'] ?? null) === $rank) {
			return false;
		}

		try {
			unset($row['@self']);
			$row['stateRank'] = $rank;
			$objectService->saveObject(object: $row, register: self::REGISTER, schema: self::SCHEMA, uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: an access request could not be ranked', ['uuid' => $uuid, 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end stamp()

	/**
	 * One page of access requests, or none when the schema is absent.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param int $offset Items to skip.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function page(object $objectService, int $offset): array {
		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::SCHEMA) === false) {
				return [];
			}

			$rows = $objectService->findAll(config: ['limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: no access requests to rank', ['reason' => $e->getMessage()]);

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
	 * OpenRegister's object service, or null when it is not installed.
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
