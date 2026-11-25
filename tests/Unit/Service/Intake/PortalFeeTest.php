<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\PortalFee;
use OCA\Portaliq\Service\Intake\PortalFormTrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * intake-pay-on-submit T03 and T04: the fee is the case type's declaration in
 * a safe shape, it makes the form need a session, and a checkout is followed
 * only on a declared https host.
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t03
 */
class PortalFeeTest extends TestCase {

	private function fee(?array $caseType = null): PortalFee {
		$reader = $this->createMock(CaseTypeReader::class);
		$reader->method('readCaseType')->willReturn($caseType);

		return new PortalFee($reader);
	}//end fee()

	public function testTheDeclaredFeeIsReadFromTheCaseType(): void {
		$declared = ['amount' => '38.5', 'currency' => 'eur', 'description' => ' Parkeren ', 'payAction' => 'create-payment'];
		$fee      = $this->fee(caseType: ['portalFee' => $declared]);

		$this->assertSame(
			['amount' => '38.50', 'currency' => 'EUR', 'description' => 'Parkeren', 'payAction' => 'create-payment'],
			$fee->forBinding(['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 't1'])
		);
	}//end testTheDeclaredFeeIsReadFromTheCaseType()

	public function testAnythingThatIsNotAUsableFeeIsNoFee(): void {
		$binding = ['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 't1'];
		$bad = [
			null,
			'38.50',
			['amount' => '0', 'currency' => 'EUR', 'payAction' => 'x'],
			['amount' => '-5', 'currency' => 'EUR', 'payAction' => 'x'],
			['amount' => '12,50', 'currency' => 'EUR', 'payAction' => 'x'],
			['amount' => '12.505', 'currency' => 'EUR', 'payAction' => 'x'],
			['amount' => '12.50', 'currency' => 'EURO', 'payAction' => 'x'],
			['amount' => '12.50', 'currency' => 'EUR', 'payAction' => ''],
		];
		foreach ($bad as $declared) {
			$this->assertNull($this->fee(['portalFee' => $declared])->forBinding($binding), json_encode($declared));
		}

		$this->assertNull($this->fee(null)->forBinding($binding), 'no case type, no fee');
	}//end testAnythingThatIsNotAUsableFeeIsNoFee()

	/**
	 * T09: the form's own fee sets the amount, currency and description; the
	 * case type keeps the pay action. A callable is read only when the case
	 * type declares a fee.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function testTheFormsFeeReplacesTheAmountAndKeepsThePayAction(): void {
		$binding  = ['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 't1'];
		$declared = ['amount' => '38.50', 'currency' => 'EUR', 'description' => 'Parkeren', 'payAction' => 'create-payment'];
		$form     = ['name' => 'Standplaats', 'fee' => ['amount' => 25, 'currency' => 'EUR', 'description' => 'Leges standplaats']];

		$expected = ['amount' => '25.00', 'currency' => 'EUR', 'description' => 'Leges standplaats', 'payAction' => 'create-payment'];
		$this->assertSame($expected, $this->fee(['portalFee' => $declared])->forBinding($binding, $form));
		$this->assertSame($expected, $this->fee(['portalFee' => $declared])->forBinding($binding, static fn (): array => $form), 'a callable form reads the same');

		$this->assertSame(
			['amount' => '12.50', 'currency' => 'USD', 'description' => '', 'payAction' => 'create-payment'],
			$this->fee(['portalFee' => $declared])->forBinding($binding, ['fee' => ['amount' => 12.5, 'currency' => 'usd']]),
			'a currency the form names is used, a missing description stays empty'
		);

		$this->assertSame('38.50', $this->fee(['portalFee' => $declared])->forBinding($binding, ['fee' => null])['amount'], 'a form without a fee keeps the case type\'s');
		$this->assertSame('38.50', $this->fee(['portalFee' => $declared])->forBinding($binding, null)['amount']);
		$this->assertNull($this->fee(['portalFee' => $declared])->forBinding($binding, ['fee' => ['amount' => -1]]), 'a form fee that is no amount is no fee');

		$read = false;
		$this->assertNull(
			$this->fee(['name' => 'free'])->forBinding($binding, static function () use (&$read): array {
				$read = true;
				return [];
			})
		);
		$this->assertFalse($read, 'a case type without a fee does not read the form');
	}//end testTheFormsFeeReplacesTheAmountAndKeepsThePayAction()

	/**
	 * T09: the pay action comes from the case type, so a form fee on a case
	 * type that names none cannot be paid and is no fee.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t09
	 */
	public function testAFormFeeWithoutAPayActionIsNoFee(): void {
		$binding = ['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 't1'];
		$form    = ['fee' => ['amount' => 25, 'currency' => 'EUR', 'description' => 'Leges']];

		$this->assertNull($this->fee(['portalFee' => ['amount' => '10.00']])->forBinding($binding, $form));
		$this->assertSame(
			'25.00',
			$this->fee(['portalFee' => ['payAction' => 'create-payment']])->forBinding($binding, $form)['amount'],
			'a case type that only names the pay action takes the form\'s amount'
		);
	}//end testAFormFeeWithoutAPayActionIsNoFee()

	public function testAFeeMakesTheFormNeedASessionAtSubstantial(): void {
		$level = new PortalFormTrustLevel();

		$this->assertSame(expected: 'substantial', actual: $level->required([], [], ['fee' => ['amount' => '38.50']]));
		$this->assertNull($level->required([], [], ['fee' => null]));
		$this->assertSame(expected: 'high', actual: $level->required([], ['minTrust' => 'high'], ['fee' => ['amount' => '38.50']]));
	}//end testAFeeMakesTheFormNeedASessionAtSubstantial()

	public function testACheckoutIsFollowedOnlyOnADeclaredHttpsHost(): void {
		$fee   = $this->fee();
		$hosts = ['www.mollie.com', 'PAY.example.nl'];

		$this->assertTrue($fee->checkoutAllowed('https://www.mollie.com/checkout/abc?x=1', $hosts));
		$this->assertTrue($fee->checkoutAllowed('https://pay.example.nl/x', $hosts), 'host names compare without case');
		foreach (['http://www.mollie.com/x', 'https://evil.example/x', 'https://www.mollie.com.evil.example/x', 'https://user:pw@www.mollie.com/x', '//www.mollie.com/x', 'javascript:alert(1)', 'https://www.mollie.com/x y', "https://www.mollie.com/\n", 'https://www.mollie.com\\@evil.example/', '', null, ['https://www.mollie.com']] as $url) {
			$this->assertFalse($fee->checkoutAllowed($url, $hosts), json_encode($url));
		}

		$this->assertFalse($fee->checkoutAllowed('https://www.mollie.com/x', []), 'no declared host, nothing is followed');
	}//end testACheckoutIsFollowedOnlyOnADeclaredHttpsHost()

	public function testTheProvidersStatusBecomesWhatTheResidentReads(): void {
		$fee = $this->fee();

		$this->assertSame(
			['paid', 'paid', 'unpaid', 'unpaid', 'failed', 'failed', 'failed', 'unknown', 'unknown'],
			array_map($fee->stateOf(...), ['paid', 'authorized', 'open', 'pending', 'failed', 'canceled', 'expired', 'refunded', 'whatever'])
		);
	}//end testTheProvidersStatusBecomesWhatTheResidentReads()
}
