<?php

/**
 * Portaliq Reference Lists (data-lookups-and-checks-in-forms)
 *
 * A choice list maintained once in OpenRegister, one object per item with a
 * `code`, a `label` and an `active` flag, read by list name. A form field
 * names it with `options.referenceList`; the resolver fills the options at
 * render and the validator refuses a value outside the list.
 *
 * @category Intake
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the active items of a reference list, cached for an hour.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t03
 */
class PortalReferenceLists {
	/**
	 * Where the list items live in OpenRegister.
	 */
	public const REGISTER = 'reference-lists';

	/**
	 * The schema of one list item.
	 */
	public const SCHEMA = 'item';

	/**
	 * How long a list is kept, in seconds.
	 */
	private const TTL = 3600;

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    Resolves OpenRegister's object service.
	 * @param ICacheFactory      $cacheFactory Creates the cache.
	 * @param LoggerInterface    $logger       Logs a list that could not be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_reference_lists');
	}//end __construct()

	/**
	 * The active items of a list, as `{value, label}`, in the list's order.
	 *
	 * An unreadable or unknown list answers no items, which the caller treats
	 * as a closed field and never as an open one.
	 *
	 * @param string $list The list name.
	 *
	 * @return array<int, array{value: string, label: string}>
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t03
	 */
	public function items(string $list): array {
		if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $list) !== 1) {
			return [];
		}

		$key = 'list|' . $list;
		$hit = $this->cache->get($key);
		if (is_string($hit) === true) {
			return (array)json_decode($hit, true);
		}

		$items = $this->read(list: $list);
		if ($items !== []) {
			$this->cache->set($key, json_encode($items), self::TTL);
		}

		return $items;
	}//end items()

	/**
	 * Read the list from OpenRegister.
	 *
	 * @param string $list The list name.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private function read(string $list): array {
		$items = [];
		foreach ($this->rowsOf(list: $list) as $row) {
			$item = $this->item(row: $row);
			if ($item !== null) {
				$items[] = $item;
			}
		}

		return $items;
	}//end read()

	/**
	 * The list's rows in OpenRegister, at most 500; none when it could not be read.
	 *
	 * @param string $list The list name.
	 *
	 * @return array<mixed>
	 */
	private function rowsOf(string $list): array {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
			$service->setRegister(register: self::REGISTER);
			$service->setSchema(schema: self::SCHEMA);
			return (array)$service->findAll(
				config: ['filters' => ['list' => $list], 'limit' => 500, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a reference list could not be read', ['list' => $list, 'reason' => $e->getMessage()]);

			return [];
		}
	}//end rowsOf()

	/**
	 * One active row as `{value, label}`, the label falling back to the code;
	 * null for a row without a code.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array{value: string, label: string}|null
	 */
	private function item(mixed $row): ?array {
		$active = $this->activeRow(row: $row);
		$code   = trim((string)($active['code'] ?? ''));
		if ($code === '') {
			return null;
		}

		return ['value' => $code, 'label' => $this->labelOf(row: $active, code: $code)];
	}//end item()

	/**
	 * A row as an array when it is active; [] when it is withdrawn or no row.
	 *
	 * @param mixed $row The row: an entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function activeRow(mixed $row): array {
		$row = $this->plainRow(row: $row);
		if (is_array($row) === false || ($row['active'] ?? true) === false) {
			return [];
		}

		return $row;
	}//end activeRow()

	/**
	 * An entity serialised; anything else as it is.
	 *
	 * @param mixed $row The row.
	 *
	 * @return mixed
	 */
	private function plainRow(mixed $row): mixed {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return $row->jsonSerialize();
		}

		return $row;
	}//end plainRow()

	/**
	 * A row's label, trimmed; its code when it has none.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string               $code Its code.
	 *
	 * @return string
	 */
	private function labelOf(array $row, string $code): string {
		$label = trim((string)($row['label'] ?? ''));
		if ($label === '') {
			return $code;
		}

		return $label;
	}//end labelOf()
}//end class
