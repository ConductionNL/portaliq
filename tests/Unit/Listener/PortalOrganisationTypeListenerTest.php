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
use OCA\Portaliq\Listener\PortalOrganisationTypeListener;
use OCA\Portaliq\Service\OrganisationTypeOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * portal-identity-from-the-admin 3.1 (REQ-PIA-003): a portal's organisation
 * type is settled on OpenRegister's REAL creating and updating events, so the
 * veto is the one its mapper honours and the label lands on the saved object.
 *
 * @covers \OCA\Portaliq\Listener\PortalOrganisationTypeListener
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */
final class PortalOrganisationTypeListenerTest extends TestCase {

	private const PORTAL_SCHEMA = 41;

	public function testARefusalStopsTheSave(): void {
		if (class_exists(ObjectCreatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$event = new ObjectCreatingEvent($this->portal(['organisationType' => 'Waterschap']));
		$this->listener(settled: ['refusal' => 'Choose the organisation type from the TOOI list.', 'portal' => []])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Choose the organisation type from the TOOI list.', $event->getErrors()['message']);
	}//end testARefusalStopsTheSave()

	public function testTheSettledPortalIsSavedAndTheOldOneIsHandedOver(): void {
		if (class_exists(ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$new   = $this->portal(['organisationType' => 'https://x/Waterschap']);
		$old   = $this->portal(['organisationType' => 'https://x/Gemeente']);
		$event = new ObjectUpdatingEvent($new, $old);
		$types = $this->createMock(OrganisationTypeOptions::class);
		$types->expects($this->once())->method('settle')
			->with(['slug' => 'hhnk', 'organisationType' => 'https://x/Waterschap'], ['slug' => 'hhnk', 'organisationType' => 'https://x/Gemeente'])
			->willReturn(['refusal' => null, 'portal' => ['slug' => 'hhnk', 'organisationType' => 'https://x/Waterschap', 'organisationTypeLabel' => 'waterschap']]);

		(new PortalOrganisationTypeListener($this->container(), $types, new NullLogger()))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('waterschap', $new->getObject()['organisationTypeLabel']);
	}//end testTheSettledPortalIsSavedAndTheOldOneIsHandedOver()

	public function testAnotherSchemasObjectIsLeftAlone(): void {
		if (class_exists(ObjectEntity::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$entity = $this->portal(['organisationType' => 'Waterschap']);
		$entity->setSchema('12');
		$types = $this->createMock(OrganisationTypeOptions::class);
		$types->expects($this->never())->method('settle');

		(new PortalOrganisationTypeListener($this->container(), $types, new NullLogger()))->handle(new ObjectCreatingEvent($entity));
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

	/**
	 * The listener over rules that settle every portal as `$settled`.
	 *
	 * @param array{refusal: string|null, portal: array<string, mixed>} $settled The outcome.
	 *
	 * @return PortalOrganisationTypeListener
	 */
	private function listener(array $settled): PortalOrganisationTypeListener {
		$types = $this->createMock(OrganisationTypeOptions::class);
		$types->method('settle')->willReturn($settled);

		return new PortalOrganisationTypeListener($this->container(), $types, new NullLogger());
	}//end listener()
}//end class
