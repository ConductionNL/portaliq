<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\BackfillCmsPortalReference;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * portal-cms-content-model task 4: content without a portal gets the only
 * portal, a second run writes nothing, the count is reported and what could
 * not be placed is reported rather than guessed.
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-4
 */
class BackfillCmsPortalReferenceTest extends TestCase {
	/**
	 * A store holding rows per schema, answering for whichever schema was applied last.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema The stored rows per schema.
	 */
	private function store(array $bySchema): object {
		return new class($bySchema) {
			public string $current = '';

			/**
			 * @var array<int, array{schema: string, uuid: string|null, object: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema The rows.
			 */
			public function __construct(public array $bySchema) {
			}

			/**
			 * @param array<string, mixed> $config The paging.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->bySchema[$this->current] ?? [], (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saves[] = ['schema' => (string)$schema, 'uuid' => $uuid, 'object' => $object];
				foreach ($this->bySchema[(string)$schema] as $i => $row) {
					if (($row['@self']['uuid'] ?? null) === $uuid) {
						$this->bySchema[(string)$schema][$i] = $object + ['@self' => $row['@self']];
					}
				}

				return $object;
			}
		};
	}//end store()

	private function step(object $store): BackfillCmsPortalReference {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(
			static function (object $service, string $schemaSlug) use ($store): bool {
				$store->current = $schemaSlug;
				return true;
			}
		);

		return new BackfillCmsPortalReference($container, $context, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * Run the step and return what it reported.
	 *
	 * @return string[]
	 */
	private function runStep(object $store): array {
		$lines  = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function ($m) use (&$lines): void {
			$lines[] = (string)$m;
		});
		$output->method('warning')->willReturnCallback(static function ($m) use (&$lines): void {
			$lines[] = (string)$m;
		});
		$this->step($store)->run($output);

		return $lines;
	}//end runStep()

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function content(string $portalSlug = 'gemeente'): array {
		return [
			'portal'       => [['slug' => $portalSlug, '@self' => ['uuid' => 'p1']]],
			'menu'         => [['title' => 'Menu', '@self' => ['uuid' => 'm1']]],
			'page'         => [['title' => 'A', '@self' => ['uuid' => 'a1']], ['title' => 'B', 'portal' => 'gemeente', '@self' => ['uuid' => 'a2']]],
			'glossaryTerm' => [['term' => 'Woo', '@self' => ['uuid' => 't1']]],
			'media'        => [],
		];
	}//end content()

	public function testContentWithoutAPortalTakesTheOnlyPortalAndTheCountIsReported(): void {
		$store = $this->store($this->content());

		$lines = $this->runStep($store);

		$this->assertCount(3, $store->saves);
		foreach ($store->saves as $save) {
			$this->assertSame('gemeente', $save['object']['portal']);
			$this->assertArrayNotHasKey('@self', $save['object']);
		}

		$this->assertContains('BackfillCmsPortalReference: assigned a portal to 3 objects, 0 still without one.', $lines);
	}//end testContentWithoutAPortalTakesTheOnlyPortalAndTheCountIsReported()

	public function testASecondRunWritesNothingAndSaysSo(): void {
		$store = $this->store($this->content());
		$this->runStep($store);
		$saved = count($store->saves);

		$lines = $this->runStep($store);

		$this->assertCount($saved, $store->saves);
		$this->assertContains('BackfillCmsPortalReference: assigned a portal to 0 objects, 0 still without one.', $lines);
	}//end testASecondRunWritesNothingAndSaysSo()

	public function testWithTwoPortalsNothingIsGuessedAndTheOrphansAreCounted(): void {
		$content           = $this->content();
		$content['portal'][] = ['slug' => 'andere', '@self' => ['uuid' => 'p2']];
		$store             = $this->store($content);

		$lines = $this->runStep($store);

		$this->assertSame([], $store->saves);
		$this->assertContains('BackfillCmsPortalReference: assigned a portal to 0 objects, 3 still without one.', $lines);
	}//end testWithTwoPortalsNothingIsGuessedAndTheOrphansAreCounted()

	public function testAfterTheRunNoContentObjectIsLeftWithoutAPortal(): void {
		$store = $this->store($this->content());
		$this->runStep($store);

		foreach (BackfillCmsPortalReference::CONTENT_SCHEMAS as $schema) {
			foreach ($store->bySchema[$schema] as $row) {
				$this->assertSame('gemeente', $row['portal'] ?? null, $schema . ' object has a portal');
			}
		}
	}//end testAfterTheRunNoContentObjectIsLeftWithoutAPortal()

	public function testItIsNotRegisteredOnInstall(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		preg_match('#<install>(.*?)</install>#s', $info, $install);
		preg_match('#<post-migration>(.*?)</post-migration>#s', $info, $post);

		$this->assertStringNotContainsString('BackfillCmsPortalReference', $install[1]);
		$this->assertStringContainsString('BackfillCmsPortalReference', $post[1]);
	}//end testItIsNotRegisteredOnInstall()
}//end class
