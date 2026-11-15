<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Reports;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\Reports\ReportIntakeService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Pins every write ReportIntakeService::accept() makes, in order, with the
 * exact fields: the report row, the separate contact record, the link between
 * them, and the calls that are refused before anything is written.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportIntakeWritesTest extends TestCase {

	/**
	 * Every writer call: method name, then the named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->calls = [];

	}//end setUp()

	/**
	 * A report with contact fields in its answers and in the contact array.
	 *
	 * @return void
	 */
	public function testTheReportTheContactAndTheLinkAreWrittenInOrder(): void {
		$accepted = $this->intake()->accept(
			portal: 'gemeente-x',
			caseType: 'misstandmelding',
			report: [
				'subject' => 'Onderwerp',
				'body' => 'Tekst',
				'ip' => '10.0.0.1',
				'address' => 'Straat 1',
				'userAgent' => 'UA',
				'name' => 'Uit het formulier',
				'phone' => '0612345678',
				'where' => 'Bij de brug',
			],
			contact: ['name' => 'Opgegeven', 'email' => ' sanne@example.org ', 'extra' => 'nee'],
			caseTypeRegister: 'zaaktypen',
			caseTypeSchema: 'zaaktype'
		);

		$this->assertSame(['code' => 'CODE-1', 'reportId' => 'report-1'], $accepted);
		$this->assertCount(3, $this->calls);

		$report = $this->calls[0];
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $report['data']['receivedAt']);
		unset($report['data']['receivedAt']);
		$this->assertSame(
			[
				'method' => 'createObject',
				'register' => 'portaliq',
				'schema' => 'portalReport',
				'scopeField' => '',
				'subjectRef' => '',
				'organisation' => '',
				'data' => [
					'portal' => 'gemeente-x',
					'caseType' => 'misstandmelding',
					'caseTypeRegister' => 'zaaktypen',
					'caseTypeSchema' => 'zaaktype',
					'subject' => 'Onderwerp',
					'body' => 'Tekst',
					'answers' => ['where' => 'Bij de brug'],
					'codeHash' => hash('sha256', 'CODE-1'),
					'contactRef' => '',
					'state' => 'open',
				],
			],
			$report
		);
		$this->assertSame(
			[
				'method' => 'createObject',
				'register' => 'portaliq',
				'schema' => 'portalReporterContact',
				'scopeField' => '',
				'subjectRef' => '',
				'organisation' => '',
				'data' => ['reportRef' => 'report-1', 'name' => 'Opgegeven', 'email' => 'sanne@example.org', 'phone' => '0612345678'],
			],
			$this->calls[1]
		);
		$this->assertSame(
			[
				'method' => 'updateObject',
				'register' => 'portaliq',
				'schema' => 'portalReport',
				'scopeField' => '',
				'subjectRef' => '',
				'organisation' => '',
				'id' => 'report-1',
				'data' => ['contactRef' => 'contact-2'],
			],
			$this->calls[2]
		);

	}//end testTheReportTheContactAndTheLinkAreWrittenInOrder()

	/**
	 * A report without contact detail writes the report only, with defaults.
	 *
	 * @return void
	 */
	public function testAReportWithoutContactWritesOnlyTheReport(): void {
		$accepted = $this->intake()->accept(portal: 'gemeente-x', caseType: 'melding', report: ['email' => '   ']);

		$this->assertSame(['code' => 'CODE-1', 'reportId' => 'report-1'], $accepted);
		$this->assertCount(1, $this->calls);
		$data = $this->calls[0]['data'];
		unset($data['receivedAt']);
		$this->assertSame(
			[
				'portal' => 'gemeente-x',
				'caseType' => 'melding',
				'caseTypeRegister' => '',
				'caseTypeSchema' => '',
				'subject' => '',
				'body' => '',
				'answers' => [],
				'codeHash' => hash('sha256', 'CODE-1'),
				'contactRef' => '',
				'state' => 'open',
			],
			$data
		);

	}//end testAReportWithoutContactWritesOnlyTheReport()

	/**
	 * Refused before any write: no portal, or no code could be minted.
	 *
	 * @return void
	 */
	public function testNoPortalOrNoCodeWritesNothing(): void {
		$this->assertNull($this->intake()->accept(portal: '', caseType: 'x', report: ['body' => 'b']));
		$this->assertNull($this->intake(code: '')->accept(portal: 'p', caseType: 'x', report: ['body' => 'b']));
		$this->assertSame([], $this->calls);

	}//end testNoPortalOrNoCodeWritesNothing()

	/**
	 * A report the store does not take answers null and stores no contact.
	 *
	 * @return void
	 */
	public function testAFailedReportWriteStoresNoContact(): void {
		$this->assertNull($this->intake(failing: ['portalReport'])->accept(portal: 'p', caseType: 'x', report: ['body' => 'b'], contact: ['email' => 'a@b.c']));
		$this->assertCount(1, $this->calls);

	}//end testAFailedReportWriteStoresNoContact()

	/**
	 * A contact record the store does not take leaves the report unlinked.
	 *
	 * @return void
	 */
	public function testAFailedContactWriteLeavesTheReportUnlinked(): void {
		$accepted = $this->intake(failing: ['portalReporterContact'])->accept(portal: 'p', caseType: 'x', report: ['body' => 'b'], contact: ['email' => 'a@b.c']);

		$this->assertSame(['code' => 'CODE-1', 'reportId' => 'report-1'], $accepted);
		$this->assertSame(['createObject', 'createObject'], array_column($this->calls, 'method'));

	}//end testAFailedContactWriteLeavesTheReportUnlinked()

	/**
	 * A report row the store returns without an id gets no contact record.
	 *
	 * @return void
	 */
	public function testAReportWithoutAnIdGetsNoContact(): void {
		$accepted = $this->intake(withoutId: true)->accept(portal: 'p', caseType: 'x', report: ['body' => 'b'], contact: ['email' => 'a@b.c']);

		$this->assertSame(['code' => 'CODE-1', 'reportId' => ''], $accepted);
		$this->assertCount(1, $this->calls);

	}//end testAReportWithoutAnIdGetsNoContact()

	/**
	 * The service over a recording writer and a fixed code.
	 *
	 * @param string        $code      The code the random source mints.
	 * @param array<string> $failing   Schemas whose create returns null.
	 * @param bool          $withoutId Whether created rows carry no id.
	 *
	 * @return ReportIntakeService
	 */
	private function intake(string $code = 'CODE-1', array $failing = [], bool $withoutId = false): ReportIntakeService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn($code);

		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject'])
			->getMock();
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use ($failing, $withoutId): ?array {
				$this->calls[] = ['method' => 'createObject'] + compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'data');
				if (in_array($schema, $failing, true) === true) {
					return null;
				}

				if ($withoutId === true) {
					return $data;
				}

				$prefix = ($schema === 'portalReport') ? 'report-' : 'contact-';
				return $data + ['@self' => ['uuid' => $prefix . count($this->calls)]];
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): ?array {
				$this->calls[] = ['method' => 'updateObject'] + compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'id', 'data');
				return $data;
			}
		);

		return new ReportIntakeService($writer, $random);
	}//end intake()

}//end class
