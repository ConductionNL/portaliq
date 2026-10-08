<?php

/**
 * Portaliq Action Reminder Service (personal-action-list)
 *
 * Three days before the end date of an action that is not done, the assignee
 * gets one reminder in the portal inbox, and no second one for the same end
 * date. The reminder is a portalMessage with the rule key `action-due`; the
 * action records which end date it was sent for, so a changed end date earns
 * a new reminder and a repeated run sends nothing.
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
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the reminder before an action's end date.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */
class ActionReminderService {
	use PagedObjectReads;

	public const RULE_KEY = 'action-due';

	/**
	 * How many days before the end date the reminder goes out.
	 */
	public const DAYS_AHEAD = 3;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister's ObjectService.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this action is due a reminder on this day: not done, ending
	 * exactly three days ahead, and not already reminded for that end date.
	 *
	 * @param array<string, mixed> $action The action row.
	 * @param string               $today  Today, `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	public static function isDue(array $action, string $today): bool {
		$end = substr((string)($action['endDate'] ?? ''), 0, 10);
		if (($action['status'] ?? 'todo') === 'done' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
			return false;
		}

		if (trim((string)($action['assignee'] ?? '')) === '') {
			return false;
		}

		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
		if ($day === false) {
			return false;
		}

		if ($day->modify('+' . self::DAYS_AHEAD . ' days')->format('Y-m-d') !== $end) {
			return false;
		}

		return (string)($action['reminderForEndDate'] ?? '') !== $end;
	}//end isDue()

	/**
	 * Send every reminder that is due today.
	 *
	 * @param string|null $today Today, `Y-m-d`; defaults to the clock in Amsterdam.
	 *
	 * @return int How many reminders were sent.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	public function remindDue(?string $today = null): int {
		$today ??= (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$rows = $this->readEveryPage(objectService: $objectService, register: self::REGISTER, schema: 'portalAction', filters: []);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: action reminders not read', ['reason' => $e->getMessage()]);
			return 0;
		}

		$sent = 0;
		if (is_array($rows) === false) {
			return 0;
		}

		foreach ($rows as $row) {
			$action = $this->asArray(row: $row);
			if (self::isDue(action: $action, today: $today) === false) {
				continue;
			}

			if ($this->remind(objectService: $objectService, action: $action) === true) {
				$sent++;
			}
		}

		return $sent;
	}//end remindDue()

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
	 * Write the reminder, then record it on the action. The record comes last
	 * and only after the message exists, so a failure sends the reminder again
	 * rather than losing it.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $action        The action row.
	 *
	 * @return bool
	 */
	private function remind(object $objectService, array $action): bool {
		$title = trim((string)($action['title'] ?? ''));
		$id    = (string)($action['uuid'] ?? $action['id'] ?? ($action['@self']['uuid'] ?? ($action['@self']['id'] ?? '')));
		if ($id === '') {
			return false;
		}

		try {
			$objectService->saveObject(
				object: [
					'subjectRef' => (string)$action['assignee'],
					'organisation' => (string)($action['organisation'] ?? ''),
					'subject' => 'Uw actie ' . $title . ' loopt bijna af',
					'body' => 'Uw actie "' . $title . '" moet uiterlijk ' . substr((string)$action['endDate'], 0, 10) . ' klaar zijn.',
					'read' => false,
					'receivedAt' => gmdate('c'),
					'ruleKey' => self::RULE_KEY,
				],
				register: self::REGISTER,
				schema: 'portalMessage',
				_rbac: false,
				_multitenancy: false
			);
			$objectService->saveObject(
				object: ['reminderSentAt' => gmdate('c'), 'reminderForEndDate' => substr((string)$action['endDate'], 0, 10)],
				register: self::REGISTER,
				schema: 'portalAction',
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: action reminder failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end remind()
}//end class
