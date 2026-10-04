<?php

/**
 * PortalDraftController (site-multi-step-forms REQ-SMF-012, REQ-SMF-021):
 * who may read a draft, what a save keeps, and when the routes refuse.
 *
 * The store is the real PortalDraftStore with a fake OpenRegister behind it,
 * so the scoping the controller relies on is the scoping under test.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Controller\PortalDraftController;
use OCA\Portaliq\Service\PortalDraftStore;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The three draft routes.
 */
class PortalDraftControllerTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'sanne-1', 'audience' => 'citizen', 'organisation' => 'gemeente-x', 'trust' => 'substantial', 'jti' => 'jti-1'];

	/**
	 * dossiq's Woo request: an endpoint action with fields, a 30 day draft
	 * and a file field that a draft must never keep.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function woo(array $overrides = []): array {
		return array_merge(
			[
				'id'           => 'startWooVerzoek',
				'endpoint'     => '/index.php/apps/dossiq/api/portal/woo-verzoek',
				'method'       => 'POST',
				'fields'       => ['onderwerp', 'omschrijving', 'bijlage'],
				'fieldConfigs' => ['bijlage' => ['type' => 'file']],
				'draft'        => ['retentionDays' => 30],
			],
			$overrides
		);
	}//end woo()

	/**
	 * A save keeps the action's own fields, the step and the retention date,
	 * and nothing the client sneaked in.
	 *
	 * @return void
	 */
	public function testASaveKeepsTheActionsOwnFields(): void {
		$fake = $this->objectService();
		$controller = $this->controller(
			params: ['onderwerp' => 'De nieuwe brug', 'omschrijving' => 'Alle besluiten', 'step' => 1, 'bsn' => '123456782'],
			objectService: $fake
		);

		$response = $controller->save('dossiq', 'startWooVerzoek');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['onderwerp' => 'De nieuwe brug', 'omschrijving' => 'Alle besluiten'],
			$fake->saved[0]['answers']
		);
		$this->assertArrayNotHasKey('bsn', $fake->saved[0]['answers']);
		$this->assertSame(1, $fake->saved[0]['step']);
		// 30 days after the clock's 2026-10-05T12:00:00Z.
		$this->assertSame('2026-11-04T12:00:00+00:00', $fake->saved[0]['expiresAt']);
		$this->assertSame('sanne-1', $fake->saved[0]['subjectRef']);

		$served = $response->getData()['draft'];
		$this->assertSame(['answers', 'step', 'savedAt', 'expiresAt'], array_keys($served));
	}//end testASaveKeepsTheTextFieldsAndNoFile()

	/**
	 * A file is never in a draft: a file field lives on a create or update
	 * action, and its content belongs to a record that does not exist yet, so
	 * the step that asks for one asks again.
	 *
	 * @return void
	 */
	public function testAFileIsNeverKeptInADraft(): void {
		$fake = $this->objectService();
		$create = [
			'id'           => 'createExcuseRequest',
			'type'         => 'create',
			'register'     => 'learniq',
			'schema'       => 'excuse-request',
			'fields'       => ['reason', 'attachmentRef'],
			'fieldConfigs' => ['attachmentRef' => ['type' => 'file']],
			'draft'        => ['retentionDays' => 7],
		];
		$controller = $this->controller(
			params: ['reason' => 'Vera heeft koorts', 'attachmentRef' => 'kaart.pdf'],
			action: $create,
			objectService: $fake
		);

		$response = $controller->save('dossiq', 'createExcuseRequest');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['reason' => 'Vera heeft koorts'], $fake->saved[0]['answers']);
		$this->assertArrayNotHasKey('attachmentRef', $fake->saved[0]['answers']);
		// Seven days after the clock, not the thirty-day default.
		$this->assertSame('2026-10-12T12:00:00+00:00', $fake->saved[0]['expiresAt']);
	}//end testAFileIsNeverKeptInADraft()

	/**
	 * A read answers with the resident's own draft, and with 404 when they
	 * have none.
	 *
	 * @return void
	 */
	public function testAReadAnswersWithTheResidentsOwnDraftOr404(): void {
		$stored = [
			'@self'           => ['uuid' => 'draft-1'],
			'subjectRef'      => 'sanne-1',
			'contributionApp' => 'dossiq',
			'actionId'        => 'startWooVerzoek',
			'answers'         => ['onderwerp' => 'De nieuwe brug'],
			'step'            => 1,
			'expiresAt'       => '2026-11-04T12:00:00+00:00',
		];

		$found = $this->controller(params: [], rows: [$stored])->show('dossiq', 'startWooVerzoek');
		$this->assertSame(200, $found->getStatus());
		$this->assertSame(['onderwerp' => 'De nieuwe brug'], $found->getData()['draft']['answers']);
		$this->assertSame(1, $found->getData()['draft']['step']);

		$none = $this->controller(params: [], rows: [])->show('dossiq', 'startWooVerzoek');
		$this->assertSame(404, $none->getStatus());
		$this->assertSame(['error' => 'no_draft'], $none->getData());
	}//end testAReadAnswersWithTheResidentsOwnDraftOr404()

	/**
	 * A signed-out visitor has no draft: 401, and nothing is stored or read.
	 *
	 * @return void
	 */
	public function testASignedOutVisitorGetsNoDraft(): void {
		$fake = $this->objectService();
		$controller = $this->controller(params: ['onderwerp' => 'x'], subject: null, objectService: $fake);

		foreach (
			[
				$controller->show('dossiq', 'startWooVerzoek'),
				$controller->save('dossiq', 'startWooVerzoek'),
				$controller->destroy('dossiq', 'startWooVerzoek'),
			] as $response
		) {
			$this->assertSame(401, $response->getStatus());
		}

		$this->assertSame([], $fake->saved);
		$this->assertSame([], $fake->deleted);
	}//end testASignedOutVisitorGetsNoDraft()

	/**
	 * An action the subject's own manifest does not hold, and an action that
	 * declares no `draft`, are both 403 with nothing stored.
	 *
	 * @return void
	 */
	public function testOnlyAnActionThatAsksForDraftsGetsOne(): void {
		$fake = $this->objectService();
		$unknown = $this->controller(params: ['onderwerp' => 'x'], objectService: $fake)->save('dossiq', 'startSomethingElse');
		$this->assertSame(403, $unknown->getStatus());

		$noDraft = $this->woo();
		unset($noDraft['draft']);
		$plain = $this->controller(params: ['onderwerp' => 'x'], action: $noDraft, objectService: $fake)->save('dossiq', 'startWooVerzoek');
		// The action is in the manifest; it simply never asked for drafts.
		$this->assertSame(403, $plain->getStatus());
		$this->assertSame(['error' => 'forbidden'], $plain->getData());

		$otherApp = $this->controller(params: ['onderwerp' => 'x'], objectService: $fake)->save('pipelinq', 'startWooVerzoek');
		$this->assertSame(403, $otherApp->getStatus());

		$this->assertSame([], $fake->saved);
	}//end testOnlyAnActionThatAsksForDraftsGetsOne()

	/**
	 * Throwing a draft away deletes the resident's own row and says whether
	 * one was there.
	 *
	 * @return void
	 */
	public function testDestroyDeletesTheOwnDraft(): void {
		$stored = [
			'@self'           => ['uuid' => 'draft-1'],
			'subjectRef'      => 'sanne-1',
			'contributionApp' => 'dossiq',
			'actionId'        => 'startWooVerzoek',
			'expiresAt'       => '2026-11-04T12:00:00+00:00',
		];
		$fake = $this->objectService();

		$response = $this->controller(params: [], rows: [$stored], objectService: $fake)->destroy('dossiq', 'startWooVerzoek');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['discarded' => true], $response->getData());
		$this->assertSame(['draft-1'], $fake->deleted);

		$empty = $this->controller(params: [], rows: [])->destroy('dossiq', 'startWooVerzoek');
		$this->assertSame(['discarded' => false], $empty->getData());
	}//end testDestroyDeletesTheOwnDraft()

	/**
	 * A fake OpenRegister that records the writes and the deletes.
	 *
	 * @return object The fake.
	 */
	private function objectService(): object {
		return new class {
			public array $saved = [];

			public array $deleted = [];

			/**
			 * Records a delete.
			 *
			 * @param string $uuid The row.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return bool True.
			 */
			public function deleteObject(string $uuid, string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->deleted[] = $uuid;
				return true;
			}
		};
	}//end objectService()

	/**
	 * The controller under test, with the real store behind it.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param array<int, mixed> $rows The drafts the scoped read answers with.
	 * @param array<string, mixed>|null $action The action the manifest holds.
	 * @param array<string, mixed>|null $subject The session subject, null for signed out.
	 * @param object|null $objectService The OpenRegister fake.
	 *
	 * @return PortalDraftController The controller.
	 */
	private function controller(
		array $params,
		array $rows = [],
		?array $action = null,
		?array $subject = self::SUBJECT,
		?object $objectService = null,
	): PortalDraftController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($subject === null ? '' : 'Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['required' => [], 'properties' => []]);
		$normalised = (new PortalManifestNormaliser($schemas))->normalise(
			['collections' => [], 'actions' => [($action ?? $this->woo())]]
		);
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(
			['contributions' => [['app' => 'dossiq', 'collections' => [], 'actions' => $normalised['actions']]]]
		);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$fake = ($objectService ?? $this->objectService());
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn($rows);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use ($fake): array {
				$fake->saved[] = array_merge([$scopeField => $subjectRef], $data);
				return array_merge(['@self' => ['uuid' => 'draft-1']], $data);
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use ($fake): array {
				$fake->saved[] = array_merge([$scopeField => $subjectRef], $data);
				return array_merge(['@self' => ['uuid' => $id]], $data);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fake);
		$store = new PortalDraftStore($reader, $writer, $container, $this->createMock(LoggerInterface::class));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime('2026-10-05T12:00:00+00:00'));

		return new PortalDraftController($request, $registry, $session, $store, $time);
	}//end controller()
}//end class
