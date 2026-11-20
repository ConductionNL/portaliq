<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormStepFetches;
use OCA\Portaliq\Service\Intake\PortalFormFetch;
use OCA\Portaliq\Service\Intake\SubmissionDecisions;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\ServiceFetchEventFake;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * data-lookups-and-checks-in-forms REQ-DIF-005: the fetches a form's steps
 * declare, as the browser sees them, as one step runs them and as a
 * submission takes their values again.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
#[CoversClass(FormStepFetches::class)]
#[UsesClass(PortalFormFetch::class)]
#[UsesClass(SubmissionDecisions::class)]
class FormStepFetchesTest extends TestCase {

	/**
	 * A form whose second step asks the Kadaster who owns the building.
	 *
	 * @return array<string, mixed>
	 */
	private function fetchForm(): array {
		$check = [
			'label' => 'Wij controleren of u eigenaar bent',
			'output' => 'isEigenaar',
			'lines' => ['true' => 'Volgens het Kadaster staat dit pand op uw naam.'],
		];

		return [
			'fields' => [['name' => 'postcode'], ['name' => 'eigenaar'], ['name' => 'naam']],
			'steps' => [
				['id' => 'adres', 'fields' => ['postcode']],
				[
					'id' => 'pand',
					'fields' => ['eigenaar', 'naam'],
					'fetch' => [[
						'id' => 'eigenaar',
						'source' => 'kadaster-eigendom',
						'inputs' => ['postcode' => 'postcode'],
						'outputs' => ['eigenaarNaam' => 'eigenaar'],
						'check' => $check,
					]],
				],
			],
		];
	}//end fetchForm()

	/**
	 * Integriq's fetch, answered by $answer, or left unhandled when null.
	 *
	 * @param callable|null $answer     Answers the dispatched command.
	 * @param array         $dispatched Receives every dispatched command.
	 *
	 * @return PortalFormFetch
	 */
	private function fetches(?callable $answer, array &$dispatched = []): PortalFormFetch {
		$events = $this->createMock(originalClassName: IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($answer, &$dispatched): void {
				$dispatched[] = $event;
				if ($answer !== null) {
					$answer($event);
				}
			}
		);

		return new PortalFormFetch(
			events: $events,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			fetchEvent: ServiceFetchEventFake::class
		);
	}//end fetches()

	/**
	 * The step's fetch answers the fields it fills and its check line, with
	 * only the form's own answers sent; an unknown fetch is a 404 and a
	 * source that is down a 503.
	 *
	 * @return void
	 */
	public function testAnswerForStepAnswersTheFieldsAndTheCheckLine(): void {
		$dispatched  = [];
		$owner       = static function (ServiceFetchEventFake $event): void {
			$event->setOutputs(outputs: ['eigenaarNaam' => 'S. de Vries', 'isEigenaar' => true], fetchedAt: '2026-11-17T12:00:00Z');
		};
		$stepFetches = new FormStepFetches(fetches: $this->fetches(answer: $owner, dispatched: $dispatched));

		$response = $stepFetches->answerForStep(
			render: $this->fetchForm(),
			step: 'pand',
			fetch: 'eigenaar',
			answers: ['postcode' => '3311AB', 'junk' => 'x']
		);
		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'fetch' => 'eigenaar',
				'values' => ['eigenaar' => 'S. de Vries'],
				'check' => 'Volgens het Kadaster staat dit pand op uw naam.',
				'fetchedAt' => '2026-11-17T12:00:00Z',
			],
			actual: $response->getData()
		);
		$this->assertSame(expected: ['postcode' => '3311AB'], actual: $dispatched[0]->inputs);

		$unknown = $stepFetches->answerForStep(render: $this->fetchForm(), step: 'adres', fetch: 'eigenaar', answers: []);
		$this->assertSame(expected: 404, actual: $unknown->getStatus());
		$noService = (new FormStepFetches())->answerForStep(render: $this->fetchForm(), step: 'pand', fetch: 'eigenaar', answers: []);
		$this->assertSame(expected: 404, actual: $noService->getStatus(), message: 'no fetch service, nothing to run');

		$refuse = static function (ServiceFetchEventFake $event): void {
			$event->refuse(refusal: 'timeout');
		};
		$down   = new FormStepFetches(fetches: $this->fetches(answer: $refuse));
		$failed = $down->answerForStep(render: $this->fetchForm(), step: 'pand', fetch: 'eigenaar', answers: []);
		$this->assertSame(expected: 503, actual: $failed->getStatus());
		$this->assertSame(expected: ['error' => 'fetch_unavailable'], actual: $failed->getData());
	}//end testAnswerForStepAnswersTheFieldsAndTheCheckLine()

	/**
	 * On submit the field a fetch fills holds what the source says; a source
	 * that is down, or no fetch service, refuses; a form without fetches
	 * keeps its answers.
	 *
	 * @return void
	 */
	public function testFilledAnswersTakeTheSourceValues(): void {
		$steps   = $this->fetchForm()['steps'];
		$owner   = static function (ServiceFetchEventFake $event): void {
			$event->setOutputs(outputs: ['eigenaarNaam' => 'S. de Vries'], fetchedAt: 'now');
		};
		$fetches = new FormStepFetches(fetches: $this->fetches(answer: $owner));

		$filled = $fetches->filledAnswers(steps: $steps, answers: ['postcode' => '3311AB', 'eigenaar' => 'Iemand anders']);
		$this->assertSame(expected: ['postcode' => '3311AB', 'eigenaar' => 'S. de Vries'], actual: $filled);

		$down = new FormStepFetches(fetches: $this->fetches(answer: null));
		$this->assertInstanceOf(expected: JSONResponse::class, actual: $down->filledAnswers(steps: $steps, answers: []));
		$this->assertInstanceOf(expected: JSONResponse::class, actual: (new FormStepFetches())->filledAnswers(steps: $steps, answers: []));

		$plain = (new FormStepFetches())->filledAnswers(steps: [['id' => 'x'], 'junk'], answers: ['a' => 1]);
		$this->assertSame(expected: ['a' => 1], actual: $plain);
	}//end testFilledAnswersTakeTheSourceValues()

	/**
	 * The browser sees the id, the fields and the check label of a fetch,
	 * never its source or inputs; a malformed entry is dropped.
	 *
	 * @return void
	 */
	public function testForTheBrowserHidesTheSource(): void {
		$fetch = $this->fetchForm()['steps'][1]['fetch'];
		$this->assertSame(
			expected: [['id' => 'eigenaar', 'fields' => ['eigenaar'], 'checkLabel' => 'Wij controleren of u eigenaar bent']],
			actual: (new FormStepFetches())->forTheBrowser(fetches: array_merge($fetch, ['junk']))
		);
	}//end testForTheBrowserHidesTheSource()
}//end class
