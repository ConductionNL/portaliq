<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Portaliq\Service\PollService;
use OCA\Portaliq\Service\PortalFieldProjector;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * parent-polls: a poll is created by staff for one audience/organisation, a
 * subject sees only their own audience/organisation and only their own
 * response, and a response is idempotent (create once, update after).
 *
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md
 */
class PollServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testCreatingAPollNeedsAtLeastTwoOptions(): void {
		$service = $this->service();

		$result = $service->create(
			question: 'Welke datum?',
			options: [['id' => 'a', 'label' => 'Dinsdag']],
			audience: 'parent',
			organisation: 'gemeente-x',
			createdBy: 'clerk-anna'
		);

		$this->assertSame(expected: 'needs_two_options', actual: $result['error']);
		$this->assertSame(expected: [], actual: $this->storedRows('portalPoll'));

	}//end testCreatingAPollNeedsAtLeastTwoOptions()

	public function testACreatedPollRecordsWhoMadeIt(): void {
		$service = $this->service();

		$result = $service->create(
			question: 'Welke datum?',
			options: [['id' => 'a', 'label' => 'Dinsdag'], ['id' => 'b', 'label' => 'Donderdag']],
			audience: 'parent',
			organisation: 'gemeente-x',
			createdBy: 'clerk-anna'
		);

		$this->assertArrayNotHasKey(key: 'error', array: $result);
		$this->assertSame(expected: 'clerk-anna', actual: $this->storedRows('portalPoll')[0]['createdBy']);

	}//end testACreatedPollRecordsWhoMadeIt()

	public function testASubjectSeesOnlyTheirOwnAudienceAndOrganisation(): void {
		$service = $this->service();
		$this->seedPoll(audience: 'parent', organisation: 'gemeente-x', question: 'A');
		$this->seedPoll(audience: 'parent', organisation: 'gemeente-y', question: 'B');
		$this->seedPoll(audience: 'teacher', organisation: 'gemeente-x', question: 'C');

		$polls = $service->forSubject(subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);

		$this->assertCount(expectedCount: 1, haystack: $polls);
		$this->assertSame(expected: 'A', actual: $polls[0]['question']);

	}//end testASubjectSeesOnlyTheirOwnAudienceAndOrganisation()

	public function testAReturnedPollCarriesOnlyThatSubjectsOwnResponse(): void {
		$service = $this->service();
		$pollId = $this->seedPoll(audience: 'parent', organisation: 'gemeente-x', question: 'A', options: [['id' => 'a', 'label' => 'Ja'], ['id' => 'b', 'label' => 'Nee']]);
		$this->seedRow('portalPollResponse', ['pollId' => $pollId, 'subjectRef' => 'guardian-1', 'optionId' => 'a']);
		$this->seedRow('portalPollResponse', ['pollId' => $pollId, 'subjectRef' => 'guardian-2', 'optionId' => 'b']);

		$polls = $service->forSubject(subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);

		$this->assertSame(expected: 'a', actual: $polls[0]['myResponse']);

	}//end testAReturnedPollCarriesOnlyThatSubjectsOwnResponse()

	public function testAnUnansweredPollCarriesNoResponse(): void {
		$service = $this->service();
		$this->seedPoll(audience: 'parent', organisation: 'gemeente-x', question: 'A');

		$polls = $service->forSubject(subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);

		$this->assertNull($polls[0]['myResponse']);

	}//end testAnUnansweredPollCarriesNoResponse()

	public function testASecondResponseUpdatesRatherThanDuplicates(): void {
		$service = $this->service();
		$pollId = $this->seedPoll(audience: 'parent', organisation: 'gemeente-x', question: 'A', options: [['id' => 'a', 'label' => 'Ja'], ['id' => 'b', 'label' => 'Nee']]);
		$subject = ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1'];

		$service->respond(pollId: $pollId, optionId: 'a', subject: $subject);
		$result = $service->respond(pollId: $pollId, optionId: 'b', subject: $subject);

		$this->assertArrayNotHasKey(key: 'error', array: $result);
		$this->assertCount(expectedCount: 1, haystack: $this->storedRows('portalPollResponse'));
		$this->assertSame(expected: 'b', actual: $this->storedRows('portalPollResponse')[0]['optionId']);

	}//end testASecondResponseUpdatesRatherThanDuplicates()

	public function testAnOptionThePollDoesNotDeclareIsRefused(): void {
		$service = $this->service();
		$pollId = $this->seedPoll(audience: 'parent', organisation: 'gemeente-x', question: 'A', options: [['id' => 'a', 'label' => 'Ja'], ['id' => 'b', 'label' => 'Nee']]);

		$result = $service->respond(pollId: $pollId, optionId: 'c', subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);

		$this->assertSame(expected: 'unknown_option', actual: $result['error']);
		$this->assertSame(expected: [], actual: $this->storedRows('portalPollResponse'));

	}//end testAnOptionThePollDoesNotDeclareIsRefused()

	public function testAClosedPollRefusesANewResponse(): void {
		$service = $this->service();
		$pollId = $this->seedPoll(
			audience: 'parent',
			organisation: 'gemeente-x',
			question: 'A',
			options: [['id' => 'a', 'label' => 'Ja'], ['id' => 'b', 'label' => 'Nee']],
			closesAt: (new DateTimeImmutable('-1 day'))->format(DATE_ATOM)
		);

		$result = $service->respond(pollId: $pollId, optionId: 'a', subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);

		$this->assertSame(expected: 'closed', actual: $result['error']);
		$this->assertSame(expected: [], actual: $this->storedRows('portalPollResponse'));

	}//end testAClosedPollRefusesANewResponse()

	public function testAPollOutsideTheSubjectsAudienceCannotBeAnsweredById(): void {
		$service = $this->service();
		$pollId = $this->seedPoll(audience: 'teacher', organisation: 'gemeente-x', question: 'A', options: [['id' => 'a', 'label' => 'Ja'], ['id' => 'b', 'label' => 'Nee']]);

		$result = $service->respond(pollId: $pollId, optionId: 'a', subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);

		$this->assertSame(expected: 'not_found', actual: $result['error']);

	}//end testAPollOutsideTheSubjectsAudienceCannotBeAnsweredById()

	/**
	 * Put a poll in the fake store.
	 *
	 * @param string $audience The poll's audience.
	 * @param string $organisation The poll's organisation.
	 * @param string $question The question.
	 * @param array<int, array<string, string>> $options The options, or a default two-option pair.
	 * @param string $closesAt ISO 8601, or '' for none.
	 *
	 * @return string The poll's uuid.
	 */
	private function seedPoll(string $audience, string $organisation, string $question, array $options = [], string $closesAt = ''): string {
		if ($options === []) {
			$options = [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']];
		}

		return $this->seedRow('portalPoll', [
			'question' => $question,
			'options' => $options,
			'audience' => $audience,
			'organisation' => $organisation,
			'closesAt' => $closesAt,
			'createdBy' => 'clerk-anna',
		]);
	}//end seedPoll()

	/**
	 * The poll list has no subject scope, so the tenant is its only boundary.
	 * Over the REAL reader, a poll stored without an organisation for the
	 * guardian's audience reaches no guardian, of this organisation or
	 * another (portaliq#801).
	 */
	public function testAPollWithoutAnOrganisationReachesNoGuardian(): void {
		$polls = [
			['id' => 'poll-a', 'uuid' => 'poll-a', 'question' => 'Van ons', 'audience' => 'parent', 'organisation' => 'gemeente-x', 'options' => []],
			['id' => 'poll-leeg', 'uuid' => 'poll-leeg', 'question' => 'Van niemand', 'audience' => 'parent', 'organisation' => '', 'options' => []],
		];
		$objectService = new class($polls) {
			private string $schema = '';

			/**
			 * @param array<int, array<string, mixed>> $polls The stored polls.
			 */
			public function __construct(
				private readonly array $polls,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->schema !== 'portalPoll') {
					return [];
				}

				return $this->polls;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new PollService(
			new PortalObjectReader($container, $logger, new PortalFieldProjector($logger)),
			$this->createMock(PortalObjectWriter::class)
		);

		$ours = $service->forSubject(subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);
		$theirs = $service->forSubject(subject: ['audience' => 'parent', 'organisation' => 'gemeente-y', 'subjectRef' => 'guardian-2']);

		$this->assertSame(['Van ons'], array_column($ours, 'question'));
		$this->assertSame([], $theirs);

	}//end testAPollWithoutAnOrganisationReachesNoGuardian()

	/**
	 * The service over the fake store.
	 *
	 * @return PollService
	 */
	private function service(): PollService {
		return new PollService($this->fakeReader(), $this->fakeWriter());
	}//end service()

}//end class
