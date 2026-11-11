<?php

/**
 * PortalIdentityMailerCallsGoldenTest
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
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
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
 * Every collaborator call the identity mailer makes, in order, for each
 * template mail and each notice: delivered, refused by the mail server,
 * failed with an exception, refused before sending, with and without the
 * portal's own text and the mail log.
 *
 * @covers \OCA\Portaliq\Service\Identity\PortalIdentityMailer
 */
class PortalIdentityMailerCallsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/portal-identity-mailer-calls.json';

	/**
	 * Everything the mailer did, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every scenario's answer and calls are as recorded.
	 *
	 * @return void
	 */
	public function testEveryCallIsMadeAsRecorded(): void {
		$moment = new DateTimeImmutable('2026-11-02T10:00:00+00:00');
		$portal = ['slug' => ' given ', 'title' => 'Given portal', 'locales' => ['fr']];
		$details = ['inviter' => ' Anna ', 'message' => ' Hello ', 'address' => ' a@example.org '];
		$actual = [];
		foreach (['plain' => [], 'renderer' => ['renderer'], 'log' => ['mailLog'], 'both' => ['renderer', 'mailLog'], 'brokenLog' => ['renderer', 'brokenLog']] as $setup => $extras) {
			foreach (['delivered' => [], 'refused' => ['a@example.org'], 'throws' => 'throw'] as $outcome => $send) {
				$actual[$setup][$outcome] = [
					'contactInvitation' => $this->sent(mail: fn (): bool => $this->identity(extras: $extras, send: $send)->send(template: PortalIdentityMailer::TEMPLATE_CONTACT_INVITATION, email: 'a@example.org', secret: 's/1 2', organisation: 'org', portal: $portal, details: $details)),
					'emailConfirmation' => $this->sent(mail: fn (): bool => $this->identity(extras: $extras, send: $send)->send(template: PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION, email: 'a@example.org', secret: 's-2', organisation: 'org')),
					'claimNotice' => $this->sent(mail: fn (): bool => $this->identity(extras: $extras, send: $send)->sendClaimNotice(email: 'a@example.org', organisation: 'org', moment: $moment)),
					'signedInNotice' => $this->sent(mail: fn (): bool => $this->identity(extras: $extras, send: $send)->sendSignedInNotice(email: 'a@example.org', organisation: 'org', portal: $portal, moment: $moment)),
					'addressChanged' => $this->sent(mail: fn (): bool => $this->identity(extras: $extras, send: $send)->sendSignInAddressChanged(email: 'a@example.org', organisation: 'org', moment: $moment)),
				];
			}
		}

		$actual['refusedBeforeSending'] = [
			'unknownTemplate' => $this->sent(mail: fn (): bool => $this->identity(extras: ['mailLog'], send: [])->send(template: 'nope', email: 'a@example.org', secret: 's', organisation: 'org')),
			'noSecret' => $this->sent(mail: fn (): bool => $this->identity(extras: ['mailLog'], send: [])->send(template: PortalIdentityMailer::TEMPLATE_INVITATION, email: 'a@example.org', secret: '', organisation: 'org')),
			'badAddress' => $this->sent(mail: fn (): bool => $this->identity(extras: ['mailLog'], send: [])->send(template: PortalIdentityMailer::TEMPLATE_INVITATION, email: 'bad', secret: 's', organisation: 'org')),
			'claimBadAddress' => $this->sent(mail: fn (): bool => $this->identity(extras: [], send: [])->sendClaimNotice(email: 'bad', organisation: 'org', moment: $moment)),
			'signedInBadAddress' => $this->sent(mail: fn (): bool => $this->identity(extras: [], send: [])->sendSignedInNotice(email: 'bad', organisation: 'org', portal: null, moment: $moment)),
			'changedBadAddress' => $this->sent(mail: fn (): bool => $this->identity(extras: [], send: [])->sendSignInAddressChanged(email: 'bad', organisation: 'org', moment: $moment)),
		];

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryCallIsMadeAsRecorded()

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
	 * A double that logs every call by name and answers from `$answers`,
	 * itself for a fluent call, or an empty value.
	 *
	 * @param class-string         $class   What to double.
	 * @param array<string, mixed> $answers Answers by method, and `label`.
	 *
	 * @return MockObject
	 */
	private function recorder(string $class, array $answers): MockObject {
		$mock = $this->createMock($class);
		foreach ((new \ReflectionClass($class))->getMethods() as $method) {
			if ($method->isPublic() === false || $method->isStatic() === true || $method->isConstructor() === true) {
				continue;
			}

			$name = $method->getName();
			$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
			$mock->method($name)->willReturnCallback(function (mixed ...$args) use ($mock, $method, $name, $names, $answers): mixed {
				$named = [];
				foreach ($args as $i => $value) {
					$named[$names[$i] ?? (string)$i] = is_object($value) ? (($value instanceof MockObject) ? 'double' : get_class($value)) : $value;
				}

				$this->log[] = [$answers['label'] . '.' . $name => $named];
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
	 * The mailer, with the optional collaborators `extras` names and a
	 * mail server that answers `send` (or throws for 'throw').
	 *
	 * @param list<string>              $extras `renderer`, `mailLog`, `brokenLog`.
	 * @param list<string>|string       $send   The refused addresses, or 'throw'.
	 *
	 * @return PortalIdentityMailer
	 */
	private function identity(array $extras, array|string $send): PortalIdentityMailer {
		$mailer = $this->recorder(IMailer::class, [
			'label' => 'mailer',
			'validateMailAddress' => static fn (string $email): bool => str_contains($email, '@'),
			'createEMailTemplate' => $this->recorder(IEMailTemplate::class, ['label' => 'template']),
			'createMessage' => $this->recorder(IMessage::class, ['label' => 'message']),
			'send' => static fn (): array => ($send === 'throw') ? throw new RuntimeException('transport for a@example.org') : $send,
		]);
		$l10n = $this->recorder(IL10N::class, [
			'label' => 'l10n',
			't' => static fn (string $text, mixed $parameters = []): string => $text . ' ' . json_encode($parameters),
			'l' => static fn (string $type, mixed $data = null, array $options = []): string => $type . ':' . $data->format('Y-m-d H:i') . ':' . json_encode($options),
		]);
		$site = $this->recorder(MailSite::class, [
			'label' => 'site',
			'portal' => ['slug' => 'own', 'locales' => ['nl']],
			'name' => static fn (?array $portal, string $organisation): string => 'Name of ' . ($portal['slug'] ?? '-') . '/' . $organisation,
			'language' => static fn (?array $portal): string => 'lang-' . ($portal['slug'] ?? '-'),
		]);

		return new PortalIdentityMailer(
			mailer: $mailer,
			l10nFactory: $this->recorder(IFactory::class, ['label' => 'l10nFactory', 'get' => $l10n]),
			deepLinks: $this->recorder(PortalDeepLinkBuilder::class, [
				'label' => 'deepLinks',
				'forSite' => static fn (string $portalSlug, string $organisation = ''): string => 'https://site/' . $portalSlug . '/' . $organisation,
				'forPortal' => static fn (string $portalSlug, string $organisation = ''): string => 'https://portal/' . $portalSlug . '/' . $organisation,
			]),
			site: $site,
			logger: $this->recorder(LoggerInterface::class, ['label' => 'logger']),
			renderer: in_array('renderer', $extras, true) ? $this->recorder(MailTemplateRenderer::class, [
				'label' => 'renderer',
				'render' => static fn (string $portal, string $key, array $values, string $subject, string $body): array => ['subject' => 'R:' . $subject, 'body' => 'R:' . $body . json_encode($values)],
			]) : null,
			mailLog: $this->mailLog(extras: $extras)
		);
	}//end identity()

	/**
	 * The mail log the extras ask for, or none.
	 *
	 * @param list<string> $extras `mailLog` or `brokenLog`.
	 *
	 * @return MailLog|null
	 */
	private function mailLog(array $extras): ?MailLog {
		if (in_array('mailLog', $extras, true) === true) {
			return $this->recorder(MailLog::class, ['label' => 'mailLog']);
		}

		if (in_array('brokenLog', $extras, true) === true) {
			return $this->recorder(MailLog::class, ['label' => 'brokenLog', 'record' => static fn (): string => throw new RuntimeException('log down')]);
		}

		return null;
	}//end mailLog()
}//end class
