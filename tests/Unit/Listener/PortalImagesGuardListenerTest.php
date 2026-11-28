<?php

/**
 * PortalImagesGuardListenerTest
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

	/**
	 * The portal schema's id in these tests.
	 */
	private const PORTAL_SCHEMA = 41;

	/**
	 * A refusal from the image rules stops the save with its words.
	 *
	 * @return void
	 */
	public function testARefusalStopsTheSave(): void {
		if (class_exists(class: ObjectCreatingEvent::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not installed in this environment.');
		}

		$event  = new ObjectCreatingEvent(object: $this->portal(data: ['logo' => 'huge.png']));
		$images = $this->createMock(originalClassName: PortalImageChoiceInterface::class);
		$images->expects($this->once())->method('refusal')
			->with($this->callback(callback: static fn (array $portal): bool => $portal['slug'] === 'hhnk' && $portal['logo'] === 'huge.png'))
			->willReturn('The logo is too large.');

		$this->listener(images: $images)->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'The logo is too large.', actual: $event->getErrors()['message']);
	}//end testARefusalStopsTheSave()

	/**
	 * An update the image rules accept is saved.
	 *
	 * @return void
	 */
	public function testAnUpdateWithinTheRulesIsSaved(): void {
		if (class_exists(class: ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not installed in this environment.');
		}

		$event  = new ObjectUpdatingEvent(newObject: $this->portal(data: ['logo' => 'small.png']), oldObject: $this->portal(data: []));
		$images = $this->createMock(originalClassName: PortalImageChoiceInterface::class);
		$images->expects($this->once())->method('refusal')
			->with($this->callback(callback: static fn (array $portal): bool => $portal['slug'] === 'hhnk' && $portal['logo'] === 'small.png'))
			->willReturn(null);

		$this->listener(images: $images)->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUpdateWithinTheRulesIsSaved()

	/**
	 * An object of another schema is not checked.
	 *
	 * @return void
	 */
	public function testAnotherSchemasObjectIsLeftAlone(): void {
		if (class_exists(class: ObjectEntity::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not installed in this environment.');
		}

		$entity = $this->portal(data: ['logo' => 'huge.png']);
		$entity->setSchema('12');
		$images = $this->createMock(originalClassName: PortalImageChoiceInterface::class);
		$images->expects($this->never())->method('refusal');
		$event = new ObjectCreatingEvent(object: $entity);

		$this->listener(images: $images)->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
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
	 * The guard over the given image rules and a container whose schema
	 * mapper knows the portal schema.
	 *
	 * @param PortalImageChoiceInterface $images The image rules.
	 *
	 * @return PortalImagesGuardListener
	 */
	private function listener(PortalImageChoiceInterface $images): PortalImagesGuardListener {
		$schema = new Schema();
		$schema->setId(self::PORTAL_SCHEMA);
		$schemas = $this->getMockBuilder(className: SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByApplicationAndSlug'])
			->getMock();
		$schemas->method('findByApplicationAndSlug')->with('portal', 'portaliq')->willReturn($schema);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		return new PortalImagesGuardListener(container: $container, images: $images, logger: new NullLogger());
	}//end listener()
}//end class
