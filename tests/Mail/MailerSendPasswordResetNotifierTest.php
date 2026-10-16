<?php

declare(strict_types=1);

namespace Casamiza\Tests\Mail;

use Casamiza\Domain\Auth\PasswordResetLink;
use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsPatch;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Infrastructure\Mail\MailerSendPasswordResetNotifier;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\AppTestCase;
use Casamiza\Tests\Support\InMemoryCompanySettings;
use Casamiza\Tests\Support\InMemoryMailer;
use Psr\Log\NullLogger;

/** E-mail de réinitialisation du mot de passe : lien, expéditeur, échec d'envoi sans exception. */
final class MailerSendPasswordResetNotifierTest extends AppTestCase
{
    private InMemoryMailer $mailer;
    private InMemoryCompanySettings $company;
    private MailerSendPasswordResetNotifier $notifier;
    private User $user;

    protected function setUp(): void
    {
        $context = new TenantContext();
        $context->set('tenant-a');
        $this->mailer = new InMemoryMailer();
        $this->company = new InMemoryCompanySettings($context);
        $this->notifier = new MailerSendPasswordResetNotifier($this->mailer, $this->company, new PasswordResetLink($this->settings()), new NullLogger());
        $this->user = new User('user-1', 'tenant-a', 'Alice', 'Admin', 'alice@a.test', 'hash', Role::Admin);
    }

    private function configure(): void
    {
        $this->company->save(CompanySettings::blank()->patched(new CompanySettingsPatch(legalName: 'Casamea', contactName: 'Équipe Casamea', contactEmail: 'contact@casamea.test')));
    }

    public function testTheUserReceivesTheResetLinkFromTheCompanyContact(): void
    {
        $this->configure();

        self::assertTrue($this->notifier->notify($this->user, 'tok-123'));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertSame(['contact@casamea.test', 'Équipe Casamea', 'alice@a.test', 'Alice Admin'], [$email->fromEmail, $email->fromName, $email->toEmail, $email->toName]);
        self::assertSame('Réinitialisation de votre mot de passe', $email->subject);
        self::assertStringStartsWith('Bonjour Alice,', $email->text);
        self::assertStringContainsString(self::ORIGIN . '/reset-password?token=tok-123', $email->text);
        self::assertStringContainsString('valable 1 heure', $email->text);
    }

    public function testNothingIsSentWithoutAContactEmail(): void
    {
        self::assertFalse($this->notifier->notify($this->user, 'tok-123'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testAFailedSendReturnsFalseWithoutThrowing(): void
    {
        $this->configure();
        $this->mailer->failure = new NotFoundException('integration_not_configured');

        self::assertFalse($this->notifier->notify($this->user, 'tok-123'));
    }
}
