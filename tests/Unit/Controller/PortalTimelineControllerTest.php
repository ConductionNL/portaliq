<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalTimelineController;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTimelineReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * portaliq#723: a case's history reaches the portal only after the subject
 * has proven, through the same scoped read as a single object, that the case
 * is theirs. A foreign or absent case is one 404 and the provider is never
 * asked.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */
class PortalTimelineControllerTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 'bsn:999993653', 'audience' => 'citizen', 'organisation' => '', 'trust' => 'substantial'];

	private const COLLECTION = [
		'id' => 'mijnZaken',
		'register' => 'dossiq',
		'schema' => 'case',
		'scopeField' => 'portalSubject',
		'minTrust' => 'low',
		'fields' => ['identifier', 'title'],
		'timeline' => ['label' => 'Wat er is gebeurd', 'provider' => 'caseTimeline'],
	];

	private const ENTRIES = [['id' => 'e1', 'message' => 'In behandeling', 'occurredAt' => '2026-09-20T10:00:00+00:00']];

	/**
	 * The doubles of the last controller built.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testTheOwnersCaseHistoryIsServedUnderItsLabel(): void {
		$controller = $this->controller();
		$this->doubles['timelines']->expects($this->once())
			->method('entries')
			->with($this->equalTo('dossiq'), $this->equalTo('caseTimeline'), $this->equalTo('case-1'))
			->willReturn(self::ENTRIES);

		$response = $controller->show(register: 'dossiq', schema: 'case', id: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['label' => 'Wat er is gebeurd', 'entries' => self::ENTRIES], $response->getData());

	}//end testTheOwnersCaseHistoryIsServedUnderItsLabel()

	public function testTheScopedReadRunsWithTheCollectionsOwnScope(): void {
		$controller = $this->controller();
		$this->doubles['reader']->expects($this->once())
			->method('readObject')
			->with(
				$this->equalTo('dossiq'),
				$this->equalTo('case'),
				$this->equalTo('portalSubject'),
				$this->equalTo('bsn:999993653'),
				$this->equalTo('case-1')
			)
			->willReturn(['id' => 'case-1']);
		$this->doubles['timelines']->method('entries')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $controller->show(register: 'dossiq', schema: 'case', id: 'case-1')->getStatus());

	}//end testTheScopedReadRunsWithTheCollectionsOwnScope()

	public function testAForeignOrAbsentCaseIsOne404AndTheProviderIsNeverAsked(): void {
		$controller = $this->controller(owned: null);
		$this->doubles['timelines']->expects($this->never())->method('entries');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show(register: 'dossiq', schema: 'case', id: 'someone-elses')->getStatus());

	}//end testAForeignOrAbsentCaseIsOne404AndTheProviderIsNeverAsked()

	public function testNoSessionIsA401(): void {
		$controller = $this->controller(subject: null);
		$this->doubles['timelines']->expects($this->never())->method('entries');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show(register: 'dossiq', schema: 'case', id: 'case-1')->getStatus());

	}//end testNoSessionIsA401()

	public function testACollectionOutsideTheSubjectsContributionsIsA403(): void {
		$controller = $this->controller();
		$this->doubles['reader']->expects($this->never())->method('readObject');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->show(register: 'elders', schema: 'case', id: 'case-1')->getStatus());

	}//end testACollectionOutsideTheSubjectsContributionsIsA403()

	public function testATrustBelowTheCollectionsIsA403BeforeAnyRead(): void {
		$controller = $this->controller(collection: ['minTrust' => 'high'] + self::COLLECTION);
		$this->doubles['reader']->expects($this->never())->method('readObject');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->show(register: 'dossiq', schema: 'case', id: 'case-1')->getStatus());

	}//end testATrustBelowTheCollectionsIsA403BeforeAnyRead()

	public function testACollectionThatDeclaresNoTimelineHasNone(): void {
		$collection = self::COLLECTION;
		unset($collection['timeline']);
		$controller = $this->controller(collection: $collection);
		$this->doubles['reader']->expects($this->never())->method('readObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show(register: 'dossiq', schema: 'case', id: 'case-1')->getStatus());

	}//end testACollectionThatDeclaresNoTimelineHasNone()

	public function testATimelineThatCouldNotBeReadIsA502NotAnEmptyHistory(): void {
		$controller = $this->controller();
		$this->doubles['timelines']->method('entries')->willReturn(null);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->show(register: 'dossiq', schema: 'case', id: 'case-1')->getStatus());

	}//end testATimelineThatCouldNotBeReadIsA502NotAnEmptyHistory()

	/**
	 * The controller over doubles.
	 *
	 * @param array<string, mixed>|null $subject The subject behind the bearer.
	 * @param array<string, mixed> $collection The collection the subject may read.
	 * @param array<string, mixed>|null $owned What the scoped read finds.
	 *
	 * @return PortalTimelineController
	 */
	private function controller(?array $subject = self::SUBJECT, array $collection = self::COLLECTION, ?array $owned = ['id' => 'case-1']): PortalTimelineController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'collection' ? 'mijnZaken' : $default));

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)->disableOriginalConstructor()->onlyMethods(['aggregateFor'])->getMock();
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'collections' => [$collection]]]]);

		$session = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['resolveFromBearer'])->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);

		$reader = $this->getMockBuilder(PortalObjectReader::class)->disableOriginalConstructor()->onlyMethods(['readObject'])->getMock();
		$reader->method('readObject')->willReturn($owned);

		$timelines = $this->getMockBuilder(PortalTimelineReader::class)->disableOriginalConstructor()->onlyMethods(['entries'])->getMock();

		$this->doubles = ['reader' => $reader, 'timelines' => $timelines];

		return new PortalTimelineController($request, $registry, $session, $reader, $timelines);

	}//end controller()

}//end class
