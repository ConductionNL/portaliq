<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalReferenceLists;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * data-lookups-and-checks-in-forms T03: only active items, in order, cached
 * for the hour, and an unreadable list answers nothing.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t03
 */
class PortalReferenceListsTest extends TestCase {

	private function lists(object $service, array &$cache): PortalReferenceLists {
		$store = new class($cache) implements ICache {
			public function __construct(private array &$data) {
			}

			public function get($key) {
				return ($this->data[$key] ?? null);
			}

			public function set($key, $value, $ttl = 0) {
				$this->data[$key] = $value;
				return true;
			}

			public function hasKey($key) {
				return isset($this->data[$key]);
			}

			public function remove($key) {
				unset($this->data[$key]);
				return true;
			}

			public function clear($prefix = '') {
				$this->data = [];
				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}
		};
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($store);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return new PortalReferenceLists($container, $factory, $this->createMock(LoggerInterface::class));
	}//end lists()

	private function service(array $rows): object {
		return new class($rows) {
			public int $reads = 0;

			public array $filters = [];

			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): void {
			}

			public function setSchema(string $schema): void {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads++;
				$this->filters = $config['filters'];

				return $this->rows;
			}
		};
	}//end service()

	public function testOnlyActiveItemsComeBackInOrderAndAreCached(): void {
		$service = $this->service([
			['code' => 'flat', 'label' => 'Flat', 'active' => true],
			['code' => 'oud', 'label' => 'Oud', 'active' => false],
			['code' => 'huis', 'active' => true],
			['label' => 'Zonder code', 'active' => true],
		]);
		$cache = [];
		$lists = $this->lists($service, $cache);

		$this->assertSame([['value' => 'flat', 'label' => 'Flat'], ['value' => 'huis', 'label' => 'huis']], $lists->items('woningtypen'));
		$this->assertSame(['list' => 'woningtypen'], $service->filters);

		$lists->items('woningtypen');
		$this->assertSame(1, $service->reads, 'the second read comes from the cache');
	}//end testOnlyActiveItemsComeBackInOrderAndAreCached()

	public function testAnUnreadableOrOddlyNamedListAnswersNothingAndIsNotCached(): void {
		$cache = [];
		$broken = new class {
			public function setRegister(string $register): void {
				throw new \RuntimeException('no register');
			}
		};
		$lists = $this->lists($broken, $cache);

		$this->assertSame([], $lists->items('woningtypen'));
		$this->assertSame([], $cache);
		$this->assertSame([], $lists->items('../../etc'));
	}//end testAnUnreadableOrOddlyNamedListAnswersNothingAndIsNotCached()
}
