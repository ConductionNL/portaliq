<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalPdfExport;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The guards around ContributionController's schema introspection, uploads,
 * forwardable actions, row ids and the PDF export flag, pinned outcome by
 * outcome.
 */
class ContributionControllerGuardsTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'sub-1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'substantial', 'jti' => 'j1'];

	/**
	 * A controller over the given aggregate, upload and schema reader.
	 *
	 * @param array<string, mixed>       $aggregate The subject's aggregate.
	 * @param mixed                      $upload    What getUploadedFile() answers.
	 * @param PortalSchemaReader|null    $schemas   The schema reader.
	 * @param PortalPdfExport|null       $pdf       The PDF export.
	 *
	 * @return ContributionController
	 */
	private function controller(array $aggregate, mixed $upload=[], ?PortalSchemaReader $schemas=null, ?PortalPdfExport $pdf=null): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', 'Bearer client-session-token'], ['Idempotency-Key', '']]);
		$request->method('getParam')->willReturn('');
		$request->method('getUploadedFile')->willReturn($upload);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($aggregate);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		return new ContributionController(
			request: $request,
			registry: $registry,
			session: $session,
			reader: $this->createMock(PortalObjectReader::class),
			writer: $this->createMock(PortalObjectWriter::class),
			fileWriter: $this->createMock(PortalFileWriter::class),
			fileReader: $this->createMock(PortalFileReader::class),
			schemaReader: ($schemas ?? $this->createMock(PortalSchemaReader::class)),
			inboxReader: $this->createMock(PortalInboxReader::class),
			auditHook: $this->createMock(PortalAuditHook::class),
			forwarder: $this->createMock(PortalActionForwarder::class),
			auditor: $this->createMock(AuditTrailService::class),
			receiptService: $this->createMock(SubmissionReceiptService::class),
			notificationDispatch: $this->createMock(NotificationDispatchService::class),
			logger: $this->createMock(LoggerInterface::class),
			pdf: $pdf
		);
	}//end controller()

	/**
	 * Call a private method of the controller.
	 *
	 * @param ContributionController $controller The controller.
	 * @param string                 $method     The method.
	 * @param array<int, mixed>      $arguments  Its arguments.
	 *
	 * @return mixed
	 */
	private function call(ContributionController $controller, string $method, array $arguments=[]): mixed {
		return (new ReflectionMethod($controller, $method))->invokeArgs($controller, $arguments);
	}//end call()

	/**
	 * A schema an action references is served; one that only a non-array
	 * contribution or another section names is refused before it is read.
	 *
	 * @return void
	 */
	public function testSchemaReferencedByAnActionIsServed(): void {
		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->expects($this->once())->method('readSchema')->with('aanvraag')->willReturn(['title' => 'Aanvraag']);
		$aggregate = ['contributions' => [['app' => 'a', 'collections' => [['schema' => 'other']], 'pages' => [['schema' => 'aanvraag']]], ['app' => 'b', 'actions' => [['schema' => 'aanvraag']]]]];

		$controller = $this->controller(aggregate: $aggregate, schemas: $schemas);
		$this->assertSame(['title' => 'Aanvraag'], $controller->schema('aanvraag')->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->schema('pagesOnly')->getStatus());

		$missing = $this->createMock(PortalSchemaReader::class);
		$missing->method('readSchema')->willReturn(null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(aggregate: $aggregate, schemas: $missing)->schema('aanvraag')->getStatus());
	}//end testSchemaReferencedByAnActionIsServed()

	/**
	 * An upload is read only when it has no error, a readable temp file and
	 * content; its name is reduced to a basename and degrades to `upload`.
	 *
	 * @return void
	 */
	public function testTheUploadedFileAndItsName(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'portaliq-guard');
		file_put_contents($tmp, 'bytes');

		$read = fn (mixed $upload): mixed => $this->call($this->controller(aggregate: [], upload: $upload), 'readUploadedFile');
		$this->assertSame(['name' => 'c.pdf', 'content' => 'bytes'], $read(['name' => 'a/b/c.pdf', 'tmp_name' => $tmp, 'error' => 0]));
		$this->assertSame(['name' => 'upload', 'content' => 'bytes'], $read(['name' => '..', 'tmp_name' => $tmp, 'error' => 0]));
		$this->assertSame(['name' => 'upload', 'content' => 'bytes'], $read(['name' => '', 'tmp_name' => $tmp, 'error' => 0]));
		$this->assertSame(['name' => 'upload', 'content' => 'bytes'], $read(['tmp_name' => $tmp, 'error' => 0]));
		$this->assertSame(['name' => 'upload', 'content' => 'bytes'], $read(['name' => '/', 'tmp_name' => $tmp, 'error' => 0]));
		$this->assertNull($read(['name' => 'x', 'tmp_name' => $tmp, 'error' => 1]));
		$this->assertNull($read(['name' => 'x', 'tmp_name' => $tmp]));
		$this->assertNull($read(['name' => 'x', 'tmp_name' => '', 'error' => 0]));
		$this->assertNull($read(['name' => 'x', 'tmp_name' => $tmp . '-gone', 'error' => 0]));
		$this->assertNull($read([]));
		@unlink($tmp);
	}//end testTheUploadedFileAndItsName()

	/**
	 * Only an instance-local path with an allowed method and enough trust is
	 * forwardable.
	 *
	 * @return void
	 */
	public function testForwardableActions(): void {
		$controller = $this->controller(aggregate: []);
		$forwardable = fn (array $action): bool => $this->call($controller, 'isForwardableAction', [$action, self::SUBJECT]);

		$this->assertTrue($forwardable(['endpoint' => '/apps/x/api']));
		$this->assertTrue($forwardable(['endpoint' => '/apps/x/api', 'method' => 'put']));
		$this->assertFalse($forwardable(['endpoint' => 5]));
		$this->assertFalse($forwardable([]));
		$this->assertFalse($forwardable(['endpoint' => '/apps/x?u=http://evil']));
		$this->assertFalse($forwardable(['endpoint' => '//evil']));
		$this->assertFalse($forwardable(['endpoint' => 'apps/x']));
		$this->assertFalse($forwardable(['endpoint' => '/apps/x', 'method' => 'TRACE']));
		$this->assertFalse($forwardable(['endpoint' => '/apps/x', 'minTrust' => 'high']));
	}//end testForwardableActions()

	/**
	 * A row's id: `id`, `uuid`, `@self.uuid`, `@self.id`, the first non-empty
	 * string or integer; '' when there is none.
	 *
	 * @return void
	 */
	public function testTheIdOfARow(): void {
		$controller = $this->controller(aggregate: []);
		$id = fn (array $row): string => $this->call($controller, 'extractId', [$row]);

		$this->assertSame('1', $id(['id' => 1, 'uuid' => 'u']));
		$this->assertSame('u', $id(['id' => '', 'uuid' => 'u']));
		$this->assertSame('su', $id(['id' => true, '@self' => ['uuid' => 'su', 'id' => 'si']]));
		$this->assertSame('si', $id(['@self' => ['uuid' => '', 'id' => 'si']]));
		$this->assertSame('', $id(['@self' => 'x']));
		$this->assertSame('', $id([]));
	}//end testTheIdOfARow()

	/**
	 * Without the PDF renderer every collection that declares `exportPdf`
	 * says false; the others are left as they are.
	 *
	 * @return void
	 */
	public function testThePdfExportFlag(): void {
		$aggregate = ['contributions' => [['collections' => [['exportPdf' => true], ['id' => 'plain'], 'junk']], 'junk', ['collections' => [['exportPdf' => 'yes']]]]];

		$without = $this->call($this->controller(aggregate: []), 'withExportAvailability', [$aggregate]);
		$this->assertSame(['contributions' => [['collections' => [['exportPdf' => false], ['id' => 'plain'], 'junk']], 'junk', ['collections' => [['exportPdf' => false]]]]], $without);

		$pdf = $this->createMock(PortalPdfExport::class);
		$pdf->method('available')->willReturn(true);
		$this->assertSame($aggregate, $this->call($this->controller(aggregate: [], pdf: $pdf), 'withExportAvailability', [$aggregate]));
	}//end testThePdfExportFlag()
}//end class
