<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFieldProjector;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The `via` read asks OpenRegister for the subject's own rows, the way a real
 * OpenRegister answers: filters are applied and the page is capped at the
 * limit. Found on a primary school example set (1,189 attendance records,
 * 396 report cards): an unfiltered outer read of 200 rows dropped a parent's
 * own child's rows, and the collection's declared `filter` (report cards
 * `published-to-parents` only) was never applied on the via path.
 *
 * @spec openspec/changes/via-read-scoped-query/tasks.md#T1
 */
class PortalObjectReaderViaQueryTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const CHILD_JOIN = [
		'register' => 'learniq',
		'schema' => 'learner-profile',
		'scopeField' => 'guardianRefs',
		'targetField' => 'id',
		'match' => 'scopeField',
	];

	/**
	 * A child's row behind more than a page of other pupils' rows is returned.
	 */
	public function testReverseViaFindsTheChildsRowsBeyondThePageOfOtherRows(): void {
		$rows = [];
		for ($i = 1; $i <= 250; $i++) {
			$rows[] = ['id' => 'a-' . $i, 'learnerRef' => 'other-' . $i, 'status' => 'late'];
		}

		$rows[] = ['id' => 'a-own', 'learnerRef' => 'child-1', 'status' => 'absent-excused'];

		$objectService = $this->filteringObjectService(
			[
				'learner-profile' => [['id' => 'child-1', 'guardianRefs' => ['guardian-1']]],
				'attendance-record' => $rows,
			]
		);

		$result = $this->read(objectService: $objectService, schema: 'attendance-record', filter: []);

		$this->assertSame(['a-own'], array_column($result, 'id'));

	}//end testReverseViaFindsTheChildsRowsBeyondThePageOfOtherRows()

	/**
	 * The declared collection filter also narrows a via read.
	 */
	public function testReverseViaAppliesTheDeclaredFilter(): void {
		$objectService = $this->filteringObjectService(
			[
				'learner-profile' => [['id' => 'child-1', 'guardianRefs' => ['guardian-1']]],
				'report-card' => [
					['id' => 'rc-draft', 'learnerRef' => 'child-1', 'lifecycle' => 'draft'],
					['id' => 'rc-published', 'learnerRef' => 'child-1', 'lifecycle' => 'published-to-parents'],
				],
			]
		);

		$result = $this->read(
			objectService: $objectService,
			schema: 'report-card',
			filter: ['lifecycle' => 'published-to-parents']
		);

		$this->assertSame(['rc-published'], array_column($result, 'id'));

	}//end testReverseViaAppliesTheDeclaredFilter()

	/**
	 * A declared filter can never widen past the child set: a filter that
	 * names the scope field is overridden by the verified target.
	 */
	public function testADeclaredFilterOnTheScopeFieldCannotWidenTheVia(): void {
		$objectService = $this->filteringObjectService(
			[
				'learner-profile' => [['id' => 'child-1', 'guardianRefs' => ['guardian-1']]],
				'report-card' => [
					['id' => 'rc-other', 'learnerRef' => 'other-1', 'lifecycle' => 'published-to-parents'],
					['id' => 'rc-own', 'learnerRef' => 'child-1', 'lifecycle' => 'published-to-parents'],
				],
			]
		);

		$result = $this->read(
			objectService: $objectService,
			schema: 'report-card',
			filter: ['learnerRef' => 'other-1']
		);

		$this->assertSame(['rc-own'], array_column($result, 'id'));

	}//end testADeclaredFilterOnTheScopeFieldCannotWidenTheVia()

	/**
	 * The single-object read honours the declared filter too: a report card
	 * under review is a 404 by id, on the via path and on a direct one.
	 */
	public function testTheDeclaredFilterAlsoHoldsForAReadById(): void {
		$objectService = $this->filteringObjectService(
			[
				'learner-profile' => [['id' => 'child-1', 'guardianRefs' => ['guardian-1']]],
				'report-card' => [
					['id' => 'rc-draft', 'learnerRef' => 'child-1', 'lifecycle' => 'draft'],
					['id' => 'rc-published', 'learnerRef' => 'child-1', 'lifecycle' => 'published-to-parents'],
				],
			]
		);
		$reader = new PortalObjectReader(
			$this->container($objectService),
			$this->createMock(LoggerInterface::class),
			new PortalFieldProjector($this->createMock(LoggerInterface::class))
		);

		$read = fn (string $id, mixed $via, string $scopeField, string $subject): ?array => $reader->readObject(
			register: 'learniq',
			schema: 'report-card',
			scopeField: $scopeField,
			subjectRef: $subject,
			id: $id,
			via: $via,
			audience: 'parent',
			filter: ['lifecycle' => 'published-to-parents']
		);

		$this->assertNull($read('rc-draft', self::CHILD_JOIN, 'learnerRef', 'guardian-1'));
		$this->assertSame('rc-published', $read('rc-published', self::CHILD_JOIN, 'learnerRef', 'guardian-1')['id']);
		$this->assertNull($read('rc-draft', null, 'learnerRef', 'child-1'));
		$this->assertSame('rc-published', $read('rc-published', null, 'learnerRef', 'child-1')['id']);

	}//end testTheDeclaredFilterAlsoHoldsForAReadById()

	/**
	 * Read one reverse-via collection for guardian-1.
	 *
	 * @param object $objectService The fake OpenRegister.
	 * @param string $schema The outer schema.
	 * @param array<string, mixed> $filter The declared collection filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(object $objectService, string $schema, array $filter): array {
		$reader = new PortalObjectReader(
			$this->container($objectService),
			$this->createMock(LoggerInterface::class),
			new PortalFieldProjector($this->createMock(LoggerInterface::class))
		);

		return $reader->readCollection(
			register: 'learniq',
			schema: $schema,
			scopeField: 'learnerRef',
			subjectRef: 'guardian-1',
			organisation: '',
			limit: 200,
			scopeClaim: '',
			contributingApp: 'learniq',
			via: self::CHILD_JOIN,
			audience: 'parent',
			fields: null,
			filter: $filter
		);

	}//end read()

	/**
	 * A fake ObjectService that answers like OpenRegister: scalar filters
	 * match by equality (or membership for a list property) and the page is
	 * capped at the limit.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 *
	 * @return object
	 */
	private function filteringObjectService(array $rows): object {
		return new class($rows) {
			private string $schema = '';

			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$matched = [];
				foreach (($this->rows[$this->schema] ?? []) as $row) {
					foreach (($config['filters'] ?? []) as $key => $value) {
						$have = ($row[$key] ?? null);
						if ($have !== $value && (is_array($have) === false || in_array($value, $have, true) === false)) {
							continue 2;
						}
					}

					$matched[] = $row;
				}

				return array_slice($matched, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 20));
			}//end findAll()

			/**
			 * @return array<string,mixed>|null
			 */
			public function find(string $id, string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true): ?array {
				foreach (($this->rows[$schema !== '' ? $schema : $this->schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						return $row;
					}
				}

				return null;
			}//end find()
		};

	}//end filteringObjectService()

	private function container(object $objectService): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end container()
}//end class
