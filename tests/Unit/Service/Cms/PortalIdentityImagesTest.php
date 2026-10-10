<?php

/**
 * Unit tests for the check on a portal's favicon, logo and hero image.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalIdentityImages;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A reference must name an item of this portal's own library, and a favicon
 * must be a file a browser takes as a tab icon.
 *
 * @covers \OCA\Portaliq\Service\Cms\PortalIdentityImages
 */
class PortalIdentityImagesTest extends TestCase {
	/**
	 * The checker over a fake OpenRegister.
	 *
	 * @param object|null $item  The item the magic mapper finds, or null to throw.
	 * @param array|null  $files The files of the item, or null to throw.
	 *
	 * @return PortalIdentityImages
	 */
	private function images(?object $item, ?array $files = []): PortalIdentityImages {
		$register = new class() {
			public function find(string $id, bool $a, bool $b): object {
				return (object)[];
			}
		};
		$schema = new class() {
			public function findByApplicationAndSlug(string $slug, string $application): object {
				return (object)[];
			}
		};
		$magic = new class($item) {
			public function __construct(private readonly ?object $item) {
			}

			public function find(string $identifier, object $register, object $schema, bool $_rbac, bool $_multitenancy): object {
				return ($this->item ?? throw new RuntimeException('not found'));
			}
		};
		$fileService = new class($files) {
			public function __construct(private readonly ?array $files) {
			}

			public function getFiles(object $object): array {
				return ($this->files ?? throw new RuntimeException('no files'));
			}
		};
		$map       = [
			'OCA\\OpenRegister\\Db\\RegisterMapper'   => $register,
			'OCA\\OpenRegister\\Db\\SchemaMapper'     => $schema,
			'OCA\\OpenRegister\\Db\\MagicMapper'      => $magic,
			'OCA\\OpenRegister\\Service\\FileService' => $fileService,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $map[$id]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PortalIdentityImages($container, $l10n, new NullLogger());
	}//end images()

	/**
	 * A media item of a portal.
	 *
	 * @param string $portal The portal slug on the item.
	 *
	 * @return object
	 */
	private function item(string $portal): object {
		return new class($portal) {
			public function __construct(private readonly string $portal) {
			}

			public function getObject(): array {
				return ['portal' => $this->portal];
			}
		};
	}//end item()

	/**
	 * A file of an item.
	 *
	 * @param int    $id   The file id.
	 * @param string $name The file name.
	 *
	 * @return object
	 */
	private function file(int $id, string $name): object {
		return new class($id, $name) {
			public function __construct(private readonly int $id, private readonly string $name) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getName(): string {
				return $this->name;
			}
		};
	}//end file()

	/**
	 * @return void
	 */
	public function testAPortalWithoutMediaReferencesIsNotRefused(): void {
		$this->assertNull($this->images(item: null)->refusal(['slug' => 'a', 'favicon' => 'https://x/y.png', 'logo' => 3]));
	}//end testAPortalWithoutMediaReferencesIsNotRefused()

	/**
	 * @return void
	 */
	public function testAReferenceOutsideThePortalsLibraryIsRefusedPerField(): void {
		$other = $this->images(item: $this->item(portal: 'other'));

		$this->assertSame('The favicon is not in the media library of this portal.', $other->refusal(['slug' => 'a', 'favicon' => 'media:1']));
		$this->assertSame('The logo is not in the media library of this portal.', $other->refusal(['slug' => 'a', 'logo' => 'media:1']));
		$this->assertSame('The hero image is not in the media library of this portal.', $other->refusal(['slug' => 'a', 'heroImage' => 'media:1']));
		$this->assertSame('The logo is not in the media library of this portal.', $other->refusal(['slug' => '', 'logo' => 'media:1']));
	}//end testAReferenceOutsideThePortalsLibraryIsRefusedPerField()

	/**
	 * @return void
	 */
	public function testAnUnreadableOrEmptyReferenceIsRefused(): void {
		$this->assertSame(
			'The logo is not in the media library of this portal.',
			$this->images(item: null)->refusal(['slug' => 'a', 'logo' => 'media:1'])
		);
		$this->assertSame(
			'The logo is not in the media library of this portal.',
			$this->images(item: $this->item(portal: 'a'))->refusal(['slug' => 'a', 'logo' => 'media:'])
		);
	}//end testAnUnreadableOrEmptyReferenceIsRefused()

	/**
	 * @return void
	 */
	public function testAFaviconMustBeAPngSvgOrIcoByItsNewestFile(): void {
		$item = $this->item(portal: 'a');
		$portal = ['slug' => 'a', 'favicon' => 'media:1'];

		$this->assertNull($this->images($item, [$this->file(1, 'old.jpg'), $this->file(2, 'new.PNG')])->refusal($portal));
		$this->assertSame(
			'A favicon must be a PNG, SVG or ICO file.',
			$this->images($item, [$this->file(1, 'old.png'), $this->file(2, 'new.jpg')])->refusal($portal)
		);
		$this->assertSame('A favicon must be a PNG, SVG or ICO file.', $this->images($item, [])->refusal($portal));
		$this->assertSame('A favicon must be a PNG, SVG or ICO file.', $this->images($item, null)->refusal($portal));
		$this->assertNull($this->images($item, [$this->file(1, 'x.jpg')])->refusal(['slug' => 'a', 'logo' => 'media:1']));
	}//end testAFaviconMustBeAPngSvgOrIcoByItsNewestFile()
}//end class
