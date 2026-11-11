<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ActionSummaryNormaliser;
use OCA\Portaliq\Contribution\FormStepDecision;
use OCA\Portaliq\Contribution\FormStepsNormaliser;
use OCA\Portaliq\Contribution\FormStepsRun;
use OCA\Portaliq\Contribution\FormText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The shape of kept steps: reviews, placed fields, ids and decisions.
 */
#[CoversClass(FormStepsNormaliser::class)]
#[UsesClass(ActionSummaryNormaliser::class)]
#[UsesClass(FormStepDecision::class)]
#[UsesClass(FormStepsRun::class)]
#[UsesClass(FormText::class)]
class FormStepsShapeTest extends TestCase {
	/**
	 * Only reviews is a one-page form; only the first review is kept, last.
	 *
	 * @return void
	 */
	public function testReviewsAndAOnePageForm(): void {
		$n = new FormStepsNormaliser();

		$this->assertSame([], $n->steps([['review' => true], ['review' => true, 'title' => 'Again']], ['a']));

		$steps = $n->steps(
			[['review' => true, 'title' => 'First'], ['id' => 'a1', 'fields' => ['a']], ['review' => true, 'title' => 'Second'], ['review' => true, 'fields' => ['b']]],
			['a', 'b']
		);
		$this->assertSame(['a1', 'more', 'step-1'], array_column($steps, 'id'));
		$this->assertSame('First', $steps[2]['title']);
		$this->assertSame(['b'], $steps[1]['fields']);
		$this->assertSame([], $steps[2]['fields']);
	}//end testReviewsAndAOnePageForm()

	/**
	 * A field held by an earlier step, an unknown or non-string field, or a
	 * step without fields drops the step; every field placed means no loose step.
	 *
	 * @return void
	 */
	public function testPlacedAndUnknownFieldsDropTheStep(): void {
		$steps = (new FormStepsNormaliser())->steps(
			[['id' => 'a1', 'fields' => ['a']], ['id' => 'again', 'fields' => ['a', 'b']], ['id' => 'odd', 'fields' => [5]], ['id' => 'none', 'fields' => []], ['id' => 'bad', 'fields' => 'b'], ['id' => 'b1', 'fields' => ['b']]],
			['a', 'b']
		);

		$this->assertSame(['a1', 'b1'], array_column($steps, 'id'));
		$this->assertSame(['id' => 'b1', 'title' => '', 'fields' => ['b']], $steps[1]);
	}//end testPlacedAndUnknownFieldsDropTheStep()

	/**
	 * Ids that are not plain tokens, or the loose step's own id, become step-N.
	 * A review step loses its decision; an empty description is left out.
	 *
	 * @return void
	 */
	public function testIdsDescriptionsAndDecisions(): void {
		$decision = ['rule' => 'r', 'output' => 'a', 'nextStep' => []];
		$steps    = (new FormStepsNormaliser())->steps(
			[
				['id' => 'more', 'fields' => ['a'], 'description' => ' ', 'decision' => $decision],
				['id' => '9lives', 'fields' => ['b'], 'title' => 5],
				['id' => 'check', 'review' => true, 'description' => ' Look ', 'decision' => $decision],
			],
			['a', 'b']
		);

		$this->assertSame(['step-1', 'step-2', 'check'], array_column($steps, 'id'));
		$this->assertArrayNotHasKey('description', $steps[0]);
		$this->assertSame(['rule' => 'r', 'inputs' => [], 'output' => 'a', 'nextStep' => []], $steps[0]['decision']);
		$this->assertSame('', $steps[1]['title']);
		$this->assertSame(['id' => 'check', 'title' => '', 'fields' => [], 'description' => 'Look', 'review' => true], $steps[2]);
	}//end testIdsDescriptionsAndDecisions()

	/**
	 * A string `summary` stays on a create action; an action whose steps all
	 * drop carries no `steps`.
	 *
	 * @return void
	 */
	public function testStringSummaryStaysAndEmptyPartsAreLeftOut(): void {
		$out = (new FormStepsNormaliser())->applyToAction(
			['type' => 'create', 'summary' => 'Start here', 'steps' => [['fields' => ['zzz']]], 'answerSummary' => null],
			['a']
		);

		$this->assertSame(['type' => 'create', 'summary' => 'Start here'], $out);
	}//end testStringSummaryStaysAndEmptyPartsAreLeftOut()
}//end class
