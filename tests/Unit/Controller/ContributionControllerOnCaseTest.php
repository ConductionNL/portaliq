<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
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
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A create sent from a case page (`?onCase=`): the server fills in the case,
 * asks the window again, and the cross-reference guard still runs.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */
class ContributionControllerOnCaseTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'resident-1', 'audience' => 'resident', 'organisation' => '', 'trust' => 'substantial', 'jti' => 'jti-1'];

	private const OWN_CASE = 'case-own';

	private const FOREIGN_CASE = 'case-foreign';

	/**
	 * A body naming another person's case is overwritten with the page's case.
	 *
	 * @return void
	 */
	public function testAForgedBodyIsOverwrittenWithThePagesCase(): void {
		$written = [];
		$writer  = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createObject')->willReturnCallback(
			function (...$args) use (&$written) {
				$written = $args[5];
				return ['id' => 'bezwaar-1', 'reference' => 'B-2026-0001'];
			}
		);

		$response = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'dossiq/zaak/' . self::OWN_CASE, 'againstCaseId' => self::FOREIGN_CASE, 'grounds' => 'Niet eens'],
			writer: $writer
		)->create('dossiq', 'portaalVerzoek');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::OWN_CASE, $written['againstCaseId']);
		$this->assertSame('Niet eens', $written['grounds']);
	}//end testAForgedBodyIsOverwrittenWithThePagesCase()

	/**
	 * A case the resident cannot read answers 403 and nothing is stored.
	 *
	 * @return void
	 */
	public function testSomeoneElsesCaseIsRefused(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$response = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'dossiq/zaak/' . self::FOREIGN_CASE, 'grounds' => 'Niet eens'],
			writer: $writer
		)->create('dossiq', 'portaalVerzoek');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('cross_ref_refused', $response->getData()['error']);
	}//end testSomeoneElsesCaseIsRefused()

	/**
	 * A case past its window answers 409 with the app's sentence, nothing stored.
	 *
	 * @return void
	 */
	public function testAClosedWindowIsRefusedWithItsSentence(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$response = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'dossiq/zaak/' . self::OWN_CASE, 'grounds' => 'Te laat'],
			writer: $writer,
			decisionSentAt: '2020-01-01'
		)->create('dossiq', 'portaalVerzoek');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'case_action_closed', 'message' => 'De termijn voor bezwaar is voorbij.'], $response->getData());
	}//end testAClosedWindowIsRefusedWithItsSentence()

	/**
	 * A page cannot name a collection the action is not placed on, and a
	 * malformed reference is a 400; neither writes.
	 *
	 * @return void
	 */
	public function testAnotherCollectionOrAMalformedReferenceIsRefused(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$other = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'dossiq/aanvraag/' . self::OWN_CASE],
			writer: $writer
		)->create('dossiq', 'portaalVerzoek');
		$malformed = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'dossiq/zaak'],
			writer: $writer
		)->create('dossiq', 'portaalVerzoek');

		$this->assertSame(403, $other->getStatus());
		$this->assertSame(400, $malformed->getStatus());
	}//end testAnotherCollectionOrAMalformedReferenceIsRefused()

	/**
	 * The controller over dossiq's bezwaar, with a reader that holds only the
	 * resident's own case.
	 *
	 * @param array<string, mixed>    $params         The request parameters.
	 * @param PortalObjectWriter      $writer         The writer double.
	 * @param string                  $decisionSentAt The own case's decision date.
	 *
	 * @return ContributionController
	 */
	private function controller(array $params, PortalObjectWriter $writer, string $decisionSentAt=''): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['required' => [], 'properties' => []]);
		$normalised = (new PortalManifestNormaliser($schemas))->normalise(['collections' => [], 'actions' => [$this->bezwaar()]]);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'collections' => [], 'actions' => $normalised['actions']]]]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$decision = $decisionSentAt;
		if ($decision === '') {
			$decision = (new \DateTimeImmutable('-3 days'))->format('Y-m-d');
		}

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn('resident-1');
		$reader->method('readObject')->willReturnCallback(
			static function (...$args) use ($decision): ?array {
				if (($args[4] ?? '') !== self::OWN_CASE || ($args[1] ?? '') !== 'zaak') {
					return null;
				}

				return ['id' => self::OWN_CASE, 'decisionSentAt' => $decision];
			}
		);

		return new ContributionController(
			$request,
			$registry,
			$session,
			$reader,
			$writer,
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$schemas,
			$this->createMock(PortalInboxReader::class),
			$this->createMock(PortalAuditHook::class),
			$this->createMock(PortalActionForwarder::class),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * dossiq's `createBezwaar`, as it will declare it.
	 *
	 * @return array<string, mixed>
	 */
	private function bezwaar(): array {
		return [
			'id' => 'createBezwaar',
			'type' => 'create',
			'register' => 'dossiq',
			'schema' => 'portaalVerzoek',
			'label' => 'Bezwaar maken',
			'fields' => ['againstCaseId', 'grounds'],
			'crossRefs' => [
				'againstCaseId' => ['register' => 'dossiq', 'schema' => 'zaak', 'scopeField' => 'portalSubject', 'required' => true],
			],
			'onCase' => [
				'crossRef' => 'againstCaseId',
				'window' => ['from' => 'decisionSentAt', 'days' => 42],
				'closedText' => 'De termijn voor bezwaar is voorbij.',
			],
		];
	}//end bezwaar()
}//end class
