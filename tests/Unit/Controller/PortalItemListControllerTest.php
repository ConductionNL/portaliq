<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalTimelineController;
use OCA\Portaliq\Service\PortalItemReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTimelineReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A dossier's items for its owner (my-dossiers D2), served by
 * `PortalTimelineController::items()` with the same ownership proof as the
 * history.
 *
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */
class PortalItemListControllerTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'sub-1', 'audience' => 'client', 'organisation' => '', 'trust' => 'substantial'];

	private const COLLECTION = [
		'id' => 'mijnDossiers',
		'register' => 'opencatalogi',
		'schema' => 'collection',
		'scopeField' => 'owner',
		'itemList' => ['label' => 'In dit dossier', 'provider' => 'dossierItems', 'removeAction' => 'removeCollectionItem'],
	];

	private const ITEMS = [
		['id' => 'i1', 'title' => 'Besluit fietspad', 'url' => '/index.php/apps/opencatalogi/api/search/p1', 'note' => 'Lees p. 3', 'public' => true, 'addedAt' => ''],
		['id' => 'i2', 'title' => 'Inventaris', 'url' => '', 'note' => 'Niet vergeten', 'public' => false, 'addedAt' => ''],
	];

	/**
	 * The item reader double.
	 *
	 * @var PortalItemReader&MockObject
	 */
	private PortalItemReader $items;

	/**
	 * Own object, items returned.
	 *
	 * @return void
	 */
	public function testOwnObjectItemsReturned(): void {
		$controller = $this->controller(owned: ['id' => 'dos-1']);
		$this->items->expects($this->once())->method('items')->with('opencatalogi', 'dossierItems', 'dos-1')->willReturn(self::ITEMS);

		$response = $controller->items(register: 'opencatalogi', schema: 'collection', id: 'dos-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['label' => 'In dit dossier', 'items' => self::ITEMS, 'removeAction' => 'removeCollectionItem'], $response->getData());
	}//end testOwnObjectItemsReturned()

	/**
	 * Foreign object, provider not called.
	 *
	 * @return void
	 */
	public function testForeignObjectProviderNotCalled(): void {
		$controller = $this->controller(owned: null);
		$this->items->expects($this->never())->method('items');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->items(register: 'opencatalogi', schema: 'collection', id: 'dos-2')->getStatus());
	}//end testForeignObjectProviderNotCalled()

	/**
	 * No session is 401; a collection without an item list is 404; a failed
	 * read is 502, not an empty dossier.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(owned: ['id' => 'dos-1'], subject: null)->items('opencatalogi', 'collection', 'dos-1')->getStatus());

		$bare = self::COLLECTION;
		unset($bare['itemList']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(owned: ['id' => 'dos-1'], collection: $bare)->items('opencatalogi', 'collection', 'dos-1')->getStatus());

		$controller = $this->controller(owned: ['id' => 'dos-1']);
		$this->items->method('items')->willReturn(null);
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->items('opencatalogi', 'collection', 'dos-1')->getStatus());
	}//end testRefusals()

	/**
	 * The controller over doubles.
	 *
	 * @param array<string, mixed>|null $owned      What the scoped read returns.
	 * @param array<string, mixed>|null $subject    The resolved subject.
	 * @param array<string, mixed>      $collection The collection.
	 *
	 * @return PortalTimelineController
	 */
	private function controller(?array $owned, ?array $subject = self::SUBJECT, array $collection = self::COLLECTION): PortalTimelineController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'collection' ? 'mijnDossiers' : $default));

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)->disableOriginalConstructor()->onlyMethods(['aggregateFor'])->getMock();
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'opencatalogi', 'collections' => [$collection]]]]);

		$session = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['resolveFromBearer'])->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);

		$reader = $this->getMockBuilder(PortalObjectReader::class)->disableOriginalConstructor()->onlyMethods(['readObject'])->getMock();
		$reader->method('readObject')->willReturn($owned);

		$timelines = $this->getMockBuilder(PortalTimelineReader::class)->disableOriginalConstructor()->onlyMethods(['entries'])->getMock();
		$this->items = $this->getMockBuilder(PortalItemReader::class)->disableOriginalConstructor()->onlyMethods(['items'])->getMock();

		return new PortalTimelineController($request, $registry, $session, $reader, $timelines, $this->items);
	}//end controller()
}//end class
