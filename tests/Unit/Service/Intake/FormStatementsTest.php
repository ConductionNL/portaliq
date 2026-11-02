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
}
