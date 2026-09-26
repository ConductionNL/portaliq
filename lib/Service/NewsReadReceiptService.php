<?php

/**
 * News Read Receipt Service
 *
 * Records that a guardian has read a `newsItem`, exactly once per guardian,
 * however many times they read it. The generic contribution-contract writer
 * only MERGES whitelisted fields (a client value replaces the existing one);
 * this needs APPEND-IF-ABSENT semantics on an array the client never
 * whitelists directly, which is why it is its own small service rather than
 * a generic `type: update` action.
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
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
 */
class NewsReadReceiptService {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'newsItem';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param NewsFeedReader $feedReader Re-verifies the item is in the guardian's own audience.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly NewsFeedReader $feedReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Mark one `newsItem` read by one guardian, idempotently. Returns false
	 * for EVERY failure shape (not found, not published, out-of-audience) —
	 * the controller maps false to a single 404, carrying no existence oracle.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $id The newsItem id.
	 *
	 * @return bool True on success (including "already recorded").
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	public function markRead(string $subjectRef, string $id): bool {
		if ($subjectRef === '' || $id === '') {
			return false;
		}

		// Re-verify ownership/audience FIRST — a guardian cannot mark an
		// out-of-audience item read, which would otherwise leak a minor
		// existence oracle through the 204/404 split.
		$item = $this->feedReader->readOwnItem(subjectRef: $subjectRef, id: $id);
		if ($item === null) {
			return false;
		}

		$receipts = [];
		if (is_array($item['readReceipts'] ?? null) === true) {
			$receipts = $item['readReceipts'];
		}

		if ($this->alreadyRecorded(receipts: $receipts, subjectRef: $subjectRef) === true) {
			// Already recorded — idempotent no-op, still a success.
			return true;
		}

		$receipts[] = ['subjectRef' => $subjectRef, 'readAt' => gmdate('c')];

		return $this->save(item: $item, receipts: $receipts, id: $id);
	}//end markRead()

	/**
	 * Whether a subject already has a read receipt on this item.
	 *
	 * @param array<int, mixed> $receipts The item's current receipts.
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return bool
	 */
	private function alreadyRecorded(array $receipts, string $subjectRef): bool {
		foreach ($receipts as $receipt) {
			if (is_array($receipt) === true && (string)($receipt['subjectRef'] ?? '') === $subjectRef) {
				return true;
			}
		}

		return false;
	}//end alreadyRecorded()

	/**
	 * Persist the item with its receipts appended.
	 *
	 * @param array<string, mixed> $item The current item.
	 * @param array<int, mixed> $receipts The updated receipts list.
	 * @param string $id The item id (preserved so OR updates, not creates).
	 *
	 * @return bool
	 */
	private function save(array $item, array $receipts, string $id): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		$merged = $item;
		unset($merged['@self']);
		$merged['readReceipts'] = $receipts;

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
			$this->logger->warning('Portaliq: news read-receipt save failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end save()

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
