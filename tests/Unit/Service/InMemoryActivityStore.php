<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An ActivityStore that keeps its rows in memory, so the activity services'
 * rules run against real reads and writes without OpenRegister. `find()`,
 * `keys()` and `idOf()` are the real ones; only `rows()` and `save()` are
 * replaced. A schema listed in `$unreadable` reads as null, the store's
 * failure value.
 *
 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
 */
class InMemoryActivityStore extends ActivityStore {
	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $data = [];

	/**
	 * Schemas whose read fails.
	 *
	 * @var array<int, string>
	 */
	public array $unreadable = [];

	/**
	 * Every save, in order.
	 *
	 * @var array<int, array{schema: string, data: array<string, mixed>, id: string}>
	 */
	public array $saves = [];

	/**
	 * Whether saves fail.
	 *
	 * @var bool
	 */
	public bool $failSaves = false;

	/**
	 * A counter for generated ids.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Unused by the in-memory store.
	 * @param LoggerInterface $logger Unused by the in-memory store.
	 * @param array<string, array<int, array<string, mixed>>> $data The initial rows.
	 */
	public function __construct(ContainerInterface $container, LoggerInterface $logger, array $data = []) {
		parent::__construct($container, $logger);
		$this->data = $data;
	}//end __construct()

	/**
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public function rows(string $schema): ?array {
		if (in_array($schema, $this->unreadable, true) === true) {
			return null;
		}

		return array_values($this->data[$schema] ?? []);
	}//end rows()

	/**
	 * @param string $schema The schema.
	 * @param array<string, mixed> $data The data.
	 * @param string $id The id to update, or ''.
	 *
	 * @return array<string, mixed>|null
	 */
	public function save(string $schema, array $data, string $id = ''): ?array {
		$this->saves[] = ['schema' => $schema, 'data' => $data, 'id' => $id];
		if ($this->failSaves === true) {
			return null;
		}

		unset($data['@self']);
		if ($id === '') {
			$data['id'] = $schema . '-' . $this->next++;
			$this->data[$schema][] = $data;
			return $data;
		}

		foreach (($this->data[$schema] ?? []) as $index => $row) {
			if (in_array($id, $this->keys(row: $row), true) === true) {
				$data['id'] = $this->idOf(row: $row);
				$data['@self'] = ($row['@self'] ?? []);
				$this->data[$schema][$index] = $data;
				return $data;
			}
		}

		return null;
	}//end save()

	/**
	 * The rows of a schema with a given field value.
	 *
	 * @param string $schema The schema.
	 * @param string $field The field.
	 * @param mixed $value The value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function where(string $schema, string $field, mixed $value): array {
		return array_values(array_filter($this->data[$schema] ?? [], static fn (array $row): bool => ($row[$field] ?? null) === $value));
	}//end where()
}//end class
