<?php

/**
 * Pending Push Service
 *
 * OpenRegister persistence for the deferred-push queue: queue a push that
 * was refused by quiet hours, and deliver every row whose `deliverAfter`
 * has been reached. {@see PushDeliveryService} calls `queue()`;
 * {@see \OCA\Portaliq\BackgroundJob\PendingPushDeliveryJob} calls
 * `deliverDue()`.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
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
 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use DateTimeImmutable;
use OCA\Portaliq\Kernel\Contracts\PendingPushServiceInterface;
use OCA\Portaliq\Service\ObjectRowNormaliser;
use OCA\Portaliq\Service\ObjectServiceLookup;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */
class PendingPushService implements PendingPushServiceInterface {
	use ObjectRowNormaliser;
	use ObjectServiceLookup;

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'pendingPush';

	/**
	 * Rows read per page. Delivered rows stay in the queue, so every page is
	 * read until a short one: a fixed first page would, once filled with
	 * delivered rows, hide every later push for good.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param PushSenderInterface $sender The push transport.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PushSenderInterface $sender,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue a push for later delivery.
	 *
	 * @param string $subjectRef The recipient's own subjectRef.
	 * @param string $title The notification title.
	 * @param string $body The notification body.
	 * @param DateTimeImmutable $deliverAfter When the window ends.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
	 */
	public function queue(string $subjectRef, string $title, string $body, DateTimeImmutable $deliverAfter): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$objectService->saveObject(
				object: [
					'subjectRef' => $subjectRef,
					'title' => $title,
					'body' => $body,
					'deliverAfter' => $deliverAfter->format('c'),
					'delivered' => false,
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: pending push queue failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end queue()

	/**
	 * Deliver every not-yet-delivered row whose `deliverAfter` is at or
	 * before `$now`, and leave every not-yet-due row untouched.
	 *
	 * @param DateTimeImmutable $now The run time.
	 *
	 * @return int The number of rows delivered.
	 *
	 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
	 */
	public function deliverDue(DateTimeImmutable $now): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		return $this->deliverAll(objectService: $objectService, now: $now);
	}//end deliverDue()

	/**
	 * Deliver the due rows of every page, until a short page.
	 *
	 * @param object            $objectService OpenRegister's ObjectService.
	 * @param DateTimeImmutable $now           The run time.
	 *
	 * @return int The number of rows delivered.
	 */
	private function deliverAll(object $objectService, DateTimeImmutable $now): int {
		$delivered = 0;
		$offset = 0;
		do {
			$rows = $this->readPage(objectService: $objectService, offset: $offset);
			if ($rows === null) {
				return $delivered;
			}

			$delivered += $this->deliverPage(objectService: $objectService, rows: $rows, now: $now);

			$offset += self::PAGE;
			$pageSize = count($rows);
		} while ($pageSize === self::PAGE);

		return $delivered;
	}//end deliverAll()

	/**
	 * Read one page of queue rows.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param int    $offset        The page offset.
	 *
	 * @return array|null The rows, or null when the read failed or returned no list.
	 */
	private function readPage(object $objectService, int $offset): array|null {
		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: pending push read failed', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		return $rows;
	}//end readPage()

	/**
	 * Deliver every due row of one page.
	 *
	 * @param object            $objectService OpenRegister's ObjectService.
	 * @param array             $rows          The page's raw rows.
	 * @param DateTimeImmutable $now           The run time.
	 *
	 * @return int The number of rows delivered.
	 */
	private function deliverPage(object $objectService, array $rows, DateTimeImmutable $now): int {
		$delivered = 0;
		foreach ($rows as $row) {
			if ($this->deliverIfDue(objectService: $objectService, row: $row, now: $now) === true) {
				$delivered++;
			}
		}

		return $delivered;
	}//end deliverPage()

	/**
	 * Deliver one row if it is due and not already delivered.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param mixed $row The raw row.
	 * @param DateTimeImmutable $now The run time.
	 *
	 * @return bool Whether this row was delivered on this call.
	 */
	private function deliverIfDue(object $objectService, mixed $row, DateTimeImmutable $now): bool {
		$normalised = $this->undeliveredRow(row: $row);
		if ($normalised === null) {
			return false;
		}

		if ($this->isDue(normalised: $normalised, now: $now) === false) {
			return false;
		}

		if ($this->sendRow(normalised: $normalised) === false) {
			return false;
		}

		return $this->markDelivered(objectService: $objectService, normalised: $normalised);
	}//end deliverIfDue()

	/**
	 * The normalised row, or null when it is unreadable or already delivered.
	 *
	 * @param mixed $row The raw row.
	 *
	 * @return array|null
	 */
	private function undeliveredRow(mixed $row): array|null {
		$normalised = $this->normalise(row: $row);
		if ($normalised === null || ($normalised['delivered'] ?? false) === true) {
			return null;
		}

		return $normalised;
	}//end undeliveredRow()

	/**
	 * Whether the row's `deliverAfter` has been reached.
	 *
	 * @param array             $normalised The normalised row.
	 * @param DateTimeImmutable $now        The run time.
	 *
	 * @return bool
	 */
	private function isDue(array $normalised, DateTimeImmutable $now): bool {
		$deliverAfter = (string)($normalised['deliverAfter'] ?? '');
		if ($deliverAfter === '') {
			return false;
		}

		$due = $this->dueAt(normalised: $normalised, deliverAfter: $deliverAfter);
		if ($due === null) {
			return false;
		}

		if ($due > $now) {
			return false;
		}

		return true;
	}//end isDue()

	/**
	 * Parse the row's `deliverAfter`, logging an unreadable one.
	 *
	 * @param array  $normalised   The normalised row.
	 * @param string $deliverAfter The row's non-empty `deliverAfter`.
	 *
	 * @return DateTimeImmutable|null The moment, or null when unreadable.
	 */
	private function dueAt(array $normalised, string $deliverAfter): DateTimeImmutable|null {
		try {
			$due = new DateTimeImmutable($deliverAfter);
		} catch (Throwable $e) {
			// One malformed row is skipped; it must not stop the rows after it.
			$this->logger->warning(
				'Portaliq: pending push has an unreadable deliverAfter',
				['id' => $this->rowId(row: $normalised), 'reason' => $e->getMessage()]
			);
			return null;
		}

		return $due;
	}//end dueAt()

	/**
	 * Send the row's push.
	 *
	 * @param array $normalised The normalised row.
	 *
	 * @return bool Whether the push was sent.
	 */
	private function sendRow(array $normalised): bool {
		return $this->sender->send(
			subjectRef: (string)($normalised['subjectRef'] ?? ''),
			title: (string)($normalised['title'] ?? ''),
			body: (string)($normalised['body'] ?? '')
		);
	}//end sendRow()

	/**
	 * Mark the row delivered in the queue.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array  $normalised    The normalised row.
	 *
	 * @return bool Whether the row was marked delivered.
	 */
	private function markDelivered(object $objectService, array $normalised): bool {
		$id = $this->rowId(row: $normalised);
		if ($id === null) {
			return false;
		}

		$merged = $normalised;
		unset($merged['@self']);
		$merged['delivered'] = true;

		return $this->saveDelivered(objectService: $objectService, merged: $merged, id: $id);
	}//end markDelivered()

	/**
	 * Save the delivered row.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array  $merged        The row marked delivered.
	 * @param string $id            The row's id.
	 *
	 * @return bool Whether the save succeeded.
	 */
	private function saveDelivered(object $objectService, array $merged, string $id): bool {
		try {
			$objectService->saveObject(object: $merged, register: self::REGISTER, schema: self::SCHEMA, uuid: $id, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: pending push mark-delivered failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end saveDelivered()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return null;
	}//end rowId()
}//end class
