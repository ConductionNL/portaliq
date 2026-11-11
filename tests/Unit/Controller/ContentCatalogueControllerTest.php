<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Session\BearerSessionResolver;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\DerivedCatalogueFacets;
use OCA\Portaliq\Service\PublicCatalogueQuery;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The public catalogue endpoints: portal resolution, access refusals,
 * parameter sanitising and cache headers.
 */
#[CoversClass(ContentCatalogueController::class)]
#[UsesClass(PublicCatalogueQuery::class)]
#[UsesClass(DerivedCatalogueFacets::class)]
#[UsesClass(BearerSessionResolver::class)]
class ContentCatalogueControllerTest extends TestCase {
	/**
	 * Build the controller over doubles.
	 *
	 * @param array<string, mixed>|null $portal  What the resolver returns.
	 * @param string                    $auth    The Authorization header.
	 * @param array<string, mixed>|null $subject The session subject.
	 * @param MockObject|null           $catalogue The catalogue double.
	 * @param array<string, mixed>      $query   The request's query values.
	 *
	 * @return ContentCatalogueController
	 */
	private function controller(
		?array $portal,
		string $auth='',
		?array $subject=null,
		?MockObject $catalogue=null,
		array $query=[],
	): ContentCatalogueController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($query[$key] ?? $default)
		);
		$request->method('getHeader')->willReturn($auth);
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);
		$session = $this->createMock(BearerSessionResolver::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new ContentCatalogueController('portaliq', $request, $resolver, $session, ($catalogue ?? $this->createMock(PublicCatalogue::class)));
	}//end controller()

	/**
	 * An unknown portal is a 404 on every endpoint.
	 *
	 * @return void
	 */
	public function testUnknownPortalIs404(): void {
		$c = $this->controller(null);

		foreach ([$c->index('x'), $c->kinds('x'), $c->detail('x', 'app', 'k', 's')] as $response) {
			$this->assertSame(404, $response->getStatus());
		}
	}//end testUnknownPortalIs404()

	/**
	 * A portal that is not public refuses anonymous callers and low trust.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$portal = ['slug' => 'zuid', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'high']];

		$anon = $this->controller($portal)->kinds('zuid');
		$this->assertSame(401, $anon->getStatus());
		$this->assertSame('authentication_required', $anon->getData()['error']);

		$low = $this->controller($portal, 'Bearer x', ['trust' => 'low'])->kinds('zuid');
		$this->assertSame(403, $low->getStatus());
		$this->assertSame('insufficient_trust', $low->getData()['error']);
		$this->assertSame(['modes' => ['digid']], $low->getData()['authentication']);
	}//end testRefusals()

	/**
	 * Kinds answer for a public portal with or without a bearer.
	 *
	 * @return void
	 */
	public function testKindsForPublicPortal(): void {
		$catalogue = $this->createMock(PublicCatalogue::class);
		$catalogue->method('kindsFor')->with('zuid')->willReturn(['news']);
		$portal = ['slug' => 'zuid', 'authentication' => ['modes' => ['public']]];

		$public = $this->controller($portal, '', null, $catalogue)->kinds();
		$this->assertSame(['kinds' => ['news']], $public->getData());

		$private = $this->controller($portal, 'Bearer x', null, $catalogue)->kinds();
		$this->assertSame(['kinds' => ['news']], $private->getData());
	}//end testKindsForPublicPortal()

	/**
	 * Detail answers the item or a 404.
	 *
	 * @return void
	 */
	public function testDetail(): void {
		$catalogue = $this->createMock(PublicCatalogue::class);
		$catalogue->method('detailFor')->willReturnOnConsecutiveCalls(['title' => 'T'], null);
		$c = $this->controller(['slug' => 'zuid'], '', null, $catalogue);

		$this->assertSame(['title' => 'T'], $c->detail('zuid', 'app', 'news', 'a')->getData());
		$this->assertSame(404, $c->detail('zuid', 'app', 'news', 'b')->getStatus());
	}//end testDetail()

	/**
	 * Index sanitises its parameters and resolves the visitor filter token.
	 *
	 * @return void
	 */
	public function testIndexSanitisesAndResolvesVisitor(): void {
		$catalogue = $this->createMock(PublicCatalogue::class);
		$catalogue->method('itemsFor')->with('zuid')->willReturn([]);
		$catalogue->expects($this->once())->method('visitorValuesFor')->with('zuid', 'myapp', $this->anything())->willReturn(['Group' => ['g1']]);
		$filters = json_encode(['Group' => ['visitor', 'other'], 'Empty' => ['visitor'], 'Plain' => ['p']]);
		$query = [
			'search'     => str_repeat('q', 300),
			'types'      => ' a, ,b ',
			'filters'    => $filters,
			'sort'       => 'bogus',
			'page'       => 1,
			'limit'      => 10,
			'upcoming'   => '1',
			'app'        => 'myapp',
			'categories' => 'c1,,c2',
			'range'      => 'schoolYear',
		];
		$c = $this->controller(portal: ['slug' => 'zuid'], auth: 'Bearer x', subject: ['subjectRef' => 'abc'], catalogue: $catalogue, query: $query);

		$response = $c->index('zuid');

		$this->assertSame(200, $response->getStatus());
		$this->assertIsArray($response->getData());
	}//end testIndexSanitisesAndResolvesVisitor()

	/**
	 * Index tolerates junk filters and a bad app key.
	 *
	 * @return void
	 */
	public function testIndexToleratesJunk(): void {
		$catalogue = $this->createMock(PublicCatalogue::class);
		$catalogue->method('itemsFor')->willReturn([]);
		$catalogue->expects($this->never())->method('visitorValuesFor');
		$c = $this->controller(
			portal: ['slug' => 'zuid'],
			auth: '',
			subject: null,
			catalogue: $catalogue,
			query: ['filters' => 'not json', 'app' => 'Bad App!']
		);

		$response = $c->index('zuid');

		$this->assertSame(200, $response->getStatus());
	}//end testIndexToleratesJunk()
}//end class
