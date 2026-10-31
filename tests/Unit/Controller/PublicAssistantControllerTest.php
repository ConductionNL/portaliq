<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PublicAssistantController;
use OCA\Portaliq\Service\Assistant\PublicAssistantChannel;
use OCA\Portaliq\Service\Assistant\PublicSourceScope;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * search-assistant-from-public-content REQ-SAP-001, REQ-SAP-002, REQ-SAP-006.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t04
 */
class PublicAssistantControllerTest extends TestCase {

	private MockObject $channel;

	private function controller(string $authorization='', ?array $site=['slug' => 'z', 'assistant' => ['enabled' => true]]): PublicAssistantController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($authorization);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn($site);
		$this->channel = $this->createMock(PublicAssistantChannel::class);

		return new PublicAssistantController($request, $portals, new PublicSourceScope(), $this->channel);
	}

	public function testBearerIsRefused(): void {
		$controller = $this->controller(authorization: 'Bearer abc');
		$this->channel->expects($this->never())->method('ask');

		$response = $controller->ask(question: 'Hallo');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'bearer_not_accepted'], $response->getData());

	}//end testBearerIsRefused()

	public function testAPortalWithTheAssistantOffForwardsNothing(): void {
		$controller = $this->controller(site: ['slug' => 'z', 'assistant' => ['enabled' => false]]);
		$this->channel->expects($this->never())->method('ask');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->ask(question: 'Hallo')->getStatus());

	}//end testAPortalWithTheAssistantOffForwardsNothing()

	public function testAnEmptyQuestionIsRefused(): void {
		$controller = $this->controller();
		$this->channel->expects($this->never())->method('ask');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->ask(question: '  ')->getStatus());

	}//end testAnEmptyQuestionIsRefused()

	public function testAnswerWithoutSourceBecomesAbstention(): void {
		$controller = $this->controller();
		$this->channel->method('ask')->willReturn(['status' => 'abstained', 'answer' => null, 'sources' => [], 'removed' => false, 'conversationId' => '']);

		$data = $controller->ask(question: 'Hoeveel kost een paspoort in Mars?')->getData();

		$this->assertSame('abstained', $data['status']);
		$this->assertNull($data['answer']);

	}//end testAnswerWithoutSourceBecomesAbstention()

	public function testAnAnswerCarriesItsSources(): void {
		$controller = $this->controller();
		$this->channel->method('ask')->willReturn(['status' => 'answered', 'answer' => 'Kort.', 'sources' => [['title' => 'Afval', 'url' => '/afval', 'type' => 'page']], 'removed' => false, 'conversationId' => 'c']);

		$response = $controller->ask(question: 'Afval?');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('/afval', $response->getData()['sources'][0]['url']);

	}//end testAnAnswerCarriesItsSources()

	public function testWithoutHermiqTheRouteIsUnavailable(): void {
		$controller = $this->controller();
		$this->channel->method('ask')->willReturn(['status' => 'unavailable', 'answer' => null, 'sources' => [], 'removed' => false, 'conversationId' => '']);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->ask(question: 'Hallo')->getStatus());

	}//end testWithoutHermiqTheRouteIsUnavailable()
}//end class
