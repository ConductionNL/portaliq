<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PortalFileReaderInterface;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\PortalImageChoice;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The save rules of a portal's logo, favicon and hero image (REQ-PIA-001).
 *
 * @covers \OCA\Portaliq\Service\Cms\PortalImageChoice
 * @uses \OCA\Portaliq\Service\Cms\MediaReferences
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */
final class PortalImageChoiceTest extends TestCase {

	/**
	 * The rules over a library holding `$items` of portal `gemeente` and files per item.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $files Files by media id.
	 */
	private function choice(array $files): PortalImageChoice {
		$library = $this->createMock(MediaLibraryReader::class);
		$library->method('item')->willReturnCallback(
			static function (string $portal, string $id) use ($files): ?array {
				if ($portal !== 'gemeente' || array_key_exists($id, $files) === false) {
					return null;
				}

				return ['id' => $id, 'title' => $id, 'alt' => 'x', 'kind' => 'image'];
			}
		);
		$reader = $this->createMock(PortalFileReaderInterface::class);
		$reader->method('listFiles')->willReturnCallback(
			static fn (string $register, string $schema, string $id): array => ($files[$id] ?? [])
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));

		return new PortalImageChoice(library: $library, files: $reader, l10n: $l10n);
	}

	public function testAJpegFaviconIsRefused(): void {
		$choice = $this->choice(files: ['icon' => [['id' => 3, 'name' => 'old.png'], ['id' => 9, 'name' => 'favicon.JPG']]]);

		$this->assertSame('A favicon must be a PNG, SVG or ICO file.', $choice->refusal(portal: ['slug' => 'gemeente', 'favicon' => 'media:icon']));
	}

	public function testAPngSvgOrIcoFaviconIsAccepted(): void {
		foreach (['favicon.png', 'mark.SVG', 'favicon.ico'] as $name) {
			$choice = $this->choice(files: ['icon' => [['id' => 1, 'name' => $name]]]);

			$this->assertNull($choice->refusal(portal: ['slug' => 'gemeente', 'favicon' => 'media:icon']), $name);
		}
	}

	public function testAFaviconItemWithoutAFileIsRefused(): void {
		$choice = $this->choice(files: ['icon' => []]);

		$this->assertSame('A favicon must be a PNG, SVG or ICO file.', $choice->refusal(portal: ['slug' => 'gemeente', 'favicon' => 'media:icon']));
	}

	public function testAnotherPortalsMediaIsRefused(): void {
		$choice = $this->choice(files: ['hero' => [['id' => 1, 'name' => 'stadhuis.jpg']]]);

		$this->assertSame(
			'The heroImage is not a published item of this portal\'s media library.',
			$choice->refusal(portal: ['slug' => 'waterschap', 'heroImage' => 'media:hero'])
		);
		$this->assertSame(
			'The logo is not a published item of this portal\'s media library.',
			$choice->refusal(portal: ['slug' => 'gemeente', 'logo' => 'media:unknown'])
		);
	}

	public function testSavingTheThreeImagesStoresReferences(): void {
		$choice = $this->choice(files: [
			'logo' => [['id' => 1, 'name' => 'logo.jpg']],
			'icon' => [['id' => 2, 'name' => 'favicon.svg']],
			'hero' => [['id' => 3, 'name' => 'stadhuis.jpg']],
		]);

		$this->assertNull($choice->refusal(portal: ['slug' => 'gemeente', 'logo' => 'media:logo', 'favicon' => 'media:icon', 'heroImage' => 'media:hero']));
	}

	public function testAPlainAddressAndNoImagesAreLeftAlone(): void {
		$choice = $this->choice(files: []);

		$this->assertNull($choice->refusal(portal: ['slug' => 'gemeente', 'logo' => 'https://example.org/logo.png', 'heroImage' => '']));
		$this->assertNull($choice->refusal(portal: ['slug' => 'gemeente']));
	}
}
