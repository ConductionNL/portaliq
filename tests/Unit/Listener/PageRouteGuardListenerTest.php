<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Kernel\Contracts\CmsReaderInterface;
use OCA\Portaliq\Listener\PageRouteGuardListener;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * portal-cms-admin-ui: two pages on one website with the same route, and
 * the second is refused when it is saved. Checked with the real OpenRegister
 * event classes; the route rule itself is PageRouteClaimTest.
 *
 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
 */
class PageRouteGuardListenerTest extends TestCase {

	private const PAGE_SCHEMA = 64;

	/**
	 * The guard listens on the creating and the updating event.
	 *
	 * @return void
	 */
	public function testTheGuardIsRegisteredOnTheCreatingAndUpdatingEvents(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Listener/ListenerRegistrar.php');

		$this->assertMatchesRegularExpression(
			'/ObjectCreatingEvent::class, ObjectUpdatingEvent::class\] as \$event\) \{\s+\$context->registerEventListener\(\$event, PageRouteGuardListener::class\)/',
			$source
		);
	}

	/**
	 * The second page on a route is refused with the sentence naming it.
	 *
	 * @return void
	 */
	public function testTheSecondPageOnARouteIsRefused(): void {
		if (class_exists(ObjectCreatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$event = new ObjectCreatingEvent($this->page('p-new', ['route' => '/over-ons']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Another page of this portal already has the route /over-ons.', $event->getErrors()['message']);
	}

	/**
	 * Saving the page that holds the route again passes.
	 *
	 * @return void
	 */
	public function testTheSamePageSavedAgainPasses(): void {
		if (class_exists(ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$event = new ObjectUpdatingEvent($this->page('p-about', ['route' => '/over-ons', 'title' => 'Over ons (nieuw)']));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * Another schema's object is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemasObjectIsLeftAlone(): void {
		if (class_exists(ObjectEntity::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$entity = $this->page('p-new', ['route' => '/over-ons']);
		$entity->setSchema('12');
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * A page entity of the guarded schema.
	 *
	 * @param string $uuid The page's uuid.
	 * @param array  $data The page's own fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function page(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema((string)self::PAGE_SCHEMA);
		$entity->setObject($data + ['portal' => 'open-tilburg', 'title' => 'Pagina', 'status' => 'published']);

		return $entity;
	}

	/**
	 * The listener over portaliq's page schema, with one stored page on /over-ons.
	 *
	 * @return PageRouteGuardListener The listener.
	 */
	private function listener(): PageRouteGuardListener {
		$schema = new Schema();
		$schema->setId(self::PAGE_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('page', 'portaliq')->willReturn($schema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		$reader = $this->createMock(CmsReaderInterface::class);
		$reader->method('rowsForCheck')->with('open-tilburg', 'page')
			->willReturn([['id' => 'p-about', 'route' => '/over-ons', 'status' => 'published']]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new PageRouteGuardListener($container, $reader, $l10n, new NullLogger());
	}
}
