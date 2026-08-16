<?php

/**
 * CmsCacheInvalidationListenerTest
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
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Portaliq\Listener\CmsCacheInvalidationListener;
use OCA\Portaliq\Service\CmsReader;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The listener that keeps a published page from 404ing for a TTL.
 *
 * Its whole job is a side effect, so the assertions are about whether
 * `invalidate()` is called and with what — and, just as importantly, about the
 * cases where it must NOT be called, because an over-eager invalidation is a
 * silent performance regression rather than a visible bug.
 */
class CmsCacheInvalidationListenerTest extends TestCase {

	/**
	 * The reader whose cache is dropped.
	 *
	 * @var CmsReader&MockObject
	 */
	private CmsReader $reader;

	/**
	 * The listener under test.
	 *
	 * @var CmsCacheInvalidationListener
	 */
	private CmsCacheInvalidationListener $listener;


	/**
	 * Build the listener with doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->reader = $this->createMock(CmsReader::class);
		$this->listener = new CmsCacheInvalidationListener(
			reader: $this->reader,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()


	/**
	 * Build an ObjectEntity double returning the given data.
	 *
	 * @param array $data The object's data.
	 *
	 * @return ObjectEntity&MockObject The double.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);

		return $entity;
	}//end entity()


	/**
	 * A content write invalidates that portal.
	 *
	 * @return void
	 */
	public function testACreatedContentObjectInvalidatesItsPortal(): void {
		$this->reader->expects($this->once())
			->method('invalidate')
			->with(portal: 'open-tilburg');

		$this->listener->handle(new ObjectCreatedEvent($this->entity(data: ['portal' => 'open-tilburg'])));
	}//end testACreatedContentObjectInvalidatesItsPortal()


	/**
	 * An update invalidates too.
	 *
	 * @return void
	 */
	public function testAnUpdatedContentObjectInvalidatesItsPortal(): void {
		$this->reader->expects($this->once())->method('invalidate')->with(portal: 'open-venray');

		$this->listener->handle(new ObjectUpdatedEvent($this->entity(data: ['portal' => 'open-venray'])));
	}//end testAnUpdatedContentObjectInvalidatesItsPortal()


	/**
	 * A delete invalidates too.
	 *
	 * Easy to forget, and the case that matters most: an unpublished page that
	 * stays in cache is content still being served after someone removed it.
	 *
	 * @return void
	 */
	public function testADeletedContentObjectInvalidatesItsPortal(): void {
		$this->reader->expects($this->once())->method('invalidate')->with(portal: 'open-tilburg');

		$this->listener->handle(new ObjectDeletedEvent($this->entity(data: ['portal' => 'open-tilburg'])));
	}//end testADeletedContentObjectInvalidatesItsPortal()


	/**
	 * A portal object invalidates itself, keyed by its own slug.
	 *
	 * A `portal` has no `portal` property — it IS one — so without the slug
	 * fallback, editing a site's own theme or title would never clear its
	 * cached presentation.
	 *
	 * @return void
	 */
	public function testAPortalObjectInvalidatesItselfByItsSlug(): void {
		$this->reader->expects($this->once())->method('invalidate')->with(portal: 'open-tilburg');

		$this->listener->handle(new ObjectUpdatedEvent($this->entity(data: ['slug' => 'open-tilburg'])));
	}//end testAPortalObjectInvalidatesItselfByItsSlug()


	/**
	 * An object belonging to no portal invalidates nothing.
	 *
	 * OpenRegister emits these events for EVERY object in the instance, most of
	 * which have nothing to do with the CMS. Invalidating on all of them would
	 * make the cache useless while looking perfectly correct.
	 *
	 * @return void
	 */
	public function testAnUnrelatedObjectInvalidatesNothing(): void {
		$this->reader->expects($this->never())->method('invalidate');

		$this->listener->handle(new ObjectUpdatedEvent($this->entity(data: ['title' => 'not content'])));
	}//end testAnUnrelatedObjectInvalidatesNothing()


	/**
	 * An event this listener does not handle is ignored.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$this->reader->expects($this->never())->method('invalidate');

		$this->listener->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()


	/**
	 * A failure in invalidation must never break the write.
	 *
	 * A stale cache is a nuisance. A save that throws because a cache drop
	 * failed is data loss, and it would surface to the editor as "saving does
	 * not work" with no hint that caching is involved.
	 *
	 * @return void
	 */
	public function testAnInvalidationFailureDoesNotBreakTheWrite(): void {
		$this->reader->method('invalidate')->willThrowException(new RuntimeException('cache down'));

		$this->listener->handle(new ObjectCreatedEvent($this->entity(data: ['portal' => 'open-tilburg'])));

		// Reaching here without an exception IS the assertion.
		$this->addToAssertionCount(1);
	}//end testAnInvalidationFailureDoesNotBreakTheWrite()


	/**
	 * The declared CMS schemas match the ones the reader caches.
	 *
	 * Pins the list so it cannot drift from the reader's own coverage without
	 * something noticing.
	 *
	 * @return void
	 */
	public function testTheDeclaredCmsSchemasAreTheCachedOnes(): void {
		$this->assertSame(
			['portal', 'menu', 'page', 'glossaryTerm'],
			CmsCacheInvalidationListener::cmsSchemas()
		);
	}//end testTheDeclaredCmsSchemasAreTheCachedOnes()


}//end class
