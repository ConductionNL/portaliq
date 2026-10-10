<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\FileService;
use OCA\Portaliq\Listener\PortalIdentityGuardListener;
use OCA\Portaliq\Service\Cms\PortalIdentityImages;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The portal settings save refuses a favicon of the wrong type and another
 * portal's media (portal-identity-from-the-admin REQ-PIA-001).
 *
 * The form saves straight through OpenRegister's object API, so the save path
 * is this pre-write hook; OpenRegister's real events and entities are built.
 *
 * @covers \OCA\Portaliq\Listener\PortalIdentityGuardListener
 * @covers \OCA\Portaliq\Service\Cms\PortalIdentityImages
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */
class PortalIdentityGuardListenerTest extends TestCase {

	private const PORTAL_SCHEMA = 41;

	private const MEDIA_SCHEMA = 42;

	/**
	 * The media items the mapper holds: id => [portal, newest file name].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $media = [
		'png-1'   => ['gemeente', 'icoon.png'],
		'jpg-1'   => ['gemeente', 'foto.jpg'],
		'logo-1'  => ['gemeente', 'logo.svg'],
		'hero-1'  => ['gemeente', 'hero.jpg'],
		'other-1' => ['waterschap', 'logo.svg'],
	];

	protected function setUp(): void {
		if (class_exists(ObjectEntity::class) === false) {
			$this->markTestSkipped('OpenRegister classes are not loadable (set PORTALIQ_OPENREGISTER_LIB).');
		}
	}//end setUp()

	/**
	 * @return void
	 */
	public function testAJpegFaviconIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->portal(['favicon' => 'media:jpg-1']), $this->portal([]));
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => 'A favicon must be a PNG, SVG or ICO file.'], $event->getErrors());
	}//end testAJpegFaviconIsRefused()

	/**
	 * @return void
	 */
	public function testAnotherPortalsMediaIsRefused(): void {
		$event = new ObjectCreatingEvent($this->portal(['logo' => 'media:other-1']));
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => 'The logo is not in the media library of this portal.'], $event->getErrors());

		$missing = new ObjectCreatingEvent($this->portal(['heroImage' => 'media:gone']));
		$this->listener()->handle($missing);
		$this->assertSame(['message' => 'The hero image is not in the media library of this portal.'], $missing->getErrors());
	}//end testAnotherPortalsMediaIsRefused()

	/**
	 * @return void
	 */
	public function testSavingTheThreeImagesStoresReferences(): void {
		$entity = $this->portal(['favicon' => 'media:png-1', 'logo' => 'media:logo-1', 'heroImage' => 'media:hero-1']);
		$event  = new ObjectUpdatingEvent($entity, $this->portal([]));
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$stored = $entity->getObject();
		$this->assertSame('media:png-1', $stored['favicon']);
		$this->assertSame('media:logo-1', $stored['logo']);
		$this->assertSame('media:hero-1', $stored['heroImage']);
	}//end testSavingTheThreeImagesStoresReferences()

	/**
	 * A web address logo and an unset favicon are left alone, as is another
	 * schema's object.
	 *
	 * @return void
	 */
	public function testAUrlLogoAndAnotherSchemaPass(): void {
		$event = new ObjectCreatingEvent($this->portal(['logo' => 'https://example.nl/logo.svg']));
		$this->listener()->handle($event);
		$this->assertFalse($event->isPropagationStopped());

		$other = $this->portal(['favicon' => 'media:jpg-1']);
		$other->setSchema('7');
		$event = new ObjectCreatingEvent($other);
		$this->listener()->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}//end testAUrlLogoAndAnotherSchemaPass()

	/**
	 * A favicon whose item has no file yet is refused: nothing to serve.
	 *
	 * @return void
	 */
	public function testAFaviconWithoutAFileIsRefused(): void {
		$this->media['empty-1'] = ['gemeente', ''];
		$event = new ObjectCreatingEvent($this->portal(['favicon' => 'media:empty-1']));
		$this->listener()->handle($event);

		$this->assertSame(['message' => 'A favicon must be a PNG, SVG or ICO file.'], $event->getErrors());
	}//end testAFaviconWithoutAFileIsRefused()

	/**
	 * The hook is registered on both pre-write events, or the rules never run.
	 *
	 * @return void
	 */
	public function testTheGuardIsRegisteredOnCreateAndUpdate(): void {
		$source = (string)file_get_contents(__DIR__.'/../../../lib/AppInfo/Application.php');
		$this->assertMatchesRegularExpression(
			'/foreach \\(\\[ObjectCreatingEvent::class, ObjectUpdatingEvent::class\\] as \\$event\\) \\{\\s*\\$context->registerEventListener\\(\\$event, PortalIdentityGuardListener::class\\);/',
			$source
		);
	}//end testTheGuardIsRegisteredOnCreateAndUpdate()

	/**
	 * A portal object as OpenRegister hands it to a hook.
	 *
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return ObjectEntity
	 */
	private function portal(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p1');
		$entity->setSchema((string)self::PORTAL_SCHEMA);
		$entity->setObject($data + ['slug' => 'gemeente', 'title' => 'Gemeente', 'status' => 'published']);

		return $entity;
	}//end portal()

	/**
	 * The listener over OpenRegister's mappers and file service.
	 *
	 * @return PortalIdentityGuardListener
	 */
	private function listener(): PortalIdentityGuardListener {
		$portalSchema = new Schema();
		$portalSchema->setId(self::PORTAL_SCHEMA);
		$mediaSchema = new Schema();
		$mediaSchema->setId(self::MEDIA_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->willReturnCallback(
			static fn (string $slug, string $application) => match ([$slug, $application]) {
				['portal', 'portaliq'] => $portalSchema,
				['media', 'portaliq'] => $mediaSchema,
			}
		);

		$register = new Register();
		$register->setId(3);
		$registers = $this->getMockBuilder(RegisterMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$registers->method('find')->with('portaliq', false, false)->willReturn($register);

		$media   = $this->media;
		$objects = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$objects->method('find')->willReturnCallback(
			static function (string|int $identifier, ?Register $reg=null, ?Schema $schema=null) use ($media, $register, $mediaSchema): ObjectEntity {
				if (isset($media[$identifier]) === false || $reg !== $register || $schema !== $mediaSchema) {
					throw new DoesNotExistException('no such object');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$identifier);
				$entity->setSchema((string)self::MEDIA_SCHEMA);
				$entity->setObject(['portal' => $media[$identifier][0], 'kind' => 'image', 'title' => 'x', 'alt' => 'x', 'status' => 'published']);
				return $entity;
			}
		);

		$files = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods(['getFiles'])->getMock();
		$files->method('getFiles')->willReturnCallback(
			function (ObjectEntity|string $object) use ($media): array {
				$name = $media[$object->getUuid()][1];
				if ($name === '') {
					return [];
				}

				$old = $this->createMock(File::class);
				$old->method('getId')->willReturn(1);
				$old->method('getName')->willReturn('oud.gif');
				$new = $this->createMock(File::class);
				$new->method('getId')->willReturn(9);
				$new->method('getName')->willReturn($name);
				return [$new, $old];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				SchemaMapper::class => $schemas,
				RegisterMapper::class => $registers,
				MagicMapper::class => $objects,
				FileService::class => $files,
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []) => vsprintf($text, $params)
		);

		$images = new PortalIdentityImages($container, $l10n, new NullLogger());

		return new PortalIdentityGuardListener($container, $images, new NullLogger());
	}//end listener()
}//end class
