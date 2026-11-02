<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ActionSummaryNormaliser;
use OCA\Portaliq\Contribution\FormStepDecision;
use OCA\Portaliq\Contribution\FormStepsNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Applying step, draft, confirmation and summary declarations to an action.
 */
#[CoversClass(FormStepsNormaliser::class)]
#[UsesClass(ActionSummaryNormaliser::class)]
#[UsesClass(FormStepDecision::class)]
class FormStepsApplyTest extends TestCase {
	/**
	 * Actions that do not carry a form lose the form-only keys.
	 *
	 * @return void
	 */
	public function testNonFormActionsAreStripped(): void {
		$out = (new FormStepsNormaliser())->applyToAction(['type' => 'update', 'steps' => [1], 'draft' => [2], 'confirmation' => [3], 'summary' => [4], 'id' => 'x'], ['a']);

		$this->assertSame(['type' => 'update', 'id' => 'x'], $out);
	}//end testNonFormActionsAreStripped()

	/**
	 * A create action keeps sound steps, draft, confirmation and answer sentence.
	 *
	 * @return void
	 */
	public function testCreateActionKeepsSoundDeclarations(): void {
		$out = (new FormStepsNormaliser())->applyToAction(
			[
				'type' => 'create',
				'steps' => [['id' => 'one', 'title' => ' One ', 'description' => 'd', 'fields' => ['a', 'a']], ['fields' => ['zzz']], 'junk', ['review' => true, 'title' => 'Check']],
				'draft' => ['retentionDays' => 500],
				'confirmation' => ['title' => ' Thanks ', 'body' => 'Done', 'next' => ''],
				'summary' => ['template' => 'You said {a}', 'label' => 'L'],
			],
			['a', 'b']
		);

		$this->assertSame(['one', 'more', 'step-4'], array_column($out['steps'], 'id'));
		$this->assertSame('One', $out['steps'][0]['title']);
		$this->assertSame('d', $out['steps'][0]['description']);
		$this->assertSame(['a'], $out['steps'][0]['fields']);
		$this->assertSame(['b'], $out['steps'][1]['fields']);
		$this->assertTrue($out['steps'][2]['review']);
		$this->assertSame(['retentionDays' => 90], $out['draft']);
		$this->assertSame(['title' => 'Thanks', 'body' => 'Done'], $out['confirmation']);
		$this->assertSame('You said {a}', $out['answerSummary']['template'], 'the old object shape moves to answerSummary');
		$this->assertArrayNotHasKey('summary', $out);
	}//end testCreateActionKeepsSoundDeclarations()

	/**
	 * An endpoint action with a whitelist also flows; unsound declarations vanish.
	 *
	 * @return void
	 */
	public function testEndpointActionAndRejections(): void {
		$n = new FormStepsNormaliser();

		$out = $n->applyToAction(['endpoint' => '/x', 'steps' => 'bad', 'draft' => ['retentionDays' => '5'], 'confirmation' => ['body' => 'no title'], 'summary' => ['template' => 'no fields']], ['a']);
		$this->assertSame(['endpoint' => '/x'], $out);

		$noList = $n->applyToAction(['endpoint' => '/x', 'draft' => ['retentionDays' => 5]], []);
		$this->assertSame(['endpoint' => '/x'], $noList);
	}//end testEndpointActionAndRejections()

	/**
	 * Draft retention is clamped to 1..90 days.
	 *
	 * @return void
	 */
	public function testDraftAndConfirmation(): void {
		$n = new FormStepsNormaliser();

		$this->assertSame(['retentionDays' => 1], $n->draft(['retentionDays' => 0]));
		$this->assertSame(['retentionDays' => 30], $n->draft(['retentionDays' => 30]));
		$this->assertNull($n->draft('x'));
		$this->assertNull($n->confirmation('x'));
		$this->assertNull($n->confirmation(['title' => str_repeat('a', 2001)]));
		$this->assertSame(['title' => 'T', 'next' => 'N'], $n->confirmation(['title' => 'T', 'next' => ' N ', 'body' => 5]));
	}//end testDraftAndConfirmation()

	/**
	 * Decision targets that are not step ids are dropped.
	 *
	 * @return void
	 */
	public function testDecisionTargetsMustBeSteps(): void {
		$steps = (new FormStepsNormaliser())->steps(
			[['id' => 's1', 'fields' => ['a'], 'decision' => ['rule' => 'r', 'output' => 'a', 'nextStep' => ['yes' => 's2', 'no' => 'ghost']]], ['id' => 's2', 'fields' => ['b']]],
			['a', 'b']
		);

		$this->assertSame(['yes' => 's2'], $steps[0]['decision']['nextStep']);
		$this->assertSame([], (new FormStepsNormaliser())->steps('x', ['a']));
	}//end testDecisionTargetsMustBeSteps()
}
