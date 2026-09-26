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
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */
class PendingPushService {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'pendingPush';

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
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-non-emergency-push-during-quiet-hours-is-deferred-not-dropped
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
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
	 */
	public function deliverDue(DateTimeImmutable $now): int {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: pending push read failed', ['reason' => $e->getMessage()]);
			return 0;
		}

		if (is_array($rows) === false) {
			return 0;
		}

		$delivered = 0;
		foreach ($rows as $row) {
			if ($this->deliverIfDue(objectService: $objectService, row: $row, now: $now) === true) {
				$delivered++;
			}
		}

		return $delivered;
	}//end deliverDue()

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
		$normalised = $this->normalise(row: $row);
		if ($normalised === null || ($normalised['delivered'] ?? false) === true) {
			return false;
		}

		$deliverAfter = (string)($normalised['deliverAfter'] ?? '');
		if ($deliverAfter === '' || new DateTimeImmutable($deliverAfter) > $now) {
			return false;
		}

		$sent = $this->sender->send(
			subjectRef: (string)($normalised['subjectRef'] ?? ''),
			title: (string)($normalised['title'] ?? ''),
			body: (string)($normalised['body'] ?? '')
		);

		if ($sent === false) {
			return false;
		}

		$id = $this->rowId(row: $normalised);
		if ($id === null) {
			return false;
		}

		$merged = $normalised;
		unset($merged['@self']);
		$merged['delivered'] = true;

		try {
			$objectService->saveObject(
				object: $merged,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: pending push mark-delivered failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end deliverIfDue()

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

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
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
