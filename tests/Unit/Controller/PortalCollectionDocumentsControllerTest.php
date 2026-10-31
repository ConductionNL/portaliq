<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalCollectionDocumentsController;
use OCA\Portaliq\Service\CollectionDocuments;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * site-member-voting-record-and-confidential-papers REQ-SCR-002, REQ-SCR-003:
 * a collection below the session's trust, an object outside the scope and an
 * unlisted document are each 404, a refused hook is 503.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */
class PortalCollectionDocumentsControllerTest extends TestCase {

	private MockObject $documents;

	private MockObject $reader;

	/**
	 * Build the controller over a subject, an aggregate and a scoped read.
	 *
	 * @param array<string, mixed>|null $subject The session's subject, or null for none.
	 * @param array<int, array<string, mixed>> $collections The subject's collections.
	 * @param array<string, mixed>|null $owned What the scoped read returns.
	 *
	 * @return PortalCollectionDocumentsController
	 */
	private function controller(?array $subject, array $collections, ?array $owned=['id' => 'a1']): PortalCollectionDocumentsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer x');
		$request->method('getParam')->willReturn('');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'decidiq', 'collections' => $collections]]]);
		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->reader->method('readObject')->willReturn($owned);
		$this->documents = $this->createMock(CollectionDocuments::class);
		$l10n            = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PortalCollectionDocumentsController($request, $registry, $session, $this->reader, $this->documents, $l10n);
	}

	private function collection(string $minTrust='substantial'): array {
		return ['id' => 'confidentialAgendaItems', 'register' => 'decidiq', 'schema' => 'agendaItem', 'minTrust' => $minTrust, 'documents' => ['label' => 'Stukken', 'provider' => 'papers']];
	}

	public function testWithoutASessionTheAnswerIs401(): void {
		$controller = $this->controller(subject: null, collections: [$this->collection()]);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->open('decidiq', 'agendaItem', 'a1', 'd1')->getStatus());

	}//end testWithoutASessionTheAnswerIs401()

	public function testACollectionBelowTheSessionTrustIsNotFound(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'p', 'trust' => 'low'], collections: [$this->collection('substantial')]);
		$this->documents->expects($this->never())->method('open');
		$this->reader->expects($this->never())->method('readObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->open('decidiq', 'agendaItem', 'a1', 'd1')->getStatus());

	}//end testACollectionBelowTheSessionTrustIsNotFound()

	public function testACollectionOutsideTheAggregateOrWithoutDocumentsIsNotFound(): void {
		$subject = ['subjectRef' => 'p', 'trust' => 'substantial'];
		$none    = $this->controller(subject: $subject, collections: []);
		$this->assertSame(Http::STATUS_NOT_FOUND, $none->list('decidiq', 'agendaItem', 'a1')->getStatus());

		$bare = $this->controller(subject: $subject, collections: [['register' => 'decidiq', 'schema' => 'agendaItem']]);
		$this->assertSame(Http::STATUS_NOT_FOUND, $bare->list('decidiq', 'agendaItem', 'a1')->getStatus());

	}//end testACollectionOutsideTheAggregateOrWithoutDocumentsIsNotFound()

	public function testAnObjectOutsideTheScopeIsNotFound(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'other', 'trust' => 'substantial'], collections: [$this->collection()], owned: null);
		$this->documents->expects($this->never())->method('open');
		$this->documents->expects($this->never())->method('listFor');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->open('decidiq', 'agendaItem', 'a1', 'd1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->list('decidiq', 'agendaItem', 'a1')->getStatus());

	}//end testAnObjectOutsideTheScopeIsNotFound()

	public function testAFailedHookIs503WithTheSentenceAndAnUnlistedPaperIs404(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'p', 'trust' => 'substantial'], collections: [$this->collection()]);
		$this->documents->method('open')->willReturnOnConsecutiveCalls(CollectionDocuments::REFUSED, CollectionDocuments::NOT_FOUND);

		$refused = $controller->open('decidiq', 'agendaItem', 'a1', 'd1');
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $refused->getStatus());
		$this->assertSame('The paper cannot be opened right now', $refused->getData()['message']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->open('decidiq', 'agendaItem', 'a1', 'd9')->getStatus());

	}//end testAFailedHookIs503WithTheSentenceAndAnUnlistedPaperIs404()

	public function testTheNamedReaderGetsTheListAndTheFile(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'p', 'trust' => 'substantial'], collections: [$this->collection()]);
		$stream = $this->createMock(StreamResponse::class);
		$this->documents->method('open')->willReturn($stream);
		$this->documents->method('listFor')->willReturn([['id' => 'd1', 'title' => 'Taxatierapport']]);

		$this->assertSame($stream, $controller->open('decidiq', 'agendaItem', 'a1', 'd1'));
		$data = $controller->list('decidiq', 'agendaItem', 'a1')->getData();
		$this->assertSame('Stukken', $data['label']);
		$this->assertSame('d1', $data['documents'][0]['id']);

	}//end testTheNamedReaderGetsTheListAndTheFile()
}//end class
