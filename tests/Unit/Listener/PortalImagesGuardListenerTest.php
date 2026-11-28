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
use OCA\Portaliq\Kernel\Contracts\PortalImageChoiceInterface;
use OCA\Portaliq\Listener\PortalImagesGuardListener;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The portal images guard refuses a portal write whose images break a rule,
 * on OpenRegister's real creating and updating events, and leaves other
 * schemas' objects alone.
 *
 * @covers \OCA\Portaliq\Listener\PortalImagesGuardListener
 *
 * @spec openspec/specs/portaliq-cms/spec.md
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t12
 */
final class PortalImagesGuardListenerTest extends TestCase {

	private const PORTAL_SCHEMA = 41;

	public function testARefusalStopsTheSave(): void {
		if (class_exists(ObjectCreatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$event  = new ObjectCreatingEvent($this->portal(['logo' => 'huge.png']));
		$images = $this->createMock(PortalImageChoiceInterface::class);
		$images->expects($this->once())->method('refusal')
			->with($this->callback(static fn (array $portal): bool => $portal['slug'] === 'hhnk' && $portal['logo'] === 'huge.png'))
			->willReturn('The logo is too large.');

		(new PortalImagesGuardListener($this->container(), $images, new NullLogger()))->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('The logo is too large.', $event->getErrors()['message']);
	}//end testARefusalStopsTheSave()

	public function testAnUpdateWithinTheRulesIsSaved(): void {
		if (class_exists(ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$new    = $this->portal(['logo' => 'small.png']);
		$event  = new ObjectUpdatingEvent($new, $this->portal([]));
		$images = $this->createMock(PortalImageChoiceInterface::class);
		$images->expects($this->once())->method('refusal')
			->with($this->callback(static fn (array $portal): bool => $portal['slug'] === 'hhnk' && $portal['logo'] === 'small.png'))
			->willReturn(null);

		(new PortalImagesGuardListener($this->container(), $images, new NullLogger()))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUpdateWithinTheRulesIsSaved()

	public function testAnotherSchemasObjectIsLeftAlone(): void {
		if (class_exists(ObjectEntity::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$entity = $this->portal(['logo' => 'huge.png']);
		$entity->setSchema('12');
		$images = $this->createMock(PortalImageChoiceInterface::class);
		$images->expects($this->never())->method('refusal');
		$event = new ObjectCreatingEvent($entity);

		(new PortalImagesGuardListener($this->container(), $images, new NullLogger()))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasObjectIsLeftAlone()

	/**
	 * A portal as OpenRegister hands it to a hook.
	 *
	 * @param array<string, mixed> $data The portal's fields.
	 *
	 * @return ObjectEntity
	 */
	private function portal(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p1');
		$entity->setSchema((string)self::PORTAL_SCHEMA);
		$entity->setObject(['slug' => 'hhnk'] + $data);

		return $entity;
	}//end portal()

	/**
	 * A container whose schema mapper knows the portal schema.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$schema = new Schema();
		$schema->setId(self::PORTAL_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('portal', 'portaliq')->willReturn($schema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		return $container;
	}//end container()
}//end class
