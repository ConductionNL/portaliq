<?php

/**
 * An item of an app's public index on a page of its own: the answer held to
 * its shape, found only through the index, and the shared 404 otherwise
 * (public-detail-page-for-a-provider-item).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/public-detail-page-for-a-provider-item/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicDetailShape;
use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicDetailTest extends TestCase {

	public function testTheAnswerIsHeldToItsShape(): void {
		$detail = (new PublicDetailShape())->detail([
			'title' => ' <b>F-gassen</b> ',
			'facts' => [['label' => 'Certificaat', 'value' => 'Ja'], ['label' => 'Leeg', 'value' => ''], 'junk'],
			'sections' => [['heading' => 'Programma', 'markdown' => "## Ochtend\n\nTheorie"], ['heading' => 'Zonder tekst', 'markdown' => ' ']],
			'dates' => [
				['id' => 'd1', 'date' => '2026-10-22', 'places' => 7, 'placesLine' => 'Op deze dag zijn 7 plekken vrij'],
				['id' => 'd2', 'date' => 'someday'],
				['id' => 'd3', 'date' => '2026-11-05', 'places' => -2],
			],
			'documents' => [['label' => 'Brochure', 'href' => '/media/1'], ['label' => 'Evil', 'href' => 'javascript:alert(1)'], ['label' => 'Ook evil', 'href' => '//evil.example']],
			'secondaryLink' => ['label' => 'Incompany aanvragen', 'href' => 'https://example.org/incompany'],
			'action' => ['id' => 'enrol', 'label' => 'Inschrijven', 'countLabel' => 'Aantal deelnemers', 'countMax' => 500],
			'secret' => 'bsn',
		]);

		$this->assertSame('F-gassen', $detail['title']);
		$this->assertSame([['label' => 'Certificaat', 'value' => 'Ja']], $detail['facts']);
		$this->assertCount(1, $detail['sections']);
		$this->assertSame(['d1', 'd3'], array_column($detail['dates'], 'id'));
		$this->assertSame(7, $detail['dates'][0]['places']);
		$this->assertArrayNotHasKey('places', $detail['dates'][1], 'a negative number of places is dropped');
		$this->assertSame([['label' => 'Brochure', 'href' => '/media/1']], $detail['documents']);
		$this->assertSame('https://example.org/incompany', $detail['secondaryLink']['href']);
		$this->assertTrue($detail['action']['requiresSignIn'], 'an action asks for sign-in unless the app says otherwise');
		$this->assertSame(20, $detail['action']['countMax']);
		$this->assertArrayNotHasKey('secret', $detail);
		$this->assertNull((new PublicDetailShape())->detail('nope'));
	}

	public function testAnItemIsFoundOnlyThroughTheIndex(): void {
		$controller = $this->controller();

		$ok = $controller->detail('warmtepompacademie', 'learniq', 'course', 'f-gassen-herhaling-en-examen');
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame('Certificaat', $ok->getData()['detail']['facts'][0]['label']);
		$this->assertSame('learniq:c1', $ok->getData()['item']['id']);

		foreach ([
			['learniq', 'course', 'onbekend'],
			['learniq', 'programme', 'f-gassen-herhaling-en-examen'],
			['other', 'course', 'f-gassen-herhaling-en-examen'],
			['learniq', 'course', 'zonder-komende-dag'],
			['learniq', 'course', ''],
		] as [$app, $kind, $slug]) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $controller->detail('warmtepompacademie', $app, $kind, $slug)->getStatus(), "$app/$kind/$slug");
		}
	}

	private function controller(): ContentCatalogueController {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['learniq']);

		$provider = new class () {
			public function getPublicIndex(string $portal): array {
				return [
					['id' => 'c1', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'F-gassen', 'slug' => 'f-gassen-herhaling-en-examen', 'date' => '2026-10-22'],
					['id' => 'c2', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'Zonder detail', 'slug' => 'zonder-komende-dag'],
				];
			}

			public function getPublicDetail(string $portal, string $id): ?array {
				return ($id === 'c1') ? ['facts' => [['label' => 'Certificaat', 'value' => 'Ja']]] : null;
			}
		};
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($provider);
		$reader = $this->createMock(PublicNewsReader::class);
		$reader->method('allFor')->willReturn([]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$catalogue = new PublicCatalogue($apps, $locator, $reader, $caches, $this->createMock(LoggerInterface::class));

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn(['slug' => 'warmtepompacademie', 'authentication' => ['modes' => ['public']]]);

		return new ContentCatalogueController('portaliq', $request, $resolver, $this->createMock(PortalSessionService::class), $catalogue);
	}
}
