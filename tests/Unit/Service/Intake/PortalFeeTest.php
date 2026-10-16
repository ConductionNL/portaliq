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
		$fee = $this->fee(['portalFee' => ['amount' => '45', 'currency' => 'eur', 'description' => ' Parkeren ', 'payAction' => 'create-payment']]);

		$this->assertSame(
			['amount' => '45.00', 'currency' => 'EUR', 'description' => 'Parkeren', 'payAction' => 'create-payment'],
			$fee->forBinding(['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 't1'])
		);
	}//end testTheDeclaredFeeIsReadFromTheCaseType()

	public function testAnythingThatIsNotAUsableFeeIsNoFee(): void {
		$binding = ['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 't1'];
		$bad = [
			null,
			'45.00',
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

	public function testAFeeMakesTheFormNeedASessionAtSubstantial(): void {
		$level = new PortalFormTrustLevel();

		$this->assertSame('substantial', $level->required([], [], ['fee' => ['amount' => '45.00']]));
		$this->assertNull($level->required([], [], ['fee' => null]));
		$this->assertSame('high', $level->required([], ['minTrust' => 'high'], ['fee' => ['amount' => '45.00']]));
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
