<?php

/**
 * Portaliq repair step: a form binding names its destination
 *
 * Decision 179 (10 Oct 2026): a form submits into its destination object, so a
 * `portalFormBinding` stores `destination { register, schema }`. Before that
 * it stored `caseRegister` and `caseSchema` for a queue job to read, and
 * `deliverTo` for the Woo hand-over. This step moves the pair into
 * `destination` and drops the three old fields. A binding that already has a
 * destination, and a second run, write nothing.
 *
 * A binding with `deliverTo: wooRequest` and no case pair named no register:
 * opencatalogi chose. Nothing is guessed for it. It is counted and logged by
 * id, and its form refuses to send until an administrator names the
 * destination; publishing it again is refused until then.
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
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves `caseRegister`/`caseSchema` into `destination` on every form binding.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#1.1
 */
class MoveBindingDestination implements IRepairStep {
	/**
	 * The binding schema.
	 */
	private const SCHEMA = 'portalFormBinding';

	/**
	 * The register.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * OpenRegister's object service, resolved lazily.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Records read per page.
	 */
	private const PAGE = 100;

	/**
	 * The most pages read in one run.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * The fields the destination replaces.
	 */
	private const OLD_FIELDS = ['caseRegister', 'caseSchema', 'deliverTo'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container The container (OpenRegister is optional).
	 * @param PortalRegisterContext $context   Points the object service at a portaliq schema.
	 * @param LoggerInterface       $logger    The logger.
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
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#1.1
	 */
	public function getName(): string {
		return 'Move the case register and schema of form bindings into their destination';
	}//end getName()

	/**
	 * Move every binding that still names its case pair the old way.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#1.1
	 */
	public function run(IOutput $output): void {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return;
		}

		$moved   = 0;
		$without = [];
		$offset  = 0;
		$pages   = 0;
		do {
			$pages++;
			$page    = $this->page(objectService: $objectService, offset: $offset);
			$read    = count($page);
			$offset += $read;
			foreach ($page as $row) {
				$outcome = $this->repair(objectService: $objectService, row: $row);
				$moved  += (int)($outcome === 'moved');
				if ($outcome === 'without') {
					$without[] = $this->uuidOf(row: $row);
				}
			}
		} while ($read === self::PAGE && $pages < self::MAX_PAGES);

		$output->info('MoveBindingDestination: moved the destination of ' . $moved . ' form bindings.');
		if ($without !== []) {
			$this->logger->warning('Portaliq: form bindings without a destination; name one before the form can send', ['bindings' => $without]);
			$output->warning('MoveBindingDestination: ' . count($without) . ' form bindings name no destination: ' . implode(', ', $without));
		}
	}//end run()

	/**
	 * The binding with its destination moved, or null when there is nothing to move.
	 *
	 * @param array<string, mixed> $row The stored binding.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#1.1
	 */
	public function movedBinding(array $row): ?array {
		$destination = (array)($row['destination'] ?? []);
		if (trim((string)($destination['register'] ?? '')) !== '' && trim((string)($destination['schema'] ?? '')) !== '') {
			return null;
		}

		$register = trim((string)($row['caseRegister'] ?? ''));
		$schema   = trim((string)($row['caseSchema'] ?? ''));
		if ($register === '' || $schema === '') {
			return null;
		}

		$row['destination'] = array_merge($destination, ['register' => $register, 'schema' => $schema]);
		foreach (self::OLD_FIELDS as $old) {
			unset($row[$old]);
		}

		unset($row['@self']);

		return $row;
	}//end movedBinding()

	/**
	 * Repair one binding.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $row           The stored binding.
	 *
	 * @return string `moved`, `without` (no destination to move, none set) or `kept`.
	 */
	private function repair(object $objectService, array $row): string {
		$binding = $this->movedBinding(row: $row);
		if ($binding === null) {
			if ($this->needsDestination(row: $row) === true) {
				return 'without';
			}

			return 'kept';
		}

		if ($this->save(objectService: $objectService, row: $binding, uuid: $this->uuidOf(row: $row)) === true) {
			return 'moved';
		}

		return 'kept';
	}//end repair()

	/**
	 * Whether a binding that was not moved still has no destination: a hosted
	 * binding with neither a destination nor a case pair.
	 *
	 * @param array<string, mixed> $row The stored binding.
	 *
	 * @return bool
	 */
	private function needsDestination(array $row): bool {
		if ((string)($row['intakeKind'] ?? '') === 'external') {
			return false;
		}

		$destination = (array)($row['destination'] ?? []);

		return trim((string)($destination['register'] ?? '')) === '' && trim((string)($row['caseRegister'] ?? '')) === '';
	}//end needsDestination()

	/**
	 * The binding's uuid, flat or in `@self`.
	 *
	 * @param array<string, mixed> $row The stored binding.
	 *
	 * @return string
	 */
	private function uuidOf(array $row): string {
		$self = (array)($row['@self'] ?? []);

		return (string)($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');
	}//end uuidOf()

	/**
	 * Store a moved binding.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $row           The moved binding.
	 * @param string               $uuid          Its uuid.
	 *
	 * @return bool
	 */
	private function save(object $objectService, array $row, string $uuid): bool {
		if ($uuid === '') {
			return false;
		}

		try {
			$objectService->saveObject(object: $row, register: self::REGISTER, schema: self::SCHEMA, uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a form binding could not have its destination moved', ['uuid' => $uuid, 'reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}//end save()

	/**
	 * One page of form bindings.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param int $offset The offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(object $objectService, int $offset): array {
		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: self::SCHEMA) === false) {
				return [];
			}

			$rows = $objectService->findAll(config: ['limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: no form bindings to repair', ['reason' => $e->getMessage()]);

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
