<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormConfirmationSummary;
use PHPUnit\Framework\TestCase;

/**
 * form-statements-intro-and-confirmation-mail T05: the summary repeats the
 * visible answers and nothing sensitive.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
 */
class FormConfirmationSummaryTest extends TestCase {

	public function testTheSummaryHoldsLabelledAnswersInOrderAndWithholdsTheRest(): void {
		$fields = [
			['name' => 'naam', 'label' => 'Naam'],
			['name' => 'bsn', 'label' => 'BSN', 'format' => 'bsn'],
			['name' => 'bsn2', 'label' => 'BSN van de partner', 'type' => 'string', 'sensitive' => true],
			['name' => 'bijlage', 'label' => 'Bijlage', 'type' => 'file'],
			['name' => 'handtekening', 'label' => 'Handtekening', 'type' => 'signature'],
			['name' => 'mee', 'label' => 'Wie verhuist mee', 'type' => 'familyMembers'],
			['name' => 'woning', 'label' => 'Soort woning', 'options' => [['value' => 'flat', 'label' => 'Flat']]],
			['name' => 'adres', 'label' => 'Adres', 'type' => 'addressNL'],
			['name' => 'leeg', 'label' => 'Leeg'],
			['name' => 'zonderlabel'],
		];
		$answers = [
			'naam' => 'Sanne', 'bsn' => '111222333', 'bsn2' => '999993653', 'bijlage' => 'x.pdf', 'handtekening' => 'data:image/png;base64,AAA',
			'mee' => ['partner-aaaaaaaaaaaaaaaaaaaa'], 'woning' => 'flat', 'leeg' => '  ', 'zonderlabel' => 'ja',
			'adres' => ['postcode' => '1234 AB', 'number' => '12', 'letter' => 'A', 'addition' => '', 'street' => 'Lindelaan', 'town' => 'Zuiddrecht'],
		];

		$lines = (new FormConfirmationSummary())->build($fields, $answers);

		$this->assertSame(
			[
				['label' => 'Naam', 'value' => 'Sanne'],
				['label' => 'Soort woning', 'value' => 'Flat'],
				['label' => 'Adres', 'value' => 'Lindelaan 12A, 1234 AB Zuiddrecht'],
				['label' => 'zonderlabel', 'value' => 'ja'],
			],
			$lines
		);
		$all = json_encode($lines);
		foreach (['111222333', '999993653', 'x.pdf', 'base64', 'partner-'] as $secret) {
			$this->assertStringNotContainsString($secret, $all);
		}
	}//end testTheSummaryHoldsLabelledAnswersInOrderAndWithholdsTheRest()
}
