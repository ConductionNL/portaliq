<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalCollectionDocumentsController;
use OCA\Portaliq\Service\CollectionDocuments;
use OCA\Portaliq\Service\DocumentOpening;
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
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($key === 'version' ? 'v2' : '')
		);
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

	/**
	 * The bundle is proven like a paper: no session 401, outside the scope
	 * 404, no bundle 404, else the stream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
	 */
	public function testTheBundleIsProvenLikeAPaper(): void {
		$noSession = $this->controller(subject: null, collections: [$this->collection()]);
		$refusal   = $noSession->bundle(register: 'decidiq', schema: 'agendaItem', id: 'a1');
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $refusal->getStatus());

		$other   = ['subjectRef' => 'other', 'trust' => 'substantial'];
		$outside = $this->controller(subject: $other, collections: [$this->collection()], owned: null);
		$this->documents->expects($this->never())->method('bundle');
		$refused = $outside->bundle(register: 'decidiq', schema: 'agendaItem', id: 'a1');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $refused->getStatus());

		$controller = $this->controller(subject: ['subjectRef' => 'p', 'trust' => 'substantial'], collections: [$this->collection()]);
		$stream     = $this->createMock(originalClassName: StreamResponse::class);
		$this->documents->method('bundle')->willReturnOnConsecutiveCalls(null, $stream);
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->bundle(register: 'decidiq', schema: 'agendaItem', id: 'a1')->getStatus());
		$this->assertSame(expected: $stream, actual: $controller->bundle(register: 'decidiq', schema: 'agendaItem', id: 'a1'));
	}//end testTheBundleIsProvenLikeAPaper()

	/**
	 * document-download-terms task 2: the terms are proven like a paper and answered as the app gives them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-download-terms/tasks.md#task-2
	 */
	public function testTheTermsAreProvenLikeAPaper(): void {
		$noSession = $this->controller(subject: null, collections: [$this->collection()]);
		$refusal   = $noSession->terms(register: 'decidiq', schema: 'agendaItem', id: 'a1', documentId: 'd1');
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $refusal->getStatus());

		$other   = ['subjectRef' => 'other', 'trust' => 'substantial'];
		$outside = $this->controller(subject: $other, collections: [$this->collection()], owned: null);
		$this->documents->expects($this->never())->method('terms');
		$refused = $outside->terms(register: 'decidiq', schema: 'agendaItem', id: 'a1', documentId: 'd1');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $refused->getStatus());
	}

	/**
	 * document-download-terms task 2: the owner reads the terms of a listed paper.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-download-terms/tasks.md#task-2
	 */
	public function testTheOwnerReadsTheTerms(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'p', 'trust' => 'substantial'], collections: [$this->collection()]);
		$terms      = ['mayDownload' => false, 'agreement' => ['version' => 'v2', 'text' => 'No reuse']];
		$this->documents->expects($this->once())->method('terms')->with(
			$this->callback(
				static fn (DocumentOpening $opening): bool => $opening->app === 'decidiq'
					&& $opening->documentId === 'd1'
					&& $opening->object === ['register' => 'decidiq', 'schema' => 'agendaItem', 'id' => 'a1']
					&& $opening->subject['subjectRef'] === 'p'
			)
		)->willReturn($terms);

		$answer = $controller->terms(register: 'decidiq', schema: 'agendaItem', id: 'a1', documentId: 'd1');
		$this->assertSame(expected: ['terms' => $terms], actual: $answer->getData());
	}

	/**
	 * document-download-terms task 2: accepting passes the body's version and answers whether it was recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-download-terms/tasks.md#task-2
	 */
	public function testAcceptingAnswersWhetherItWasRecorded(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'p', 'trust' => 'substantial'], collections: [$this->collection()]);
		$this->documents->method('accept')->with($this->isInstanceOf(DocumentOpening::class), 'v2')->willReturnOnConsecutiveCalls(true, false);

		$accepted = $controller->acceptTerms(register: 'decidiq', schema: 'agendaItem', id: 'a1', documentId: 'd1');
		$this->assertSame(expected: ['accepted' => true], actual: $accepted->getData());

		$refused = $controller->acceptTerms(register: 'decidiq', schema: 'agendaItem', id: 'a1', documentId: 'd1');
		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $refused->getStatus());
		$this->assertFalse(condition: $refused->getData()['accepted']);
	}

	/**
	 * document-download-terms task 2: an acceptance outside the scope never reaches the app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-download-terms/tasks.md#task-2
	 */
	public function testAnAcceptanceOutsideTheScopeIsNotFound(): void {
		$other   = ['subjectRef' => 'other', 'trust' => 'substantial'];
		$outside = $this->controller(subject: $other, collections: [$this->collection()], owned: null);
		$this->documents->expects($this->never())->method('accept');

		$answer = $outside->acceptTerms(register: 'decidiq', schema: 'agendaItem', id: 'a1', documentId: 'd1');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $answer->getStatus());
	}
}//end class
