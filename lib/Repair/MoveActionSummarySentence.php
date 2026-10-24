<?php

/**
 * Portaliq repair step: move a stored action's answer sentence to `answerSummary`
 *
 * Decision 127 (9 Oct 2026) made an action's `summary` the start tile's plain
 * sentence of at most 200 characters. Before that, `summary` held the answer
 * sentence object (`{label, template, phrases}`, action-summary-sentence). A
 * data-provisioned contribution (a `portalPage` record) stored in that time
 * still carries the object; this step moves it to `answerSummary`, where the
 * form reads it now. A string summary, a record without the old shape and a
 * second run write nothing, so the step is safe on every upgrade.
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
 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
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
 * Moves the old object `summary` of stored actions to `answerSummary`.
 *
 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
 */
class MoveActionSummarySentence implements IRepairStep {
	/**
	 * The schema of a data-provisioned contribution.
	 */
	private const SCHEMA = 'portalPage';

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
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container (OpenRegister is optional).
	 * @param PortalRegisterContext $context Points the object service at a portaliq schema.
	 * @param LoggerInterface $logger The logger.
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
	 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
	 */
	public function getName(): string {
		return 'Move the answer sentence of stored portal actions to answerSummary';
	}//end getName()

	/**
	 * Move every old object summary.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
	 */
	public function run(IOutput $output): void {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return;
		}

		$moved  = 0;
		$offset = 0;
		$pages  = 0;
		do {
			$pages++;
			$page    = $this->page(objectService: $objectService, offset: $offset);
			$read    = count($page);
			$offset += $read;
			foreach ($page as $row) {
				if ($this->move(objectService: $objectService, row: $row) === true) {
					$moved++;
				}
			}
		} while ($read === self::PAGE && $pages < self::MAX_PAGES);

		$output->info('MoveActionSummarySentence: moved the answer sentence on ' . $moved . ' portal pages.');
	}//end run()

	/**
	 * The record's actions with every old object summary moved, or null when
	 * none had one.
	 *
	 * @param array<string, mixed> $row The stored record.
	 *
	 * @return array<int, mixed>|null
	 *
	 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
	 */
	public function movedActions(array $row): ?array {
		if (is_array(($row['actions'] ?? null)) === false) {
			return null;
		}

		$changed = false;
		$actions = [];
		foreach ($row['actions'] as $action) {
			if (is_array($action) === true && is_array(($action['summary'] ?? null)) === true) {
				if (array_key_exists('answerSummary', $action) === false) {
					$action['answerSummary'] = $action['summary'];
				}

				unset($action['summary']);
				$changed = true;
			}

			$actions[] = $action;
		}

		if ($changed === false) {
			return null;
		}

		return $actions;
	}//end movedActions()

	/**
	 * Save one record with its sentences moved.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param array<string, mixed> $row The stored record.
	 *
	 * @return bool Whether the record was written.
	 */
	private function move(object $objectService, array $row): bool {
		$actions = $this->movedActions(row: $row);
		$self    = [];
		if (is_array(($row['@self'] ?? null)) === true) {
			$self = $row['@self'];
		}

		$uuid = (string)($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');
		if ($actions === null || $uuid === '') {
			return false;
		}

		try {
			unset($row['@self']);
			$row['actions'] = $actions;
			$objectService->saveObject(object: $row, register: self::REGISTER, schema: self::SCHEMA, uuid: $uuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a portal page could not have its action sentence moved', ['uuid' => $uuid, 'reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}//end move()

	/**
	 * One page of portalPage records.
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
			$this->logger->info('Portaliq: no portal pages to repair', ['reason' => $e->getMessage()]);

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
