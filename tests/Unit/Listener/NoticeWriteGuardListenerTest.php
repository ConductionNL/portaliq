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
use OCA\Portaliq\Listener\NoticeWriteGuardListener;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * operate-maintenance-notice T05 (REQ-OMN-003): a notice's end is after its
 * start, checked on the write with the real OpenRegister event classes.
 *
 * @spec openspec/specs/portal-notices/spec.md#requirement-page-editors-manage-notices-req-omn-003
 */
class NoticeWriteGuardListenerTest extends TestCase {

	private const NOTICE_SCHEMA = 81;


	/**
	 * An end before the start is refused with the editor's sentence.
	 *
	 * @return void
	 */
	public function testAnEndBeforeTheStartIsRefused(): void {
		$event = new ObjectCreatingEvent($this->notice(['startsAt' => '2026-10-03T22:00:00+02:00', 'endsAt' => '2026-10-03T21:00:00+02:00']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('The end must be after the start.', $event->getErrors()['message']);
	}//end testAnEndBeforeTheStartIsRefused()


	/**
	 * An end equal to the start, or a missing start, is refused on update too.
	 *
	 * @return void
	 */
	public function testAnEmptyWindowIsRefusedOnUpdate(): void {
		$same    = new ObjectUpdatingEvent($this->notice(['startsAt' => '2026-10-03T22:00:00+02:00', 'endsAt' => '2026-10-03T20:00:00+00:00']));
		$noStart = new ObjectUpdatingEvent($this->notice(['endsAt' => '2026-10-04T02:00:00+02:00']));

		$this->listener()->handle($same);
		$this->listener()->handle($noStart);

		$this->assertTrue($same->isPropagationStopped(), '22:00 in Amsterdam is 20:00 UTC: the same moment');
		$this->assertTrue($noStart->isPropagationStopped());
	}//end testAnEmptyWindowIsRefusedOnUpdate()


	/**
	 * A real window passes.
	 *
	 * @return void
	 */
	public function testAWindowPasses(): void {
		$event = new ObjectCreatingEvent($this->notice(['startsAt' => '2026-10-03T22:00:00+02:00', 'endsAt' => '2026-10-04T02:00:00+02:00']));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAWindowPasses()


	/**
	 * Another schema's object is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemasObjectIsLeftAlone(): void {
		$entity = $this->notice(['endsAt' => 'x']);
		$entity->setSchema('12');
		$event = new ObjectCreatingEvent($entity);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasObjectIsLeftAlone()


	/**
	 * A notice entity.
	 *
	 * @param array $data The notice's own fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function notice(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('n1');
		$entity->setSchema((string)self::NOTICE_SCHEMA);
		$entity->setObject($data + ['portal' => 'gemeente', 'message' => 'Onderhoud', 'level' => 'warning', 'surfaces' => ['site'], 'status' => 'published']);

		return $entity;
	}//end notice()


	/**
	 * The listener over portaliq's notice schema.
	 *
	 * @return NoticeWriteGuardListener The listener.
	 */
	private function listener(): NoticeWriteGuardListener {
		$schema = new Schema();
		$schema->setId(self::NOTICE_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('portalNotice', 'portaliq')->willReturn($schema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new NoticeWriteGuardListener($container, $l10n, new NullLogger());
	}//end listener()
}//end class
