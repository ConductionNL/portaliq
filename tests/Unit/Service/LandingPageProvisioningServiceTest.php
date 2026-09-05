<?php

/**
 * LandingPageProvisioningServiceTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Event\LandingPageRequestedEvent;
use OCA\Portaliq\Service\LandingPageProvisioningService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the validation and provisioning logic behind the
 * `LandingPageRequestedEvent` cross-app command (landing-page-provisioning):
 * unknown-portal / duplicate-route / invalid-article / invalid-form fail
 * closed with no writes, a valid request writes a `form` then a `page` and
 * fills the event's result slot, and the created page is ALWAYS `draft`.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-requests-fail-closed-with-a-machine-readable-error-and-no-partial-write-plan
 */
class LandingPageProvisioningServiceTest extends TestCase {

	/**
	 * @var PortalObjectReader&MockObject
	 */
	private PortalObjectReader $reader;

	/**
	 * @var PortalObjectWriter&MockObject
	 */
	private PortalObjectWriter $writer;

	private LandingPageProvisioningService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->writer = $this->createMock(PortalObjectWriter::class);
		$this->service = new LandingPageProvisioningService(
			reader: $this->reader,
			writer: $this->writer,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A minimal, otherwise-valid request event, for tests that only care
	 * about one dimension of validation.
	 *
	 * @param array<string, mixed> $overrides Constructor arg overrides, keyed by name.
	 */
	private function event(array $overrides = []): LandingPageRequestedEvent {
		$defaults = [
			'sourceApp' => 'pipelinq',
			'portal' => 'open-tilburg',
			'route' => '/campagne/webinar-ai',
			'title' => 'Webinar AI',
			'locale' => 'nl',
			'article' => ['summary' => 'Een webinar over AI.', 'body' => '## Programma'],
			'form' => [
				'fields' => [['id' => 'email', 'label' => 'E-mail', 'type' => 'email']],
				'submitLabel' => 'Aanmelden',
				'consentText' => 'Ik ga akkoord.',
			],
			'utm' => ['campaign' => 'webinar-ai', 'source' => 'newsletter', 'medium' => 'email'],
			'externalReference' => 'pipelinq:campaign:1',
			'correlationId' => 'corr-1',
		];
		$args = array_merge($defaults, $overrides);

		return new LandingPageRequestedEvent(
			sourceApp: $args['sourceApp'],
			portal: $args['portal'],
			route: $args['route'],
			title: $args['title'],
			locale: $args['locale'],
			article: $args['article'],
			form: $args['form'],
			utm: $args['utm'],
			externalReference: $args['externalReference'],
			correlationId: $args['correlationId']
		);
	}//end event()

	/**
	 * Configure the reader to resolve a published portal by slug, matching
	 * `resolvePublishedPortal()`'s filter.
	 *
	 * @param array<string, mixed> $portalRow The portal row to return.
	 */
	private function willResolvePortal(array $portalRow): void {
		$this->reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use ($portalRow): array {
				if ($schema === 'portal') {
					return [$portalRow];
				}

				return [];
			}
		);
	}//end willResolvePortal()

	public function testUnknownPortalIsRejectedWithNoWrite(): void {
		$this->reader->method('readCollection')->willReturn([]);
		$this->writer->expects($this->never())->method('createAnonymousObject');

		$event = $this->event();
		$this->service->provision(event: $event);

		$this->assertSame('unknown_portal', $event->getError());
		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getPageId());
		$this->assertNull($event->getFormId());
	}//end testUnknownPortalIsRejectedWithNoWrite()

	public function testDuplicateRouteWithinTheSamePortalIsRejected(): void {
		$this->reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema) {
				if ($schema === 'portal') {
					return [['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]];
				}

				if ($schema === 'page') {
					return [['route' => '/campagne/webinar-ai', 'portal' => 'open-tilburg']];
				}

				return [];
			}
		);
		$this->writer->expects($this->never())->method('createAnonymousObject');

		$event = $this->event();
		$this->service->provision(event: $event);

		$this->assertSame('duplicate_route', $event->getError());
	}//end testDuplicateRouteWithinTheSamePortalIsRejected()

	public function testTheSameRouteInADifferentPortalSucceeds(): void {
		$this->reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema) {
				if ($schema === 'portal') {
					return [['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]];
				}

				if ($schema === 'page') {
					// Same route, but a DIFFERENT portal's page.
					return [['route' => '/campagne/webinar-ai', 'portal' => 'open-venray']];
				}

				return [];
			}
		);
		$this->writer->method('createAnonymousObject')->willReturnCallback(
			static fn (string $register, string $schema, array $data): array => array_merge($data, ['id' => ($schema === 'form') ? 'form-1' : 'page-1'])
		);

		$event = $this->event();
		$this->service->provision(event: $event);

		$this->assertNull($event->getError());
		$this->assertSame('page-1', $event->getPageId());
	}//end testTheSameRouteInADifferentPortalSucceeds()

	public function testEmptyArticleSummaryOrBodyIsRejected(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);
		$this->writer->expects($this->never())->method('createAnonymousObject');

		$event = $this->event(['article' => ['summary' => '', 'body' => 'x']]);
		$this->service->provision(event: $event);

		$this->assertSame('invalid_article', $event->getError());
	}//end testEmptyArticleSummaryOrBodyIsRejected()

	public function testFormWithNoFieldsIsRejected(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);
		$this->writer->expects($this->never())->method('createAnonymousObject');

		$event = $this->event(['form' => ['fields' => [], 'submitLabel' => 'Go']]);
		$this->service->provision(event: $event);

		$this->assertSame('invalid_form', $event->getError());
	}//end testFormWithNoFieldsIsRejected()

	public function testFormFieldMissingIdLabelOrTypeIsRejected(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);

		$event = $this->event(['form' => ['fields' => [['id' => 'email', 'label' => 'E-mail']], 'submitLabel' => 'Go']]);
		$this->service->provision(event: $event);

		$this->assertSame('invalid_form', $event->getError());
	}//end testFormFieldMissingIdLabelOrTypeIsRejected()

	public function testEmptySubmitLabelIsRejected(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);

		$event = $this->event(
			['form' => ['fields' => [['id' => 'email', 'label' => 'E-mail', 'type' => 'email']], 'submitLabel' => '']]
		);
		$this->service->provision(event: $event);

		$this->assertSame('invalid_form', $event->getError());
	}//end testEmptySubmitLabelIsRejected()

	public function testAValidRequestCreatesAFormThenADraftPageAndFillsTheResultSlot(): void {
		$this->willResolvePortal(
			['slug' => 'open-tilburg', 'status' => 'published', 'domains' => [['hostname' => 'tilburg.example.org', 'verified' => true]]]
		);

		$writes = [];
		$this->writer->method('createAnonymousObject')->willReturnCallback(
			function (string $register, string $schema, array $data) use (&$writes): array {
				$writes[] = ['register' => $register, 'schema' => $schema, 'data' => $data];
				$id = ($schema === 'form') ? 'form-42' : 'page-42';
				return array_merge($data, ['id' => $id]);
			}
		);

		$event = $this->event();
		$this->service->provision(event: $event);

		$this->assertNull($event->getError());
		$this->assertTrue($event->isHandled());
		$this->assertSame('page-42', $event->getPageId());
		$this->assertSame('form-42', $event->getFormId());
		$this->assertSame('https://tilburg.example.org/campagne/webinar-ai', $event->getPublicUrl());

		// Order matters: the form MUST be written before the page, so the
		// page's widget can embed the form's real id.
		$this->assertCount(2, $writes);
		$this->assertSame('form', $writes[0]['schema']);
		$this->assertSame('page', $writes[1]['schema']);
		$this->assertSame('draft', $writes[1]['data']['status']);
	}//end testAValidRequestCreatesAFormThenADraftPageAndFillsTheResultSlot()

	public function testCreatedPageIsAlwaysDraft(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);

		$pageData = null;
		$this->writer->method('createAnonymousObject')->willReturnCallback(
			function (string $register, string $schema, array $data) use (&$pageData): array {
				if ($schema === 'page') {
					$pageData = $data;
				}

				return array_merge($data, ['id' => 'x']);
			}
		);

		$this->service->provision(event: $this->event());

		$this->assertSame('draft', $pageData['status']);
	}//end testCreatedPageIsAlwaysDraft()

	public function testPublicUrlIsNullWhenThePortalHasNoDomain(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);
		$this->writer->method('createAnonymousObject')->willReturnCallback(
			static fn (string $register, string $schema, array $data): array => array_merge($data, ['id' => 'x'])
		);

		$event = $this->event();
		$this->service->provision(event: $event);

		$this->assertNull($event->getError());
		$this->assertNull($event->getPublicUrl());
		$this->assertSame('x', $event->getPageId());
	}//end testPublicUrlIsNullWhenThePortalHasNoDomain()

	public function testAWriteFailureIsReportedAsWriteFailedWithNoPartialSuccess(): void {
		$this->willResolvePortal(['slug' => 'open-tilburg', 'status' => 'published', 'domains' => []]);
		$this->writer->method('createAnonymousObject')->willReturn(null);

		$event = $this->event();
		$this->service->provision(event: $event);

		$this->assertSame('write_failed', $event->getError());
		$this->assertNull($event->getPageId());
		$this->assertNull($event->getFormId());
	}//end testAWriteFailureIsReportedAsWriteFailedWithNoPartialSuccess()
}//end class
