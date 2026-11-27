<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\CollectionDocuments;
use OCA\Portaliq\Service\DocumentOpening;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalCaseDocumentReader;
use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\Portaliq\Contribution\TimelineProviderMethod;

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

	/**
	 * What the terms hook answers, or an exception to throw.
	 *
	 * @var mixed
	 */
	public mixed $terms = null;

	/**
	 * The terms hook.
	 *
	 * @param string                $objectId   The object.
	 * @param string                $documentId The document.
	 * @param array<string, string> $subject    The session's subject.
	 *
	 * @return mixed
	 */
	public function paperTerms(string $objectId, string $documentId, array $subject): mixed {
		$this->calls[] = ['terms', $objectId, $documentId, $subject];
		if ($this->terms instanceof RuntimeException) {
			throw $this->terms;
		}

		return $this->terms;
	}

	/**
	 * The accept hook: answers $answer.
	 *
	 * @param string                $objectId   The object.
	 * @param string                $documentId The document.
	 * @param array<string, string> $subject    The session's subject.
	 * @param string                $version    The version shown.
	 *
	 * @return mixed
	 */
	public function paperAccept(string $objectId, string $documentId, array $subject, string $version): mixed {
		$this->calls[] = ['accept', $objectId, $documentId, $subject, $version];
		if ($this->answer instanceof RuntimeException) {
			throw $this->answer;
		}

		return $this->answer;
	}

	private function privateHook(string $objectId, string $documentId, array $subject): bool {
		return true;
	}

	public static function staticHook(string $objectId, string $documentId, array $subject): bool {
		return true;
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

	private const TERMS = ['provider' => 'papers', 'opened' => 'confidentialPaperOpened', 'terms' => 'paperTerms', 'accept' => 'paperAccept'];

	private const BUNDLED = ['provider' => 'certificates', 'bundle' => 'validCertificates'];

	private FakeOpenedProvider $provider;

	private MockObject $files;

	private MockObject $audit;

	/**
	 * The warnings logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	private function service(bool $listed=true): CollectionDocuments {
		$this->provider = new FakeOpenedProvider();
		$published      = $this->createMock(PortalCaseDocumentReader::class);
		$published->method('entries')->willReturn($listed === true ? [['id' => 'd1', 'title' => 'Taxatierapport', 'kind' => 'document', 'date' => '', 'file' => ['register' => 'r', 'schema' => 's', 'id' => 'o', 'fileId' => 'f1']]] : []);
		$this->files = $this->createMock(PortalFileReader::class);
		$this->files->method('streamFile')->willReturn($this->createMock(StreamResponse::class));
		$this->audit = $this->createMock(PortalAuditHook::class);
		$locator     = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($this->provider);

		$this->warnings = [];
		$logger         = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
			$this->warnings[] = $message . ' ' . json_encode($context);
		});

		return new CollectionDocuments($published, $this->files, $this->audit, $locator, $logger, new TimelineProviderMethod());
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

	/**
	 * The download is recorded for the session's subject and the proven
	 * object; a file that cannot be streamed is not found and not recorded;
	 * each failed hook logs why.
	 *
	 * @return void
	 */
	public function testTheAuditAndTheHookWarningsAreAsRecorded(): void {
		$service = $this->service();
		$this->audit->expects($this->once())->method('download')->with('bsn-1', 'org', 'decidiq', 'agendaItem', 'a1');
		$service->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd1');
		$this->assertSame([], $this->warnings);

		$gone = $this->service();
		$this->files = $this->createMock(PortalFileReader::class);
		$gone        = new CollectionDocuments(
			$this->createConfiguredMock(PortalCaseDocumentReader::class, ['entries' => [['id' => 'd1', 'file' => ['register' => 'r', 'schema' => 's', 'id' => 'o', 'fileId' => 'f1']]]]),
			$this->files,
			$this->audit = $this->createMock(PortalAuditHook::class),
			$this->createConfiguredMock(PortalProviderLocator::class, ['locate' => $this->provider]),
			$this->createMock(LoggerInterface::class),
			new TimelineProviderMethod()
		);
		$this->files->method('streamFile')->willReturn(null);
		$this->audit->expects($this->never())->method('download');
		$this->assertSame(CollectionDocuments::NOT_FOUND, $gone->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd1'));

		$cases = [
			'refused' => [false, 'Portaliq: the opened hook refused {"app":"decidiq"}'],
			'failed'  => [new RuntimeException('down'), 'Portaliq: the opened hook failed {"app":"decidiq"}'],
		];
		foreach ($cases as $label => [$answer, $warning]) {
			$service = $this->service();
			$this->provider->answer = $answer;
			$service->open(subject: self::SUBJECT, app: 'decidiq', documents: self::DOCUMENTS, object: self::OBJECT, documentId: 'd1');
			$this->assertSame([$warning], $this->warnings, $label);
		}

		$service = $this->service();
		$service->open(subject: self::SUBJECT, app: 'decidiq', documents: ['provider' => 'papers', 'opened' => 'nope'], object: self::OBJECT, documentId: 'd1');
		$this->assertSame(['Portaliq: the opened hook cannot be called {"app":"decidiq"}'], $this->warnings);

		$service = $this->service();
		$this->assertSame(CollectionDocuments::REFUSED, $service->open(subject: self::SUBJECT, app: 'decidiq', documents: ['provider' => 'papers', 'opened' => 'privateHook'], object: self::OBJECT, documentId: 'd1'));
		$this->assertSame(CollectionDocuments::REFUSED, $service->open(subject: self::SUBJECT, app: 'decidiq', documents: ['provider' => 'papers', 'opened' => 'staticHook'], object: self::OBJECT, documentId: 'd1'));
		$this->assertSame([], $this->warnings, 'a hook that is not a public instance method refuses without a warning');
	}//end testTheAuditAndTheHookWarningsAreAsRecorded()

	/**
	 * The bundle streams the first entry the `bundle` method answers and is
	 * recorded as a download; no `bundle` method, or no entry, streams nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
	 */
	public function testTheBundleStreamsTheFirstEntryOfTheBundleMethod(): void {
		$service = $this->service();
		$this->files->expects($this->once())->method('streamFile')->with('r', 's', 'o', 'f1');
		$this->audit->expects($this->once())->method('download');

		$result = $service->bundle(subject: self::SUBJECT, app: 'learniq', documents: self::BUNDLED, object: self::OBJECT);

		$this->assertInstanceOf(expected: StreamResponse::class, actual: $result);
	}//end testTheBundleStreamsTheFirstEntryOfTheBundleMethod()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
	 */
	public function testNoBundleMethodOrNoEntryStreamsNothing(): void {
		$service = $this->service();
		$this->files->expects($this->never())->method('streamFile');
		$this->assertNull(actual: $service->bundle(subject: self::SUBJECT, app: 'learniq', documents: self::DOCUMENTS, object: self::OBJECT));

		$service = $this->service(listed: false);
		$this->files->expects($this->never())->method('streamFile');
		$this->audit->expects($this->never())->method('download');
		$this->assertNull(actual: $service->bundle(subject: self::SUBJECT, app: 'learniq', documents: self::BUNDLED, object: self::OBJECT));
	}//end testNoBundleMethodOrNoEntryStreamsNothing()

	/**
	 * One listed document of the test object, as the controller hands it over.
	 *
	 * @param array<string, mixed> $documents  The declaration.
	 * @param string               $documentId The listed entry's id.
	 *
	 * @return DocumentOpening
	 */
	private function opening(array $documents=self::TERMS, string $documentId='d1'): DocumentOpening {
		return new DocumentOpening(subject: self::SUBJECT, app: 'filinq', documents: $documents, object: self::OBJECT, documentId: $documentId);
	}

	/**
	 * document-download-terms task 1: the terms hook answers the conditions, filtered, with the session's subject.
	 *
	 * @return void
	 */
	public function testTheTermsHookAnswersTheConditionsShown(): void {
		$service               = $this->service();
		$agreement             = ['version' => 'v2', 'text' => 'No reuse'];
		$this->provider->terms = ['mayDownload' => false, 'message' => 'Accept first', 'agreement' => $agreement, 'internal' => 'x'];

		$terms = $service->terms(opening: $this->opening());

		$this->assertSame(expected: ['mayDownload' => false, 'message' => 'Accept first', 'agreement' => $agreement], actual: $terms);
		$subject = ['subjectRef' => 'bsn-1', 'trust' => 'substantial', 'identityType' => 'digid', 'audience' => 'citizen'];
		$this->assertSame(expected: ['terms', 'a1', 'd1', $subject], actual: $this->provider->calls[0]);
	}

	/**
	 * document-download-terms task 1: no hook, an unlisted document, a throw or a non-array answer gives no terms.
	 *
	 * @return void
	 */
	public function testTermsAreNullWhenTheyCannotBeRead(): void {
		$this->assertNull(actual: $this->service()->terms(opening: $this->opening(documents: self::DOCUMENTS)));
		$this->assertNull(actual: $this->service(listed: false)->terms(opening: $this->opening()));
		$this->assertSame(expected: [], actual: $this->provider->calls);

		$service               = $this->service();
		$this->provider->terms = new RuntimeException('down');
		$this->assertNull(actual: $service->terms(opening: $this->opening()));
		$this->provider->terms = 'yes';
		$this->assertNull(actual: $service->terms(opening: $this->opening()));
	}

	/**
	 * document-download-terms task 1: accepting passes the trimmed version and is true only on exactly true.
	 *
	 * @return void
	 */
	public function testAcceptingPassesTheVersionShown(): void {
		$service = $this->service();
		$this->assertTrue(condition: $service->accept(opening: $this->opening(), version: ' v2 '));
		$this->assertSame(expected: 'v2', actual: $this->provider->calls[0][4]);

		$this->provider->answer = 'true';
		$this->assertFalse(condition: $service->accept(opening: $this->opening(), version: 'v2'));
		$this->provider->answer = new RuntimeException('down');
		$this->assertFalse(condition: $service->accept(opening: $this->opening(), version: 'v2'));
	}

	/**
	 * document-download-terms task 1: no hook, no version or an unlisted document records nothing.
	 *
	 * @return void
	 */
	public function testAnAcceptanceWithoutHookVersionOrListingRecordsNothing(): void {
		$service = $this->service();
		$this->assertFalse(condition: $service->accept(opening: $this->opening(documents: self::DOCUMENTS), version: 'v2'));
		$this->assertFalse(condition: $service->accept(opening: $this->opening(), version: '  '));
		$this->assertFalse(condition: $service->accept(opening: $this->opening(documentId: ''), version: 'v2'));
		$this->assertSame(expected: [], actual: $this->provider->calls);

		$unlisted = $this->service(listed: false);
		$this->assertFalse(condition: $unlisted->accept(opening: $this->opening(), version: 'v2'));
		$this->assertSame(expected: [], actual: $this->provider->calls);
	}
}
