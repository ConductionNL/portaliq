<?php

/**
 * PortalNoticeReader: every way a row is kept or dropped, and how the
 * published rows are cached and read.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The notice selection and the published read, as recorded.
 */
class PortalNoticeReaderGoldenTest extends TestCase {
	/**
	 * What the doubles were asked, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A published notice for gemeente on the site.
	 *
	 * @param array<string, mixed> $over Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(array $over): array {
		return array_merge(
			[
				'portal'   => 'gemeente',
				'status'   => 'published',
				'surfaces' => ['site'],
				'startsAt' => '2026-10-03T10:00:00+00:00',
				'endsAt'   => '2026-10-04T02:00:00+00:00',
			],
			$over
		);
	}//end row()

	/**
	 * Each guard drops its row; the kept rows are shaped, newest start first,
	 * at most three.
	 *
	 * @return void
	 */
	public function testEveryRowIsKeptOrDroppedAsRecorded(): void {
		$now  = new DateTimeImmutable('2026-10-03T21:00:00+00:00');
		$rows = [
			self::row(['id' => '']),
			self::row(['id' => 'no-start', 'startsAt' => null]),
			self::row(['id' => 'bad-end', 'endsAt' => 'not a date']),
			self::row(['id' => 'blank-start', 'startsAt' => '  ']),
			self::row(['id' => 'future', 'startsAt' => '2026-10-03T21:00:01+00:00']),
			self::row(['id' => 'ends-now', 'endsAt' => '2026-10-03T21:00:00+00:00']),
			self::row(['id' => 'other-portal', 'portal' => 'elders']),
			self::row(['id' => 'draft', 'status' => 'draft']),
			self::row(['id' => 'portal-only', 'surfaces' => ['portal']]),
			self::row(['id' => 'oldest', 'startsAt' => '2026-10-01T00:00:00+00:00', 'level' => 'danger', 'linkUrl' => 'http://x.nl']),
			self::row(['@self' => ['id' => 'self-id'], 'id' => 'ignored', 'startsAt' => '2026-10-03T20:00:00+00:00', 'level' => 'warning', 'message' => 'M', 'linkLabel' => 'L', 'linkUrl' => ' https://x.nl/a ']),
			self::row(['id' => 'starts-now', 'startsAt' => '2026-10-03T21:00:00+00:00']),
			self::row(['id' => 'middle', 'startsAt' => '2026-10-02T00:00:00+00:00']),
		];

		$this->assertSame(
			[
				['id' => 'starts-now', 'message' => '', 'level' => 'info', 'linkLabel' => '', 'linkUrl' => '', 'endsAt' => '2026-10-04T02:00:00+00:00'],
				['id' => 'self-id', 'message' => 'M', 'level' => 'warning', 'linkLabel' => 'L', 'linkUrl' => 'https://x.nl/a', 'endsAt' => '2026-10-04T02:00:00+00:00'],
				['id' => 'middle', 'message' => '', 'level' => 'info', 'linkLabel' => '', 'linkUrl' => '', 'endsAt' => '2026-10-04T02:00:00+00:00'],
			],
			PortalNoticeReader::select(rows: $rows, portal: 'gemeente', surface: 'site', now: $now)
		);
	}//end testEveryRowIsKeptOrDroppedAsRecorded()

	/**
	 * A reader whose cache and object service record what they are asked.
	 *
	 * @param array<string, mixed> $world hit (cached string), applies (bool), fails (bool), rows.
	 *
	 * @return PortalNoticeReader
	 */
	private function reader(array $world): PortalNoticeReader {
		$this->calls = [];
		$test        = $this;
		$objects     = new class($world, $test) {
			/**
			 * @param array                        $world The world.
			 * @param PortalNoticeReaderGoldenTest $test  The test.
			 */
			public function __construct(private array $world, private PortalNoticeReaderGoldenTest $test) {
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->note('findAll ' . json_encode($config) . ' rbac=' . var_export($_rbac, true) . ' mt=' . var_export($_multitenancy, true));
				if (($this->world['fails'] ?? false) === true) {
					throw new RuntimeException('down');
				}

				return ($this->world['rows'] ?? []);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function () use ($objects) {
			$this->calls[] = 'container';
			return $objects;
		});
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(function () use ($world) {
			$this->calls[] = 'apply';
			return ($world['applies'] ?? true);
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) use ($world) {
			$this->calls[] = "get $key";
			return ($world['hit'] ?? null);
		});
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl) {
			$this->calls[] = "set $key $value $ttl";
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context) {
			$this->calls[] = "error $message " . json_encode($context);
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-10-03T21:00:00+00:00'));

		return new PortalNoticeReader($container, $factory, $logger, $context, $time);
	}//end reader()

	/**
	 * Record one call from the object service double.
	 *
	 * @param string $call The call.
	 *
	 * @return void
	 */
	public function note(string $call): void {
		$this->calls[] = $call;
	}//end note()

	/**
	 * A cache hit, a register that cannot be set, a failed read and a good
	 * read: what is read, logged and cached.
	 *
	 * @return void
	 */
	public function testThePublishedRowsAreCachedAndReadAsRecorded(): void {
		$row = self::row(['id' => 'n-1']);

		$this->assertSame('n-1', $this->reader(['hit' => json_encode([$row])])->active(portal: 'gemeente', surface: 'site')[0]['id']);
		$this->assertSame(['get gemeente'], $this->calls);

		$this->assertSame('n-1', $this->reader(['hit' => 'not json', 'rows' => [$row, new class { public function jsonSerialize(): array { return ['id' => 'obj']; } }]])->active(portal: 'gemeente', surface: 'site')[0]['id']);
		$this->assertSame(
			[
				'get gemeente',
				'container',
				'apply',
				'findAll {"filters":{"portal":"gemeente","status":"published"},"limit":100,"offset":0} rbac=false mt=false',
				'set gemeente ' . json_encode([$row, ['id' => 'obj']]) . ' 60',
			],
			$this->calls
		);

		$this->assertSame([], $this->reader(['applies' => false, 'rows' => [$row]])->active(portal: 'gemeente', surface: 'site'));
		$this->assertSame(['get gemeente', 'container', 'apply'], $this->calls);

		$this->assertSame([], $this->reader(['fails' => true])->active(portal: 'gemeente', surface: 'site'));
		$this->assertSame(
			[
				'get gemeente',
				'container',
				'apply',
				'findAll {"filters":{"portal":"gemeente","status":"published"},"limit":100,"offset":0} rbac=false mt=false',
				'error Portaliq: notice read failed {"portal":"gemeente","reason":"down"}',
			],
			$this->calls
		);
	}//end testThePublishedRowsAreCachedAndReadAsRecorded()
}//end class
