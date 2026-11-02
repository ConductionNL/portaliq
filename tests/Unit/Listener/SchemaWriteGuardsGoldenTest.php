<?php

/**
 * SchemaWriteGuardsGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Listener
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

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Listener\BindingDestinationGuardListener;
use OCA\Portaliq\Listener\GlossaryRelationGuardListener;
use OCA\Portaliq\Listener\MediaWriteGuardListener;
use OCA\Portaliq\Listener\NoticeWriteGuardListener;
use OCA\Portaliq\Service\Cms\GlossaryRelations;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The four pre-write guards answer every event kind, schema and schema
 * lookup outcome as recorded: which writes they refuse, with what errors,
 * and what they log and look up.
 *
 * @covers \OCA\Portaliq\Listener\BindingDestinationGuardListener
 * @covers \OCA\Portaliq\Listener\GlossaryRelationGuardListener
 * @covers \OCA\Portaliq\Listener\MediaWriteGuardListener
 * @covers \OCA\Portaliq\Listener\NoticeWriteGuardListener
 * @covers \OCA\Portaliq\Listener\SchemaWriteGuard
 */
class SchemaWriteGuardsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/schema-write-guards.json';

	/**
	 * The schema id the mapper answers with.
	 */
	private const GUARDED = 81;

	/**
	 * What the guards logged and looked up so far.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every guard answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryGuardAnswersAsRecorded(): void {
		if (class_exists(ObjectCreatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not loadable: set PORTALIQ_OPENREGISTER_LIB');
		}

		$actual = [];
		foreach (['binding', 'glossary', 'notice', 'media'] as $guard) {
			foreach (['found', 'noId', 'none', 'throws'] as $lookup) {
				foreach (['81' => '81', '82' => '82', 'empty' => ''] as $schemaName => $schema) {
					foreach (['creating', 'updating', 'deleting', 'other'] as $kind) {
						$this->log = [];
						$event = $this->event(kind: $kind, schema: $schema);
						$this->guard(guard: $guard, lookup: $lookup)->handle($event);
						$actual[$guard][$lookup][$schemaName][$kind] = [
							'stopped' => $event->isPropagationStopped(),
							'errors' => method_exists($event, 'getErrors') ? $event->getErrors() : null,
							'log' => $this->log,
						];
					}
				}
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryGuardAnswersAsRecorded()

	/**
	 * One event of a kind over an object every guard would refuse.
	 *
	 * @param string $kind   creating, updating, deleting or other.
	 * @param string $schema The object's schema id.
	 *
	 * @return Event
	 */
	private function event(string $kind, string $schema): Event {
		$entity = new ObjectEntity();
		$entity->setUuid('m1');
		$entity->setSchema($schema);
		$entity->setObject(['status' => 'published', 'portal' => '', 'relations' => ['t-9', ['nested']], 'kind' => 'image', 'alt' => ' ']);

		return match ($kind) {
			'creating' => new ObjectCreatingEvent($entity),
			'updating' => new ObjectUpdatingEvent($entity, new ObjectEntity()),
			'deleting' => new ObjectDeletingEvent($entity),
			default => new Event(),
		};
	}//end event()

	/**
	 * A guard whose schema lookup ends one way.
	 *
	 * @param string $guard  Which guard.
	 * @param string $lookup found, noId, none or throws.
	 *
	 * @return IEventListener<Event>
	 */
	private function guard(string $guard, string $lookup): IEventListener {
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->willReturnCallback(function (string $slug, string $application) use ($lookup): ?Schema {
			$this->log[] = ['lookup' => [$slug, $application]];
			if ($lookup === 'throws') {
				throw new RuntimeException('mapper down');
			}

			if ($lookup === 'none') {
				return null;
			}

			$schema = new Schema();
			if ($lookup === 'found') {
				$schema->setId(self::GUARDED);
			}

			return $schema;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($schemas): SchemaMapper {
			$this->log[] = ['get' => $id];

			return $schemas;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->log[] = ['warning' => [$message, $context]];
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return match ($guard) {
			'binding' => new BindingDestinationGuardListener($container, $this->bindings(), $this->intake(), $l10n, $logger),
			'glossary' => new GlossaryRelationGuardListener($container, $this->createMock(CmsReader::class), new GlossaryRelations(), $l10n, $logger),
			'notice' => new NoticeWriteGuardListener($container, $l10n, $logger),
			default => new MediaWriteGuardListener($container, $this->library(), $l10n, $logger),
		};
	}//end guard()

	/**
	 * A binding resolver that renders nothing.
	 *
	 * @return PortalFormBindingResolver
	 */
	private function bindings(): PortalFormBindingResolver {
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('render')->willReturn(['fields' => []]);

		return $bindings;
	}//end bindings()

	/**
	 * A destination check that always has one finding.
	 *
	 * @return DestinationIntake
	 */
	private function intake(): DestinationIntake {
		$intake = $this->createMock(DestinationIntake::class);
		$intake->method('check')->willReturnCallback(function (array $binding, array $render): array {
			$this->log[] = ['check' => $render];

			return [['field' => 'name']];
		});

		return $intake;
	}//end intake()

	/**
	 * A media library where the item is used on one page.
	 *
	 * @return MediaLibraryReader
	 */
	private function library(): MediaLibraryReader {
		$library = $this->getMockBuilder(MediaLibraryReader::class)->disableOriginalConstructor()->onlyMethods(['pagesUsing'])->getMock();
		$library->method('pagesUsing')->willReturnCallback(function (string $portal, string $id): array {
			$this->log[] = ['pagesUsing' => [$portal, $id]];

			return ['/over'];
		});

		return $library;
	}//end library()
}//end class
