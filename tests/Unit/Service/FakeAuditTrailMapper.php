<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use RuntimeException;

/**
 * A stand-in for OpenRegister's AuditTrailMapper holding real AuditTrail rows.
 */
class FakeAuditTrailMapper {
	/**
	 * @var list<object>
	 */
	public array $rows = [];

	/**
	 * @param list<object> $entries
	 *
	 * @return list<object>
	 */
	public function insertAuditTrails(array $entries, int $chunkSize = 100): array {
		foreach ($entries as $entry) {
			if ((string)$entry->getUuid() === '') {
				throw new RuntimeException('insertAuditTrails() requires every pre-built row to carry a uuid.');
			}

			$this->rows[] = $entry;
		}

		return $entries;
	}

	/**
	 * @return list<object>
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = [], ?string $search = null): array {
		$found = array_values(
			array_filter(
				$this->rows,
				static function (object $row) use ($filters): bool {
					foreach ($filters ?? [] as $field => $value) {
						$getter = 'get' . ucfirst($field);
						if ($row->$getter() !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);

		return array_slice($found, (int)$offset, $limit);
	}

	public function foreignRow(string $action): object {
		$row = new \OCA\OpenRegister\Db\AuditTrail();
		$row->setUuid('foreign-' . count($this->rows));
		$row->setAction($action);

		return $row;
	}
}
