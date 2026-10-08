<?php

/**
 * Portaliq Plan End Reminder Service
 *
 * Tells everyone in a plan, once per end date, that the plan is nearly over
 * and still has open actions.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\PagedObjectReads;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs from the daily job. Every running plan 14 days or less from its end date
 * with open actions and no reminder for that date sends one portal message to
 * each participant, then records it on the plan; changing the end date clears
 * the record, so a new date earns a new reminder.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */
class PlanEndReminderService {
	use PagedObjectReads;

	public const RULE_KEY = 'plan-ending';

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister's ObjectService.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send every plan reminder due today.
	 *
	 * @param string|null $today Today, `Y-m-d`; defaults to the clock in Amsterdam.
	 *
	 * @return int How many plans were reminded.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
	 */
	public function remindDue(?string $today = null): int {
		$today ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$plans = $this->readEveryPage(
				objectService: $objectService,
				register: self::REGISTER,
				schema: 'portalPlan',
				filters: ['status' => 'running']
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: plan reminders not read', ['reason' => $e->getMessage()]);
			return 0;
		}

		$sent = 0;
		if (is_array($plans) === false) {
			return 0;
		}

		foreach ($plans as $row) {
			$plan = $this->asArray(row: $row);
			$id   = $this->idOf(row: $plan);
			if ($id === '' || PlanRules::needsAction(plan: $plan, openActions: 1, today: $today) === false) {
				continue;
			}

			$open = $this->openActions(objectService: $objectService, planId: $id);
			if ($open === 0 || PlanRules::reminderDue(plan: $plan, openActions: $open, today: $today) === false) {
				continue;
			}

			if ($this->remind(objectService: $objectService, plan: $plan, id: $id, open: $open, today: $today) === true) {
				$sent++;
			}
		}

		return $sent;
	}//end remindDue()

	/**
	 * How many of a plan's actions are not done.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $planId The plan.
	 *
	 * @return int
	 */
	private function openActions(object $objectService, string $planId): int {
		try {
			$rows = $this->readEveryPage(
				objectService: $objectService,
				register: self::REGISTER,
				schema: 'portalAction',
				filters: ['plan' => $planId]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: plan actions not read', ['reason' => $e->getMessage()]);
			return 0;
		}

		$open = 0;
		if (is_array($rows) === false) {
			return 0;
		}

		foreach ($rows as $row) {
			$action = $this->asArray(row: $row);
			if (($action['plan'] ?? '') === $planId && ($action['status'] ?? '') !== 'done') {
				$open++;
			}
		}

		return $open;
	}//end openActions()

	/**
	 * A stored row as an array.
	 *
	 * @param mixed $row The row, an array or an entity.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end asArray()

	/**
	 * A row's identifier.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['uuid'] ?? $row['id'] ?? ($row['@self']['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * Write one message per person, then record the reminder on the plan. The
	 * record comes last and only after every message exists, so a failure
	 * sends the reminder again rather than losing it.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $plan The plan row.
	 * @param string $id The plan's id.
	 * @param int $open How many actions are open.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return bool
	 */
	private function remind(object $objectService, array $plan, string $id, int $open, string $today): bool {
		$end    = substr((string)$plan['endDate'], 0, 10);
		$left   = PlanRules::daysLeft(endDate: $end, today: $today);
		$members = array_filter((array)($plan['participants'] ?? []), 'is_string');
		$people  = array_values(array_unique(array_merge([(string)($plan['owner'] ?? '')], $members)));
		try {
			foreach (array_filter($people) as $ref) {
				$objectService->saveObject(
					object: [
						'subjectRef' => $ref,
						'organisation' => (string)($plan['organisation'] ?? ''),
						'subject' => 'Het plan ' . (string)($plan['title'] ?? '') . ' loopt over ' . (string)$left . ' dagen af',
						'body' => 'Het plan "' . (string)($plan['title'] ?? '') . '" eindigt op ' . $end . '. Er staan nog ' . $open . ' acties open.',
						'read' => false,
						'receivedAt' => gmdate('c'),
						'ruleKey' => self::RULE_KEY,
					],
					register: self::REGISTER,
					schema: 'portalMessage',
					_rbac: false,
					_multitenancy: false
				);
			}

			$objectService->saveObject(
				object: ['endReminderSentAt' => gmdate('c')],
				register: self::REGISTER,
				schema: 'portalPlan',
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: plan reminder failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end remind()
}//end class
