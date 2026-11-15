<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormStatements;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * form-statements-intro-and-confirmation-mail T03: the statements a form asks,
 * the check that a required one is accepted, and the record of what was.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
 */
class FormStatementsTest extends TestCase {

	private function statements(): FormStatements {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new FormStatements($l10n);
	}//end statements()

	private function site(): array {
		return ['statementTexts' => ['truth' => ['text' => 'Klopt.', 'version' => '1'], 'privacy' => ['text' => 'Akkoord.', 'version' => '3']]];
	}//end site()

	public function testOnlyTheDeclaredStatementsAreAskedWithThePortalsWording(): void {
		$asked = $this->statements()->asked(['privacy' => ['required' => true], 'colour' => ['required' => true]], $this->site());

		$this->assertSame([['key' => 'privacy', 'required' => true, 'text' => 'Akkoord.', 'version' => '3']], $asked);
		$this->assertSame([], $this->statements()->asked(null, $this->site()));
	}//end testOnlyTheDeclaredStatementsAreAskedWithThePortalsWording()

	public function testARequiredStatementThatIsNotTickedIsAnError(): void {
		$statements = $this->statements();
		$asked = $statements->asked(['truth' => ['required' => true], 'privacy' => ['required' => true]], $this->site());

		$result = $statements->check($asked, ['truth']);

		$this->assertSame(['privacy'], array_keys($result['errors']));
		$this->assertSame(['truth'], array_column($result['record'], 'key'));
		$this->assertSame('1', $result['record'][0]['textVersion']);
	}//end testARequiredStatementThatIsNotTickedIsAnError()

	public function testAnOptionalStatementMayBeLeftAndAnInventedOneIsIgnored(): void {
		$statements = $this->statements();
		$asked = $statements->asked(['truth' => ['required' => false]], $this->site());

		$none = $statements->check($asked, ['invented']);
		$this->assertSame([], $none['errors']);
		$this->assertSame([], $none['record']);
		$this->assertSame([], $statements->check($asked, 'truth')['errors'], 'a malformed answer is not an error for an optional statement');
	}//end testAnOptionalStatementMayBeLeftAndAnInventedOneIsIgnored()

	public function testAStatementWithoutTextCanNeverBeAccepted(): void {
		$statements = $this->statements();
		$asked = $statements->asked(['truth' => ['required' => true]], []);

		$ticked = $statements->check($asked, ['truth']);
		$this->assertSame(['truth'], array_keys($ticked['errors']));
		$this->assertSame([], $ticked['record']);

		$optional = $statements->asked(['truth' => ['required' => false]], []);
		$this->assertSame([], $statements->check($optional, ['truth'])['record'], 'ticked but unreadable is not recorded');
	}//end testAStatementWithoutTextCanNeverBeAccepted()

	/**
	 * Every asked statement and tick is an error, a record or nothing, exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryStatementIsAsRecorded(): void {
		$st    = static fn (string $key, bool $required, string $text, string $version = 'v1'): array => ['key' => $key, 'required' => $required, 'text' => $text, 'version' => $version];
		$asked = [
			$st('needed-unreadable', true, ''),
			$st('needed-ticked', true, 'Text', 'v2'),
			$st('needed-left', true, 'Text'),
			$st('optional-ticked', false, 'Text', 'v3'),
			$st('optional-left', false, 'Text'),
			$st('optional-unreadable', false, ''),
		];

		$out = $this->statements()->check(asked: $asked, accepted: ['needed-unreadable', 'needed-ticked', 'optional-ticked', 'optional-unreadable', 'invented', 7, null]);

		$this->assertSame(
			[
				'needed-unreadable' => 'This form cannot be sent yet: a statement it needs has no text.',
				'needed-left' => 'Accept this statement before you send the form.',
			],
			$out['errors']
		);
		$this->assertSame(['needed-ticked', 'optional-ticked'], array_column($out['record'], 'key'));
		$this->assertSame(['v2', 'v3'], array_column($out['record'], 'textVersion'));
		$this->assertSame(['key', 'textVersion', 'acceptedAt'], array_keys($out['record'][0]));
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $out['record'][0]['acceptedAt']));

		$this->assertSame(['errors' => [], 'record' => []], $this->statements()->check(asked: [], accepted: 'x'));
		$this->assertSame(['needed-ticked', 'needed-left'], array_keys($this->statements()->check(asked: array_slice($asked, 1, 2), accepted: 'needed-x')['errors']));
	}//end testEveryStatementIsAsRecorded()
}
