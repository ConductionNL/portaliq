<?php

/**
 * PortalMailOriginGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ContactConfirmationMailer;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Both identity mailers write a mail from the portal it speaks for, the name
 * it greets from and the portal's language exactly as recorded, for every
 * shape of portal and organisation record.
 *
 * @covers \OCA\Portaliq\Service\Identity\PortalIdentityMailer
 * @covers \OCA\Portaliq\Service\Identity\ContactConfirmationMailer
 * @uses   \OCA\Portaliq\Service\Mail\MailSite
 */
class PortalMailOriginGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/portal-mail-origin.json';

	/**
	 * The portals the resolver answers, by organisation; a missing key throws.
	 */
	private const PORTALS = [
		'org-portal' => ['slug' => 'p', 'title' => '  Portal title  ', 'locales' => [' de ', 'en']],
		'org-untitled' => ['slug' => 'q', 'title' => ' ', 'locales' => ['  ']],
		'org-none' => null,
		'org-unnamed' => null,
	];

	/**
	 * The organisation records, by organisation; a missing key throws.
	 */
	private const ORGANISATIONS = [
		'org-portal' => ['organisationName' => ' Gemeente X '],
		'org-untitled' => ['organisationName' => ' Untitled org '],
		'org-none' => ['organisationName' => ' Named org '],
		'org-unnamed' => [],
	];

	/**
	 * Everything the mailers did, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every mail is written as recorded.
	 *
	 * @return void
	 */
	public function testEveryMailIsWrittenAsRecorded(): void {
		$moment = new DateTimeImmutable('2026-11-02T10:00:00+00:00');
		$actual = [];
		foreach (['org-portal', 'org-untitled', 'org-none', 'org-unnamed', 'org-throws'] as $organisation) {
			$identity = $this->identity();
			$contact = $this->contact();
			$actual[$organisation] = [
				'send' => $this->sent(mail: static fn (): bool => $identity->send(template: PortalIdentityMailer::TEMPLATE_INVITATION, email: 'a@example.org', secret: 's-1', organisation: $organisation)),
				'sendWithPortal' => $this->sent(mail: static fn (): bool => $identity->send(template: PortalIdentityMailer::TEMPLATE_EMAIL_LINK, email: 'a@example.org', secret: 's-2', organisation: $organisation, portal: ['slug' => 'given', 'locales' => ['fr']])),
				'claimNotice' => $this->sent(mail: static fn (): bool => $identity->sendClaimNotice(email: 'a@example.org', organisation: $organisation, moment: $moment)),
				'signedInNotice' => $this->sent(mail: static fn (): bool => $identity->sendSignedInNotice(email: 'a@example.org', organisation: $organisation, portal: null, moment: $moment)),
				'contactConfirmation' => $this->sent(mail: static fn (): bool => $contact->afterCreate(
					subject: ['subjectRef' => 'subject-1', 'organisation' => $organisation],
					action: ['confirmationMail' => 'contact-confirmation', 'topicField' => 'topic'],
					data: ['topic' => 'Parking']
				)),
			];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryMailIsWrittenAsRecorded()

	/**
	 * The answer of one mail and everything it did.
	 *
	 * @param callable $mail Sends the mail.
	 *
	 * @return array<string, mixed>
	 */
	private function sent(callable $mail): array {
		$this->log = [];
		$answer = $mail();

		return ['answer' => $answer, 'log' => $this->log];
	}//end sent()

	/**
	 * A double of an interface or class that logs every call by name and
	 * answers from `$answers`, itself for a fluent call, or an empty value.
	 *
	 * @param class-string         $class   What to double.
	 * @param string               $label   Its name in the log.
	 * @param array<string, mixed> $answers Answers by method.
	 *
	 * @return MockObject
	 */
	private function recorder(string $class, string $label, array $answers = []): MockObject {
		$mock = $this->createMock($class);
		foreach ((new \ReflectionClass($class))->getMethods() as $method) {
			if ($method->isPublic() === false || $method->isStatic() === true || $method->isConstructor() === true) {
				continue;
			}

			$name = $method->getName();
			$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
			$mock->method($name)->willReturnCallback(function (mixed ...$args) use ($mock, $method, $name, $names, $label, $answers): mixed {
				$named = [];
				foreach ($args as $i => $value) {
					$named[$names[$i] ?? (string)$i] = is_object($value) ? (($value instanceof MockObject) ? 'double' : get_class($value)) : $value;
				}

				$this->log[] = [$label . '.' . $name => $named];
				if (array_key_exists($name, $answers) === true) {
					return is_callable($answers[$name]) ? $answers[$name](...$args) : $answers[$name];
				}

				return $this->emptyAnswer(mock: $mock, method: $method);
			});
		}

		return $mock;
	}//end recorder()

	/**
	 * The empty value a method's return type allows.
	 *
	 * @param MockObject       $mock   The double, for fluent calls.
	 * @param ReflectionMethod $method The method.
	 *
	 * @return mixed
	 */
	private function emptyAnswer(MockObject $mock, ReflectionMethod $method): mixed {
		$type = $method->getReturnType();
		if ($type === null || $type->allowsNull() === true || ($type instanceof ReflectionNamedType && $type->getName() === 'void')) {
			return null;
		}

		$name = ($type instanceof ReflectionNamedType) ? $type->getName() : 'mixed';
		$empty = ['string' => '', 'array' => [], 'bool' => true, 'int' => 0];
		if (array_key_exists($name, $empty) === true) {
			return $empty[$name];
		}

		return $mock;
	}//end emptyAnswer()

	/**
	 * The collaborators both mailers share, by constructor parameter name.
	 *
	 * @return array<string, object>
	 */
	private function shared(): array {
		$l10n = $this->recorder(IL10N::class, 'l10n', [
			't' => static fn (string $text, mixed $parameters = []): string => $text . ' ' . json_encode($parameters),
			'l' => static fn (string $type, mixed $data = null, array $options = []): string => $type . ':' . $data->format('Y-m-d'),
		]);
		$template = $this->recorder(IEMailTemplate::class, 'template');
		$message = $this->recorder(IMessage::class, 'message');
		$mailer = $this->recorder(IMailer::class, 'mailer', [
			'validateMailAddress' => true,
			'createEMailTemplate' => $template,
			'createMessage' => $message,
			'send' => [],
		]);
		$factory = $this->recorder(IFactory::class, 'l10nFactory', ['get' => $l10n]);
		$deepLinks = $this->recorder(PortalDeepLinkBuilder::class, 'deepLinks', [
			'forSite' => static fn (string $portalSlug, string $organisation = ''): string => 'https://site/' . $portalSlug . '/' . $organisation,
			'forPortal' => static fn (string $portalSlug, string $organisation = ''): string => 'https://portal/' . $portalSlug . '/' . $organisation,
		]);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willReturnCallback(function (string $organisation): ?array {
			$this->log[] = ['resolveByOrganisation' => $organisation];
			if (array_key_exists($organisation, self::PORTALS) === false) {
				throw new RuntimeException('no portal');
			}

			return self::PORTALS[$organisation];
		});
		$organisations = $this->createMock(PortalOrganisationConfigService::class);
		$organisations->method('resolve')->willReturnCallback(function (string $orgSlug, string $locale = 'nl'): array {
			$this->log[] = ['organisations.resolve' => [$orgSlug, $locale]];
			if (array_key_exists($orgSlug, self::ORGANISATIONS) === false) {
				throw new RuntimeException('no organisation');
			}

			return self::ORGANISATIONS[$orgSlug];
		});

		$shared = [
			'mailer' => $mailer,
			'l10nFactory' => $factory,
			'deepLinks' => $deepLinks,
			'logger' => $this->recorder(LoggerInterface::class, 'logger'),
		];
		if ((new \ReflectionClass(PortalIdentityMailer::class))->getConstructor()->getParameters()[3]->getName() === 'site') {
			return [...$shared, 'site' => new \OCA\Portaliq\Service\Mail\MailSite(portals: $portals, organisations: $organisations)];
		}

		return [...$shared, 'portals' => $portals, 'organisations' => $organisations];
	}//end shared()

	/**
	 * The identity mailer.
	 *
	 * @return PortalIdentityMailer
	 */
	private function identity(): PortalIdentityMailer {
		return new PortalIdentityMailer(...$this->shared());
	}//end identity()

	/**
	 * The contact confirmation mailer, for an account with an address.
	 *
	 * @return ContactConfirmationMailer
	 */
	private function contact(): ContactConfirmationMailer {
		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturn(['email' => 'b@example.org']);

		return new ContactConfirmationMailer(...[...$this->shared(), 'accounts' => $accounts]);
	}//end contact()
}//end class
