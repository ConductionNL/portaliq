<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\Portaliq\Service\Cms\PageHistory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * site-page-seo-history-and-media REQ-SPH-003: the published versions of a
 * page, newest first, with who and when, read from OpenRegister's audit trail
 * of the page object. Built on the REAL AuditTrail entity, so the `changed`
 * shape is OpenRegister's own ({field: {old, new}}).
 *
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
class PageHistoryTest extends TestCase {

	private const PAGE_SCHEMA = 41;

	private const PAGE = 'a1b2c3d4-0000-4000-8000-000000000001';

	public function testEveryPublishedBodyIsAVersionNewestFirst(): void {
		$friday = ['type' => 'grid', 'widgets' => [['id' => 'w1', 'widgetKey' => 'text', 'props' => ['text' => 'Friday']]]];
		$monday = ['type' => 'grid', 'widgets' => [['id' => 'w1', 'widgetKey' => 'text', 'props' => ['text' => 'Monday']]]];

		$versions = $this->history(rows: [
			$this->row(id: 12, action: 'update', changed: ['body' => ['old' => $monday, 'new' => $friday]], when: '2026-09-25 10:00:00', user: 'anna', name: 'Anna de Vries'),
			$this->row(id: 7, action: 'create', changed: ['title' => ['old' => null, 'new' => 'Contact'], 'body' => ['old' => null, 'new' => $monday]], when: '2026-09-21 09:00:00', user: 'bram', name: 'Bram'),
		])->versions(pageId: self::PAGE);

		$this->assertCount(2, $versions);
		$this->assertSame(12, $versions[0]['id']);
		$this->assertSame('Anna de Vries', $versions[0]['by']);
		$this->assertSame('2026-09-25T10:00:00+00:00', $versions[0]['publishedAt']);
		$this->assertTrue($versions[0]['restorable']);
		$this->assertSame($friday, $versions[0]['body']);
		$this->assertSame($monday, $versions[1]['body']);
	}//end testEveryPublishedBodyIsAVersionNewestFirst()

	public function testADraftSaveIsNotAVersion(): void {
		$versions = $this->history(rows: [
			$this->row(id: 13, action: 'update', changed: ['draftBody' => ['old' => null, 'new' => ['type' => 'grid', 'widgets' => []]]], when: '2026-09-26 10:00:00', user: 'anna', name: 'Anna'),
		])->versions(pageId: self::PAGE);

		$this->assertSame([], $versions);
	}//end testADraftSaveIsNotAVersion()

	public function testAPublicationWithoutItsBodyShowsWithoutARestore(): void {
		$versions = $this->history(rows: [
			$this->row(id: 9, action: 'update', changed: ['status' => ['old' => 'draft', 'new' => 'published']], when: '2026-09-22 08:00:00', user: 'anna', name: ''),
		])->versions(pageId: self::PAGE);

		$this->assertCount(1, $versions);
		$this->assertFalse($versions[0]['restorable']);
		$this->assertNull($versions[0]['body']);
		$this->assertSame('anna', $versions[0]['by']);
	}//end testAPublicationWithoutItsBodyShowsWithoutARestore()

	public function testAnotherSchemasObjectLendsNoHistory(): void {
		$row = $this->row(id: 3, action: 'update', changed: ['body' => ['old' => null, 'new' => ['secret' => 'x']]], when: '2026-09-22 08:00:00', user: 'x', name: 'X');
		$row->setSchema(99);

		$this->assertSame([], $this->history(rows: [$row])->versions(pageId: self::PAGE));
	}//end testAnotherSchemasObjectLendsNoHistory()

	public function testWithoutOpenRegisterThereIsNoHistory(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no openregister'));

		$this->assertSame([], (new PageHistory($container, new NullLogger()))->versions(pageId: self::PAGE));
	}//end testWithoutOpenRegisterThereIsNoHistory()

	public function testOnlyThePagesCreatesAndUpdatesAreRead(): void {
		$trail = $this->getMockBuilder(AuditTrailMapper::class)->disableOriginalConstructor()->onlyMethods(['findForObjectByAction'])->getMock();
		$trail->expects($this->once())
			->method('findForObjectByAction')
			->with($this->equalTo(self::PAGE), $this->equalTo(['create', 'update']), $this->greaterThan(0))
			->willReturn([]);

		$this->build(trail: $trail)->versions(pageId: self::PAGE);
	}//end testOnlyThePagesCreatesAndUpdatesAreRead()

	/**
	 * A history over audit rows.
	 *
	 * @param array<AuditTrail> $rows The rows the mapper returns, newest first.
	 *
	 * @return PageHistory
	 */
	private function history(array $rows): PageHistory {
		$trail = $this->getMockBuilder(AuditTrailMapper::class)->disableOriginalConstructor()->onlyMethods(['findForObjectByAction'])->getMock();
		$trail->method('findForObjectByAction')->willReturn($rows);

		return $this->build(trail: $trail);
	}//end history()

	/**
	 * The history over a trail mapper and the real page schema entity.
	 *
	 * @param AuditTrailMapper $trail The trail mapper.
	 *
	 * @return PageHistory
	 */
	private function build(AuditTrailMapper $trail): PageHistory {
		$schema = new Schema();
		$schema->setId(self::PAGE_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('page', 'portaliq')->willReturn($schema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				AuditTrailMapper::class => $trail,
				SchemaMapper::class => $schemas,
			}
		);

		return new PageHistory($container, new NullLogger());
	}//end build()

	/**
	 * One real audit row of the page.
	 *
	 * @param int                  $id      The row id.
	 * @param string               $action  create or update.
	 * @param array<string, mixed> $changed OpenRegister's per-field diff.
	 * @param string               $when    UTC timestamp.
	 * @param string               $user    The uid.
	 * @param string               $name    The display name.
	 *
	 * @return AuditTrail
	 */
	private function row(int $id, string $action, array $changed, string $when, string $user, string $name): AuditTrail {
		$row = new AuditTrail();
		$row->setId($id);
		$row->setSchema(self::PAGE_SCHEMA);
		$row->setObjectUuid(self::PAGE);
		$row->setAction($action);
		$row->setChanged($changed);
		$row->setUser($user);
		$row->setUserName($name);
		$row->setCreated(new DateTime($when, new \DateTimeZone('UTC')));

		return $row;
	}//end row()
}//end class
