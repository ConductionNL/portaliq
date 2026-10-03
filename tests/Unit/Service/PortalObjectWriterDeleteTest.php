<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * inbox-delete-own-messages T1: the writer deletes a row only when it is
 * the subject's alone. Another subject's row, another tenant's row, a row
 * shared with someone else and an unknown id are never deleted, and all
 * answer the same false.
 *
 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
 */
class PortalObjectWriterDeleteTest extends TestCase {
	/**
	 * A store over the given rows that records each delete.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return object
	 */
	private function store(array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array{uuid: string, register: mixed, schema: mixed, rbac: bool, multitenancy: bool}>
			 */
			public array $deleted = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The stored rows.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				foreach ($this->rows as $row) {
					if (($row['@self']['id'] ?? null) === $id) {
						return $row;
					}
				}

				return null;
			}

			public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->deleted[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return true;
			}
		};
	}

	private function writer(object $store): PortalObjectWriter {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		return new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			['@self' => ['id' => 'mine'], 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine'],
			['@self' => ['id' => 'theirs'], 'subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Theirs'],
			['@self' => ['id' => 'other-tenant'], 'subjectRef' => 's1', 'organisation' => 'org-2', 'title' => 'Elsewhere'],
			['@self' => ['id' => 'shared'], 'subjectRef' => ['s1', 's2'], 'organisation' => 'org-1', 'title' => 'Shared'],
			['@self' => ['id' => 'mine-in-a-list'], 'subjectRef' => ['s1'], 'organisation' => 'org-1', 'title' => 'Mine too'],
		];
	}

	public function testItDeletesTheSubjectsOwnRowWithRbacBypassed(): void {
		$store = $this->store($this->rows());

		$this->assertTrue($this->writer($store)->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'org-1', 'mine'));
		$this->assertTrue($this->writer($store)->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'org-1', 'mine-in-a-list'));

		$this->assertSame(['mine', 'mine-in-a-list'], array_column($store->deleted, 'uuid'));
		$this->assertSame('portaliq', $store->deleted[0]['register']);
		$this->assertSame('portalMessage', $store->deleted[0]['schema']);
		$this->assertFalse($store->deleted[0]['rbac']);
		$this->assertFalse($store->deleted[0]['multitenancy']);
	}

	public function testItNeverDeletesARowThatIsNotTheSubjectsAlone(): void {
		$store = $this->store($this->rows());
		$writer = $this->writer($store);

		foreach (['theirs', 'other-tenant', 'shared', 'unknown', ''] as $id) {
			$this->assertFalse($writer->deleteObject('portaliq', 'portalMessage', 'subjectRef', 's1', 'org-1', $id), $id);
		}

		$this->assertFalse($writer->deleteObject('portaliq', 'portalMessage', '', 's1', 'org-1', 'mine'), 'no scope field, no delete');
		$this->assertSame([], $store->deleted);
	}
}
