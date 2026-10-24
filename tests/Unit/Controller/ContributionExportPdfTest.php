<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Http\PdfDownloadResponse;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
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
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister refuses a list longer than its cap with this exception.
 */
class ExportTooLargeException extends RuntimeException {
}

/**
 * A stand-in for OpenRegister's rows renderer: records what it was asked and answers a PDF.
 */
class FakeRowsPdfService {

	/**
	 * What renderRowsToPdf was called with, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * An exception to throw instead of answering.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throws = null;

	/**
	 * The renderer.
	 *
	 * @param string $title The title.
	 * @param array<int, array<string, string>> $columns The columns.
	 * @param array<int, array<string, string>> $rows The rows.
	 *
	 * @return string
	 */
	public function renderRowsToPdf(string $title, array $columns, array $rows): string {
		$this->calls[] = compact('title', 'columns', 'rows');
		if ($this->throws !== null) {
			throw $this->throws;
		}

		return '%PDF-1.4 fake';
	}
}

/**
 * cases-export-own-data-pdf REQ-OPX-001 to REQ-OPX-005: the export reads what
 * the screen reads, only projected fields reach the renderer, and every export
 * is audited.
 *
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t02
 */
class ContributionExportPdfTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 's1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'low', 'roles' => [], 'jti' => 'j'];

	private FakeRowsPdfService $renderer;

	private MockObject $reader;

	private MockObject $audit;

	/**
	 * Build the controller over one collection.
	 *
	 * @param array<string, mixed> $collection The collection overrides.
	 * @param bool $withRenderer Whether OpenRegister's renderer is there.
	 * @param array<string, mixed> $subject The session's subject.
	 *
	 * @return ContributionController
	 */
	private function controller(array $collection=[], bool $withRenderer=true, array $subject=self::SUBJECT): ContributionController {
		$aggregate = ['contributions' => [['app' => 'budgetiq', 'collections' => [
			$collection + ['id' => 'statements', 'label' => 'Afschriften', 'register' => 'budgetiq', 'schema' => 'statement', 'scopeField' => 'subjectRef', 'exportPdf' => true, 'fields' => ['date', 'amount'], 'columns' => [['field' => 'date', 'label' => 'Datum'], ['field' => 'amount', 'label' => 'Bedrag']]],
		]]]];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', 'Bearer t']]);
		$request->method('getParam')->willReturn('');
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($aggregate);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->audit  = $this->createMock(PortalAuditHook::class);
		$this->renderer = new FakeRowsPdfService();
		$container      = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->renderer);
		$pdf = new PortalPdfExport($container, $this->createMock(LoggerInterface::class), ($withRenderer === true ? FakeRowsPdfService::class : 'OCA\\OpenRegister\\Service\\Missing'));
		$urls = $this->createMock(IURLGenerator::class);

		return new ContributionController(
			$request,
			$registry,
			$session,
			$this->reader,
			$this->createMock(PortalObjectWriter::class),
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$this->createMock(PortalInboxReader::class),
			$this->audit,
			new PortalActionForwarder($request, new InstanceLoopback($this->createMock(IClientService::class), $urls, $this->createMock(InternalBaseUrl::class), $this->createMock(LoggerInterface::class)), $session),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class),
			pdf: $pdf
		);
	}

	public function testListExportUsesTheScopedRead(): void {
		$controller = $this->controller();
		$received   = [];
		$this->reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, int $limit, string $scopeClaim, ?string $contributingApp, mixed $via, string $audience, mixed $fields, array $filter) use (&$received): array {
				$received = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'limit', 'fields');
				return [['date' => '2026-09-01', 'amount' => '12,50']];
			}
		);

		$response = $controller->exportCollectionPdf('budgetiq', 'statement');

		$this->assertInstanceOf(PdfDownloadResponse::class, $response);
		$this->assertSame('%PDF-1.4 fake', $response->render());
		$this->assertSame('afschriften.pdf', $response->fileName());
		$this->assertSame(['register' => 'budgetiq', 'schema' => 'statement', 'scopeField' => 'subjectRef', 'subjectRef' => 's1', 'organisation' => 'org-1', 'limit' => 200, 'fields' => ['date', 'amount']], $received);
		$this->assertSame([['key' => 'date', 'label' => 'Datum'], ['key' => 'amount', 'label' => 'Bedrag']], $this->renderer->calls[0]['columns']);
		$this->assertSame([['date' => '2026-09-01', 'amount' => '12,50']], $this->renderer->calls[0]['rows']);
		$this->assertStringStartsWith('Afschriften, org-1, ', $this->renderer->calls[0]['title']);

	}//end testListExportUsesTheScopedRead()

	public function testCollectionWithoutOptInIs404(): void {
		$controller = $this->controller(['exportPdf' => false]);
		$this->reader->expects($this->never())->method('readCollection');
		$this->reader->expects($this->never())->method('readObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->exportCollectionPdf('budgetiq', 'statement')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->exportObjectPdf('budgetiq', 'statement', 'a')->getStatus());
		$this->assertSame([], $this->renderer->calls);

	}//end testCollectionWithoutOptInIs404()

	public function testTrustBelowMinTrustIs403(): void {
		$controller = $this->controller(['minTrust' => 'substantial']);
		$this->reader->expects($this->never())->method('readCollection');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->exportCollectionPdf('budgetiq', 'statement')->getStatus());
		$this->assertSame([], $this->renderer->calls);

	}//end testTrustBelowMinTrustIs403()

	public function testForeignObjectIs404(): void {
		$controller = $this->controller();
		$this->reader->method('readObject')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->exportObjectPdf('budgetiq', 'statement', 'someone-elses')->getStatus());
		$this->assertSame([], $this->renderer->calls, 'nothing is rendered for a record that is not theirs');

	}//end testForeignObjectIs404()

	public function testOnlyProjectedFieldsReachTheRenderer(): void {
		$controller = $this->controller(['detail' => ['layout' => 'card', 'fields' => ['amount']]]);
		$this->reader->method('readObject')->willReturn(['date' => '2026-09-01', 'amount' => '12,50', 'internalNote' => 'niet tonen']);
		$this->reader->method('readCollection')->willReturn([['date' => '2026-09-01', 'amount' => '12,50', 'internalNote' => 'niet tonen']]);

		$controller->exportObjectPdf('budgetiq', 'statement', 'a1');
		$controller->exportCollectionPdf('budgetiq', 'statement');

		foreach ($this->renderer->calls as $call) {
			$this->assertStringNotContainsString('internalNote', json_encode($call));
			$this->assertStringNotContainsString('niet tonen', json_encode($call));
		}

		$this->assertSame([['key' => 'amount', 'label' => 'Bedrag']], $this->renderer->calls[0]['columns'], 'the record uses the detail fields');
		$this->assertSame([['amount' => '12,50']], $this->renderer->calls[0]['rows']);

	}//end testOnlyProjectedFieldsReachTheRenderer()

	public function testMissingRendererIs503(): void {
		$controller = $this->controller(withRenderer: false);
		$this->reader->method('readCollection')->willReturn([]);
		$this->reader->method('readObject')->willReturn(['date' => 'x']);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->exportCollectionPdf('budgetiq', 'statement')->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->exportObjectPdf('budgetiq', 'statement', 'a')->getStatus());

	}//end testMissingRendererIs503()

	public function testExportPdfHiddenWithoutRenderer(): void {
		$with    = $this->controller();
		$without = $this->controller(withRenderer: false);

		$this->assertTrue($with->index()->getData()['contributions'][0]['collections'][0]['exportPdf']);
		$this->assertFalse($without->index()->getData()['contributions'][0]['collections'][0]['exportPdf']);

	}//end testExportPdfHiddenWithoutRenderer()

	public function testTooLargeIs400AndOtherFailureIs502(): void {
		$controller = $this->controller();
		$this->reader->method('readCollection')->willReturn([['date' => 'x']]);
		$this->audit->expects($this->never())->method('download');

		$this->renderer->throws = new ExportTooLargeException('too many');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->exportCollectionPdf('budgetiq', 'statement')->getStatus());
		$this->renderer->throws = new RuntimeException('dompdf');
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->exportCollectionPdf('budgetiq', 'statement')->getStatus());

	}//end testTooLargeIs400AndOtherFailureIs502()

	public function testExportIsAudited(): void {
		$controller = $this->controller();
		$this->reader->method('readCollection')->willReturn([['date' => 'x']]);
		$this->reader->method('readObject')->willReturn(['date' => 'x']);
		$audited = [];
		$this->audit->expects($this->exactly(2))->method('download')->willReturnCallback(
			function (string $subjectRef, string $organisation, string $register, string $schema, string $id) use (&$audited): void {
				$audited[] = [$subjectRef, $register, $schema, $id];
			}
		);

		$controller->exportCollectionPdf('budgetiq', 'statement');
		$controller->exportObjectPdf('budgetiq', 'statement', 'a1');

		$this->assertSame([['s1', 'budgetiq', 'statement', 'statements'], ['s1', 'budgetiq', 'statement', 'a1']], $audited);

	}//end testExportIsAudited()
}//end class
