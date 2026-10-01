<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\PortalIntakeFee;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * intake-pay-on-submit REQ-IPS-001: the fee is read only from the case
 * type's declaration, and a declaration that is not complete is no fee, the
 * same declarations the real register schema refuses.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-fee-comes-from-the-case-type-req-ips-001
 */
class PortalIntakeFeeTest extends TestCase {

	/**
	 * Declarations the register schema accepts, and the fee the portal reads.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
	 */
	public static function validFees(): array {
		$base = ['payApp' => 'dossiq', 'payAction' => 'pay-intake-fee'];

		return [
			'two decimals' => [['amount' => '45.00'] + $base, ['amount' => '45.00', 'currency' => 'EUR', 'description' => '', 'payApp' => 'dossiq', 'payAction' => 'pay-intake-fee']],
			'whole euros' => [['amount' => '45'] + $base, ['amount' => '45.00', 'currency' => 'EUR', 'description' => '', 'payApp' => 'dossiq', 'payAction' => 'pay-intake-fee']],
			'one decimal, another currency' => [['amount' => '7.5', 'currency' => 'USD', 'description' => 'Fee'] + $base, ['amount' => '7.50', 'currency' => 'USD', 'description' => 'Fee', 'payApp' => 'dossiq', 'payAction' => 'pay-intake-fee']],
		];

	}//end validFees()

	/**
	 * @param array<string, mixed> $declared The declaration.
	 * @param array<string, string> $expected What the portal reads.
	 *
	 * @return void
	 */
	#[DataProvider('validFees')]
	public function testAFeeIsReadFromTheCaseType(array $declared, array $expected): void {
		$this->assertTrue($this->schemaAccepts(fee: $declared), 'the declaration fits the register schema');
		$this->assertSame($expected, $this->fee(caseType: ['title' => 'Parkeren', 'portalFee' => $declared])->feeFor(binding: $this->binding()));

	}//end testAFeeIsReadFromTheCaseType()

	/**
	 * Declarations the register schema refuses are no fee either.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalidFees(): array {
		$base = ['payApp' => 'dossiq', 'payAction' => 'pay-intake-fee'];

		return [
			'no amount' => [$base],
			'a comma' => [['amount' => '45,00'] + $base],
			'three decimals' => [['amount' => '45.001'] + $base],
			'negative' => [['amount' => '-1'] + $base],
			'lower-case currency' => [['amount' => '45', 'currency' => 'eur'] + $base],
			'no pay action' => [['amount' => '45', 'payApp' => 'dossiq']],
			'no paying app' => [['amount' => '45', 'payAction' => 'pay']],
		];

	}//end invalidFees()

	/**
	 * @param array<string, mixed> $declared The declaration.
	 *
	 * @return void
	 */
	#[DataProvider('invalidFees')]
	public function testAnIncompleteDeclarationIsNoFee(array $declared): void {
		$this->assertFalse($this->schemaAccepts(fee: $declared), 'the register schema refuses it too');
		$this->assertNull($this->fee(caseType: ['title' => 'Parkeren', 'portalFee' => $declared])->feeFor(binding: $this->binding()));

	}//end testAnIncompleteDeclarationIsNoFee()

	public function testZeroIsNoFee(): void {
		$this->assertNull($this->fee(caseType: ['portalFee' => ['amount' => '0.00', 'payApp' => 'a', 'payAction' => 'b']])->feeFor(binding: $this->binding()));

	}//end testZeroIsNoFee()

	public function testACaseTypeWithoutAFeeOrNotFoundIsFree(): void {
		$this->assertNull($this->fee(caseType: ['title' => 'Kapvergunning'])->feeFor(binding: $this->binding()));
		$this->assertNull($this->fee(caseType: null)->feeFor(binding: $this->binding()));

	}//end testACaseTypeWithoutAFeeOrNotFoundIsFree()

	/**
	 * The fee reader over one case type.
	 *
	 * @param array<string, mixed>|null $caseType What the case type read answers.
	 *
	 * @return PortalIntakeFee
	 */
	private function fee(?array $caseType): PortalIntakeFee {
		$records = $this->createMock(CaseTypeReader::class);
		$records->method('readCaseType')->with('dossiq', 'zaaktype', 'parkeren')->willReturn($caseType);

		return new PortalIntakeFee($records);
	}//end fee()

	/**
	 * A binding for the parking permit case type.
	 *
	 * @return array<string, string>
	 */
	private function binding(): array {
		return ['typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 'parkeren'];
	}//end binding()

	/**
	 * Whether the real register schema accepts a fee declaration.
	 *
	 * @param array<string, mixed> $fee The declaration.
	 *
	 * @return bool
	 */
	private function schemaAccepts(array $fee): bool {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../../lib/Settings/portaliq_register.json'), true);
		$schema = $register['components']['schemas']['portalCaseType'];
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'properties' => $schema['properties']]), false);

		return (new Validator())->validate(json_decode((string)json_encode(['title' => 'Parkeren', 'portalFee' => $fee]), false), $jsonSchema)->isValid();
	}//end schemaAccepts()
}//end class
