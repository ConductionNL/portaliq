<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\EventRsvpService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which stored answer a new RSVP replaces, whatever shape its id comes in,
 * and what a failed read of the answers means.
 */
class EventRsvpRowsTest extends TestCase {
	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * A store answering the given rows, or failing, and recording each save.
	 *
	 * @param array<int, mixed> $rows  The stored answers.
	 * @param bool              $fails Whether a read fails.
	 *
	 * @return object
	 */
	private function store(array $rows, bool $fails=false): object {
		return new class ($rows, $fails) {
			/** @var array<int, array{0: array<string, mixed>, 1: ?string}> */
			public array $saved = [];

			public function __construct(private array $rows, private bool $fails) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->fails === true) {
					throw new RuntimeException('down');
				}

				return $this->rows;
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved[] = [$object, $uuid];
				return $object;
			}
		};
	}//end store()

	/**
	 * The service over a store, for an event with the given capacity.
	 *
	 * @param object               $store  The store.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return EventRsvpService
	 */
	private function service(object $store, ?LoggerInterface $logger=null): EventRsvpService {
		$reader = $this->createMock(EventFeedReader::class);
		$reader->method('readOwnEvent')->willReturn(['id' => 'e1', 'rsvpEnabled' => true, 'capacity' => 2]);
		$reader->method('isOwnChild')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $store : throw new RuntimeException('no service'));

		return new EventRsvpService($container, $reader, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * The child's earlier answer is replaced by its id, its uuid, its `@self`
	 * id or `@self` uuid, and does not count against the new one; answers of
	 * another event or that are not rows are left out.
	 *
	 * @return void
	 */
	public function testTheEarlierAnswerIsFoundByEveryIdShape(): void {
		$others = [['id' => 'x1', 'eventRef' => 'e1', 'childRef' => 'c9', 'response' => 'yes'], ['id' => 'x2', 'eventRef' => 'e2', 'childRef' => 'c8', 'response' => 'yes'], 'junk'];
		foreach ([
			[['id' => 'a1'], 'a1'],
			[['uuid' => 'u1'], 'u1'],
			[['@self' => ['id' => 's1', 'uuid' => 'su']], 's1'],
			[['@self' => ['uuid' => 'su']], 'su'],
			[['@self' => 'x'], null],
			[['id' => 7], '7'],
		] as [$ids, $uuid]) {
			$store = $this->store(array_merge($others, [$ids + ['eventRef' => 'e1', 'childRef' => 'c1', 'response' => 'yes']]));
			$this->assertNull($this->service($store)->attempt('g1', 'e1', 'c1', 'yes'), json_encode($ids));
			$this->assertSame($uuid, $store->saved[0][1], json_encode($ids));
		}

		$full = $this->store(array_merge($others, [['id' => 'z', 'eventRef' => 'e1', 'childRef' => 'c2', 'response' => 'yes']]));
		$this->assertSame(EventRsvpService::REASON_FULL, $this->service($full)->attempt('g1', 'e1', 'c1', 'yes'));
	}//end testTheEarlierAnswerIsFoundByEveryIdShape()

	/**
	 * A read of the answers that fails is logged and counts as no answers.
	 *
	 * @return void
	 */
	public function testAFailedReadCountsAsNoAnswers(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning')->with('Portaliq: event RSVP count failed', $this->anything());
		$store = $this->store([], true);

		$this->assertNull($this->service($store, $logger)->attempt('g1', 'e1', 'c1', 'yes'));
		$this->assertNull($store->saved[0][1]);
	}//end testAFailedReadCountsAsNoAnswers()
}//end class
