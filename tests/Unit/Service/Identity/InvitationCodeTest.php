<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\InvitationCode;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * invitation-code-from-a-letter REQ-PIS-009: a code a person can read from
 * paper and type, forgiving in how it is typed and in nothing else.
 *
 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
 */
class InvitationCodeTest extends TestCase {

	public function testTheAlphabetLeavesOutWhatPeopleMixUp(): void {
		$this->assertSame(32, strlen(InvitationCode::ALPHABET));
		$this->assertSame(32, count(array_unique(str_split(InvitationCode::ALPHABET))));
		foreach (['0', 'O', '1', 'I'] as $confusing) {
			$this->assertStringNotContainsString($confusing, InvitationCode::ALPHABET);
		}

		// 32 symbols, 12 places: 60 bits.
		$this->assertSame(12, InvitationCode::LENGTH);

	}//end testTheAlphabetLeavesOutWhatPeopleMixUp()

	public function testACodeIsMintedFromTheSecureSourceOverTheAlphabet(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->once())->method('generate')->with(12, InvitationCode::ALPHABET)->willReturn('ABCDEFGH2345');

		$this->assertSame('ABCDEFGH2345', (new InvitationCode())->mint($random));

	}//end testACodeIsMintedFromTheSecureSourceOverTheAlphabet()

	public function testASourceThatGivesSomethingElseMintsNothing(): void {
		foreach (['', 'ABC', 'abcdefgh2345', 'ABCDEFGH234O'] as $bad) {
			$random = $this->createMock(ISecureRandom::class);
			$random->method('generate')->willReturn($bad);

			$this->assertSame('', (new InvitationCode())->mint($random), $bad);
		}

	}//end testASourceThatGivesSomethingElseMintsNothing()

	public function testACodeIsShownInThreeGroupsOfFour(): void {
		$this->assertSame('ABCD-EFGH-2345', (new InvitationCode())->shown('ABCDEFGH2345'));

	}//end testACodeIsShownInThreeGroupsOfFour()

	public function testCaseSpacesAndDashesAreForgiven(): void {
		$codes = new InvitationCode();
		foreach (['ABCD-EFGH-2345', 'abcd-efgh-2345', 'abcdefgh2345', ' ABCD EFGH 2345 ', "ab cd-ef\tgh--2345"] as $typed) {
			$this->assertSame('ABCDEFGH2345', $codes->normalise($typed), $typed);
		}

	}//end testCaseSpacesAndDashesAreForgiven()

	public function testAnythingElseIsNotACode(): void {
		$codes = new InvitationCode();
		$link  = 'm83wy9aqmilm7y3fjke2olhxkqu8cj7vf8qeqnbysvt6oqtr';
		foreach (['', 'ABCD-EFGH-234', 'ABCD-EFGH-23456', 'ABCD-EFGH-234O', 'ABCD_EFGH_2345', 'ABCD-EFGH-234!', $link] as $typed) {
			$this->assertSame('', $codes->normalise($typed), $typed);
		}

	}//end testAnythingElseIsNotACode()

	/**
	 * The generator itself, not a fixed double: a source that draws each
	 * character uniformly from the characters it is given, as Nextcloud's
	 * SecureRandom does (its class is not loadable outside a server, so the
	 * same draw stands in for it). Every code is twelve characters of the
	 * alphabet, and two hundred draws give two hundred codes.
	 *
	 * @return void
	 */
	public function testTheGeneratorGivesTwelveCharactersOfTheAlphabet(): void {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length, string $characters = ISecureRandom::CHAR_HUMAN_READABLE): string {
				$out = '';
				for ($i = 0; $i < $length; $i++) {
					$out .= $characters[random_int(0, (strlen($characters) - 1))];
				}

				return $out;
			}
		);

		$codes = [];
		for ($draw = 0; $draw < 200; $draw++) {
			$code = (new InvitationCode())->mint($random);
			$this->assertSame(InvitationCode::LENGTH, strlen($code), $code);
			$this->assertSame(InvitationCode::LENGTH, strspn($code, InvitationCode::ALPHABET), $code);
			$codes[$code] = true;
		}

		$this->assertCount(200, $codes);

	}//end testTheGeneratorGivesTwelveCharactersOfTheAlphabet()

	/**
	 * Security review M4: the stored hash is keyed. It is not the plain
	 * SHA-256 an offline attacker could compute, it changes with the
	 * instance secret, and without a secret there is no hash at all.
	 *
	 * @return void
	 */
	public function testTheStoredHashIsKeyedWithTheInstanceSecret(): void {
		$codes = new InvitationCode();
		$hash  = $codes->keyedHash('ABCDEFGH2345', 'instance-secret');

		$this->assertSame(64, strlen($hash));
		$this->assertNotSame(hash('sha256', 'ABCDEFGH2345'), $hash);
		$this->assertSame($hash, $codes->keyedHash('ABCDEFGH2345', 'instance-secret'), 'The same code and key give the same hash.');
		$this->assertNotSame($hash, $codes->keyedHash('ABCDEFGH2345', 'another-secret'));
		$this->assertNotSame($hash, $codes->keyedHash('ABCDEFGH2346', 'instance-secret'));
		$this->assertSame('', $codes->keyedHash('ABCDEFGH2345', ''));
		$this->assertSame('', $codes->keyedHash('', 'instance-secret'));

	}//end testTheStoredHashIsKeyedWithTheInstanceSecret()
}//end class
