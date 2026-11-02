<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\CollectionDocuments;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalCaseDocumentReader;
use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for decidiq's provider: the `opened` hook answers what the test sets.
 */
class FakeOpenedProvider {

	/**
	 * What the hook answers, or an exception to throw.
	 *
	 * @var mixed
	 */
	public mixed $answer = true;

	/**
	 * What the hook was called with, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * The hook.
	 *
	 * @param string $objectId The object.
	 * @param string $documentId The document.
	 * @param array<string, string> $subject The session's subject.
	 *
	 * @return mixed
	 */
	public function confidentialPaperOpened(string $objectId, string $documentId, array $subject): mixed {
		$this->calls[] = [$objectId, $documentId, $subject];
		if ($this->answer instanceof RuntimeException) {
			throw $this->answer;
		}

		return $this->answer;
	}
}

/**
 * site-member-voting-record-and-confidential-papers REQ-SCR-002, REQ-SCR-003:
 * the order of an open, and a hook that fails closed.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */
class CollectionDocumentsTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 'bsn-1', 'trust' => 'substantial', 'identityType' => 'digid', 'audience' => 'citizen', 'organisation' => 'org'];

	private const DOCUMENTS = ['label' => 'Stukken', 'provider' => 'papers', 'opened' => 'confidentialPaperOpened'];

	private const OBJECT = ['register' => 'decidiq', 'schema' => 'agendaItem', 'id' => 'a1'];

	private FakeOpenedProvider $provider;

	private MockObject $files;

	private MockObject $audit;

	private function service(bool $listed=true): CollectionDocuments {
		$this->provider = new FakeOpenedProvider();
		$published      = $this->createMock(PortalCaseDocumentReader::class);
		$published->method('entries')->willReturn($listed === true ? [['id' => 'd1', 'title' => 'Taxatierapport', 'kind' => 'document', 'date' => '', 'file' => ['register' => 'r', 'schema' => 's', 'id' => 'o', 'fileId' => 'f1']]] : []);
		$this->files = $this->createMock(PortalFileReader::class);
		$this->files->method('streamFile')->willReturn($this->createMock(StreamResponse::class));
		$this->audit = $this->createMock(PortalAuditHook::class);
		$locator     = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($this->provider);

		return new CollectionDocuments($published, $this->files, $this->audit, $locator, $this->createMock(LoggerInterface::class));
	}

	public function testTheListNeverSaysWhereAFileLives(): void {
		$entries = $this->service()->listFor(app: 'decidiq', documents: self::DOCUMENTS, id: 'a1');

		$this->assertSame('Taxatierapport', $entries[0]['title']);
		$this->assertArrayNotHasKey('file', $entries[0]);

	}//end testTheListNeverSaysWhereAFileLives()

	public function testTheOpenedHookRunsBeforeTheStream(): void {
		$service = $this->service();
		$this->audit->expects($this->once())->method('download');

		$result = $service->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd1');

		$this->assertInstanceOf(StreamResponse::class, $result);
		$this->assertSame([['a1', 'd1', ['subjectRef' => 'bsn-1', 'trust' => 'substantial', 'identityType' => 'digid', 'audience' => 'citizen']]], $this->provider->calls);

	}//end testTheOpenedHookRunsBeforeTheStream()

	public function testAFailedHookStreamsNothing(): void {
		foreach ([false, null, 'true', 1, new RuntimeException('down')] as $answer) {
			$service = $this->service();
			$this->provider->answer = $answer;
			$this->files->expects($this->never())->method('streamFile');
			$this->audit->expects($this->never())->method('download');

			$result = $service->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd1');

			$this->assertSame(CollectionDocuments::REFUSED, $result, 'hook answer '.get_debug_type($answer));
		}

	}//end testAFailedHookStreamsNothing()

	public function testAnUnlistedDocumentIsNotFoundAndTheHookIsNotCalled(): void {
		$service = $this->service();

		$this->assertSame(CollectionDocuments::NOT_FOUND, $service->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd9'));
		$this->assertSame([], $this->provider->calls);

		$empty = $this->service(listed: false);
		$this->assertSame(CollectionDocuments::NOT_FOUND, $empty->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd1'));

	}//end testAnUnlistedDocumentIsNotFoundAndTheHookIsNotCalled()

	public function testWithoutAHookTheDocumentOpens(): void {
		$service = $this->service();

		$result = $service->open(subject: self::SUBJECT, app: 'decidiq', documents: ['provider' => 'papers'], object: self::OBJECT, documentId: 'd1');

		$this->assertInstanceOf(StreamResponse::class, $result);
		$this->assertSame([], $this->provider->calls);

	}//end testWithoutAHookTheDocumentOpens()

	public function testAHookThatCannotBeCalledRefuses(): void {
		$service = $this->service();

		$result = $service->open(subject: self::SUBJECT, app: 'decidiq', documents: ['provider' => 'papers', 'opened' => 'getContribution'], object: self::OBJECT, documentId: 'd1');

		$this->assertSame(CollectionDocuments::REFUSED, $result);

	}//end testAHookThatCannotBeCalledRefuses()
}//end class
