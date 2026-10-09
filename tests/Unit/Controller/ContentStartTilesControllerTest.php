<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContentStartTilesController;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * site-nlds-widget-palette T10: the public start tiles endpoint answers
 * label, summary, audiences and route only, for a portal that exists.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */
class ContentStartTilesControllerTest extends TestCase {
	/**
	 * The controller over a registry answering `$tiles`.
	 *
	 * @param array<int, array<string, mixed>> $tiles The registry's tiles.
	 * @param bool $portalExists Whether the portal resolves.
	 *
	 * @return ContentStartTilesController
	 */
	private function controller(array $tiles, bool $portalExists = true): ContentStartTilesController {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('startTiles')->willReturn($tiles);
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portalExists === true ? ['slug' => 'zuiddrecht'] : null);

		return new ContentStartTilesController($this->createMock(IRequest::class), $registry, $resolver);
	}//end controller()

	/**
	 * Only the four tile keys leave, whatever the registry adds.
	 *
	 * @return void
	 */
	public function testTheAnswerCarriesOnlyTheTileKeys(): void {
		$response = $this->controller(
			[['label' => 'Bezwaar maken', 'summary' => 'Maak bezwaar.', 'audiences' => ['citizen'], 'route' => '/mijn/dossiq/bezwaar', 'endpoint' => '/apps/dossiq/x', 'fields' => ['a']]]
		)->index('zuiddrecht');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['tiles' => [['label' => 'Bezwaar maken', 'summary' => 'Maak bezwaar.', 'audiences' => ['citizen'], 'route' => '/mijn/dossiq/bezwaar']]],
			$response->getData()
		);
		// getHeaders() needs a running Nextcloud; the header set is read directly.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertStringContainsString('public', (string)$headers['Cache-Control']);
	}//end testTheAnswerCarriesOnlyTheTileKeys()

	/**
	 * An unknown portal is a 404.
	 *
	 * @return void
	 */
	public function testAnUnknownPortalIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller([], false)->index('nergens')->getStatus());
	}//end testAnUnknownPortalIsNotFound()

	/**
	 * Readable signed out, without CSRF, rate limited.
	 *
	 * @return void
	 */
	public function testTheEndpointIsPublicAndRateLimited(): void {
		$method = new ReflectionMethod(ContentStartTilesController::class, 'index');
		foreach ([PublicPage::class, NoCSRFRequired::class, AnonRateLimit::class] as $attribute) {
			$this->assertNotEmpty($method->getAttributes($attribute), $attribute);
		}
	}//end testTheEndpointIsPublicAndRateLimited()
}//end class
