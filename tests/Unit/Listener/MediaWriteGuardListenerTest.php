<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\Portaliq\Listener\MediaWriteGuardListener;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * site-page-seo-history-and-media T06, T09 (REQ-SPH-004, REQ-SPH-005): an
 * image in the media library needs alternative text, and an item a published
 * page uses cannot be deleted; the refusal names the pages. Built on
 * OpenRegister's REAL events and entities, so the veto is the one its mapper
 * honours (stopPropagation plus a message).
 *
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
class MediaWriteGuardListenerTest extends TestCase {

	private const MEDIA_SCHEMA = 77;

	public function testAnImageWithoutAlternativeTextIsRefused(): void {
		$event = new ObjectCreatingEvent($this->item(['kind' => 'image', 'alt' => '  ']));

		$this->listener(pages: [])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('An image in the media library needs alternative text.', $event->getErrors()['message']);
	}//end testAnImageWithoutAlternativeTextIsRefused()

	public function testAnImageWithAlternativeTextAndAFileWithoutOnePass(): void {
		$image = new ObjectUpdatingEvent($this->item(['kind' => 'image', 'alt' => 'Het stadhuis']));
		$file  = new ObjectCreatingEvent($this->item(['kind' => 'file']));

		$this->listener(pages: [])->handle($image);
		$this->listener(pages: [])->handle($file);

		$this->assertFalse($image->isPropagationStopped());
		$this->assertFalse($file->isPropagationStopped());
	}//end testAnImageWithAlternativeTextAndAFileWithoutOnePass()

	public function testAUsedItemIsNotDeletedAndThePagesAreNamed(): void {
		$event = new ObjectDeletingEvent($this->item(['kind' => 'image', 'alt' => 'Het stadhuis']));

		$this->listener(pages: ['/about', '/contact'])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('This item is used on published pages: /about, /contact. Remove it there first.', $event->getErrors()['message']);
	}//end testAUsedItemIsNotDeletedAndThePagesAreNamed()

	public function testAnUnusedItemIsDeleted(): void {
		$event = new ObjectDeletingEvent($this->item(['kind' => 'image', 'alt' => 'x']));

		$this->listener(pages: [])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnusedItemIsDeleted()

	public function testAnotherSchemasObjectIsLeftAlone(): void {
		$entity = $this->item(['kind' => 'image']);
		$entity->setSchema('12');
		$event = new ObjectCreatingEvent($entity);

		$this->listener(pages: ['/x'])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasObjectIsLeftAlone()

	/**
	 * A media item as OpenRegister hands it to a hook.
	 *
	 * @param array<string, mixed> $data The item's fields.
	 *
	 * @return ObjectEntity
	 */
	private function item(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('m1');
		$entity->setSchema((string)self::MEDIA_SCHEMA);
		$entity->setObject($data + ['portal' => 'gemeente', 'title' => 'Stadhuis', 'status' => 'published']);

		return $entity;
	}//end item()

	/**
	 * The listener over a reader naming the pages that use m1 on gemeente.
	 *
	 * @param list<string> $pages The routes.
	 *
	 * @return MediaWriteGuardListener
	 */
	private function listener(array $pages): MediaWriteGuardListener {
		$schema = new Schema();
		$schema->setId(self::MEDIA_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('media', 'portaliq')->willReturn($schema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		$reader = $this->getMockBuilder(MediaLibraryReader::class)->disableOriginalConstructor()->onlyMethods(['pagesUsing'])->getMock();
		$reader->method('pagesUsing')->willReturnCallback(
			static fn (string $portal, string $id) => ($portal === 'gemeente' && $id === 'm1') ? $pages : []
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []) => vsprintf($text, $params)
		);

		return new MediaWriteGuardListener($container, $reader, $l10n, new NullLogger());
	}//end listener()
}//end class
