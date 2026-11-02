<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalDraftController;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\PortalOwnerDraft;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * site-multi-step-forms T8 and submit-creates-the-case-directly 5.1/5.2: the
 * draft routes take the subject from the bearer only, resolve the subject's
 * own create action, and keep the draft in OpenRegister as the destination
 * object in status draft, owned by portal:<subjectRef>. portaliq writes no
 * draft object of its own.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
#[CoversClass(PortalDraftController::class)]
#[UsesClass(ActionDraft::class)]
#[UsesClass(\OCA\Portaliq\Service\Intake\DestinationDraft::class)]
#[UsesClass(\OCA\Portaliq\Service\Intake\DestinationSubmit::class)]
#[UsesClass(\OCA\Portaliq\Contribution\ActionScopeResolver::class)]
#[UsesClass(\OCA\Portaliq\Contribution\CreateBody::class)]
#[UsesClass(PortalSessionService::class)]
class PortalDraftControllerTest extends TestCase {

	private const ACTION = [
		'id' => 'request',
		'type' => 'create',
		'register' => 'dossiq',
		'schema' => 'case',
		'fields' => ['title', 'preferredDate'],
		'defaults' => ['caseType' => 'ct-1'],
		'draft' => ['retentionDays' => 14],
	];

	private FormSubmitFake $fake;

	/**
	 * Draft ids OpenRegister was asked to discard.
	 *
	 * @var array<int, string>
	 */
	private array $discarded = [];

	public function testSavingStartsADraftOfTheDestinationOwnedByThePortalSubject(): void {
		$controller        = $this->controller(subjectRef: 'sanne', outcome: ['id' => 'd-1', 'status' => 'draft']);
		$response          = $controller->save('dossiq', 'request', ['title' => 'Half', 'other' => 'x']);
		$data              = $response->getData();
		$save              = $this->call('saveDraft');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('d-1', $data['id']);
		$this->assertSame('draft', $data['status']);
		$this->assertSame(['title' => 'Half'], $data['answers']);
		$this->assertNotSame('', $data['expiresAt']);
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case'], $save['destination']);
		$this->assertSame(['title' => 'Half', 'caseType' => 'ct-1', 'subjectRef' => 'sanne'], $save['payload']);
		$this->assertNull($save['draftId']);
		$this->assertSame('portal:sanne', $save['owner']->value);
		$this->assertSame('dossiq/request', $save['scope']);
	}//end testSavingStartsADraftOfTheDestinationOwnedByThePortalSubject()

	public function testSavingAgainUpdatesTheLiveDraft(): void {
		$controller = $this->controller(subjectRef: 'sanne', outcome: ['id' => 'd-1'], found: $this->live());
		$controller->save('dossiq', 'request', ['title' => 'Whole']);

		$this->assertSame('d-1', $this->call('saveDraft')['draftId']);
	}//end testSavingAgainUpdatesTheLiveDraft()

	public function testATypeInvalidAnswerIsRefusedOnItsField(): void {
		$controller = $this->controller(
			subjectRef: 'sanne',
			outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'preferredDate', 'code' => 'format-mismatch', 'message' => 'Fill in a date.']])
		);
		$response = $controller->save('dossiq', 'request', ['preferredDate' => '0612345678']);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['preferredDate' => 'Fill in a date.'], $response->getData()['errors']);
	}//end testATypeInvalidAnswerIsRefusedOnItsField()

	public function testShowAnswersTheLiveDraftsAnswersAndExpiry(): void {
		$response = $this->controller(subjectRef: 'sanne', found: $this->live())->show('dossiq', 'request');
		$data     = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['title' => 'Half'], $data['answers']);
		$this->assertSame('d-1', $data['id']);
		$this->assertNotSame('', $data['updatedAt'], 'the resume dialog names the last save');
		$this->assertSame('portal:sanne', $this->call('findDraft')['owner']->value);
		$this->assertSame(['caseType' => 'ct-1'], $this->call('findDraft')['match']);
	}//end testShowAnswersTheLiveDraftsAnswersAndExpiry()

	/**
	 * The index lists each live draft with its label and moments, and nothing
	 * for an action without a draft declaration or a draft past retention.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
	 */
	public function testIndexListsTheLiveDrafts(): void {
		$labelled = self::ACTION + ['label' => 'Woo-verzoek'];
		$data     = $this->controller(subjectRef: 'sanne', found: $this->live(), action: $labelled)->index()->getData();

		$this->assertCount(expectedCount: 1, haystack: $data['drafts']);
		$first = $data['drafts'][0];
		$this->assertSame(expected: ['dossiq', 'request', 'Woo-verzoek'], actual: [$first['app'], $first['action'], $first['label']]);
		$this->assertNotSame(expected: '', actual: $data['drafts'][0]['expiresAt']);

		$undeclared = $labelled;
		unset($undeclared['draft']);
		$withoutDeclaration = $this->controller(subjectRef: 'sanne', found: $this->live(), action: $undeclared);
		$this->assertSame(expected: ['drafts' => []], actual: $withoutDeclaration->index()->getData());

		$old = ['id' => 'd-old', 'status' => 'draft', 'data' => ['title' => 'Old'], 'updatedAt' => '2026-01-01T00:00:00+00:00'];
		$this->assertSame(expected: ['drafts' => []], actual: $this->controller(subjectRef: 'sanne', found: $old)->index()->getData());

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(subjectRef: '')->index()->getStatus());
		$unreachable = $this->controller(subjectRef: 'sanne', registryFails: true);
		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $unreachable->index()->getStatus());
	}//end testIndexListsTheLiveDrafts()

	public function testADraftPastItsRetentionIsNotShownAndIsDiscarded(): void {
		$old      = ['id' => 'd-old', 'status' => 'draft', 'data' => ['title' => 'Old'], 'updatedAt' => '2026-01-01T00:00:00+00:00'];
		$response = $this->controller(subjectRef: 'sanne', found: $old)->show('dossiq', 'request');

		// No draft to resume is a quiet answer, not a 404 (REQ-RR3-005).
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['found' => false], $response->getData());
		$this->assertSame(['d-old'], $this->discarded);
	}//end testADraftPastItsRetentionIsNotShownAndIsDiscarded()

	/**
	 * No draft yet is a quiet 200 `{found: false}`; an unknown action stays 404.
	 *
	 * @spec openspec/changes/resident-pages-live-round-3/specs/site-forms/spec.md#requirement-a-form-asks-for-a-draft-only-when-its-action-can-keep-one-req-rr3-005
	 */
	public function testNoDraftIsAQuietAnswerAndAnUnknownActionIsNotFound(): void {
		$none = $this->controller(subjectRef: 'sanne')->show('dossiq', 'request');
		$this->assertSame(Http::STATUS_OK, $none->getStatus());
		$this->assertSame(['found' => false], $none->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(subjectRef: 'sanne')->show('dossiq', 'unknown')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(subjectRef: 'sanne')->save('other', 'request', [])->getStatus());
		$this->assertSame([], $this->fake->calls);
	}//end testNoDraftIsAQuietAnswerAndAnUnknownActionIsNotFound()

	public function testAnUnresolvedScopeClaimIsForbidden(): void {
		$controller = $this->controller(subjectRef: 'sanne', action: self::ACTION + ['scopeClaim' => 'missing']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->save('dossiq', 'request', ['title' => 'x'])->getStatus());
		$this->assertSame([], $this->fake->calls);
	}//end testAnUnresolvedScopeClaimIsForbidden()

	public function testDiscardRemovesOnlyTheLiveDraft(): void {
		$this->assertSame(['deleted' => true], $this->controller(subjectRef: 'sanne', found: $this->live())->discard('dossiq', 'request')->getData());
		$this->assertSame(['d-1'], $this->discarded);

		$this->assertSame(['deleted' => false], $this->controller(subjectRef: 'sanne')->discard('dossiq', 'request')->getData());
		$this->assertSame(['deleted' => false], $this->controller(subjectRef: 'sanne')->discard('dossiq', 'unknown')->getData());
	}//end testDiscardRemovesOnlyTheLiveDraft()

	public function testSignedOutIsRefusedOnEveryRoute(): void {
		$controller = $this->controller(subjectRef: '');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('dossiq', 'request')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->save('dossiq', 'request', [])->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->discard('dossiq', 'request')->getStatus());
		$this->assertSame([], $this->fake->calls);
	}//end testSignedOutIsRefusedOnEveryRoute()

	public function testAFailureBelowAnswersTryAgainLater(): void {
		$controller = $this->controller(subjectRef: 'sanne', registryFails: true);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->show('dossiq', 'request')->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->save('dossiq', 'request', [])->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->discard('dossiq', 'request')->getStatus());
	}//end testAFailureBelowAnswersTryAgainLater()

	/**
	 * A draft changed a moment ago.
	 *
	 * @return array<string, mixed>
	 */
	private function live(): array {
		return ['id' => 'd-1', 'status' => 'draft', 'data' => ['title' => 'Half', 'caseType' => 'ct-1', 'subjectRef' => 'sanne'], 'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
	}//end live()

	/**
	 * The first call of a kind the fake recorded.
	 *
	 * @return array<string, mixed>
	 */
	private function call(string $kind): array {
		foreach ($this->fake->calls as $call) {
			if (isset($call[$kind]) === true) {
				return $call;
			}
		}

		$this->fail('No ' . $kind . ' call.');
	}//end call()

	/**
	 * The controller over a fake OpenRegister.
	 *
	 * @param array<string, mixed>|\Throwable $outcome What saveDraft answers.
	 * @param array<string, mixed>|null       $found   What findDraft answers.
	 * @param array<string, mixed>            $action  The subject's create action.
	 */
	private function controller(string $subjectRef, array|\Throwable $outcome = [], ?array $found = null, array $action = self::ACTION, bool $registryFails = false): PortalDraftController {
		$session = $this->getMockBuilder(PortalSessionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveFromBearer'])
			->getMock();
		$session->method('resolveFromBearer')->willReturn($subjectRef === '' ? null : ['subjectRef' => $subjectRef, 'trust' => 'low']);

		$registry = $this->createMock(PortalContributionRegistry::class);
		if ($registryFails === true) {
			$registry->method('aggregateFor')->willThrowException(new \RuntimeException('registry gone'));
		}

		if ($registryFails === false) {
			$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'actions' => [$action]]]]);
		}
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturnCallback(
			static fn (string $scopeClaim, string $contributingApp, array $subject): ?string => ($scopeClaim === 'missing' ? null : (string)($subject['subjectRef'] ?? ''))
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('stamp')->willReturnCallback(
			static fn (string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array => array_merge($data, [$scopeField => $subjectRef])
		);

		$this->fake        = new FormSubmitFake(outcome: $outcome);
		$this->fake->found = $found;
		$this->discarded   = [];
		$discard           = new class($this->discarded) {
			public function __construct(private array &$discarded) {
			}

			public function discard(array $destination, string $draftId, object $owner): bool {
				$this->discarded[] = $draftId;
				return true;
			}
		};
		$drafts = new PortalOwnerDraft($this->fake->container(['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $discard]), $this->createMock(LoggerInterface::class), new DestinationSubmit($this->fake->container(['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $discard]), $this->createMock(LoggerInterface::class)));

		return new PortalDraftController(
			$this->createMock(IRequest::class),
			$session,
			new ActionDraft(registry: $registry, reader: $reader, writer: $writer, drafts: $drafts),
			$drafts
		);
	}//end controller()
}//end class
