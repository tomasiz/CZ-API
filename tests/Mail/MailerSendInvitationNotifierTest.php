<?php

declare(strict_types=1);

namespace Casamiza\Tests\Mail;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsPatch;
use Casamiza\Domain\Team\ActivationLink;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserStatus;
use Casamiza\Infrastructure\Mail\MailerSendInvitationNotifier;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\AppTestCase;
use Casamiza\Tests\Support\InMemoryCompanySettings;
use Casamiza\Tests\Support\InMemoryMailer;
use Psr\Log\NullLogger;

/** E-mail d'invitation d'un membre : lien d'activation, échec d'envoi sans conséquence sur l'invitation. */
final class MailerSendInvitationNotifierTest extends AppTestCase
{
    private InMemoryMailer $mailer;
    private InMemoryCompanySettings $company;
    private MailerSendInvitationNotifier $notifier;
    private User $user;

    protected function setUp(): void
    {
        $context = new TenantContext();
        $context->set('tenant-a');
        $this->mailer = new InMemoryMailer();
        $this->company = new InMemoryCompanySettings($context);
        $this->notifier = new MailerSendInvitationNotifier($this->mailer, $this->company, new ActivationLink($this->settings()), new NullLogger());
        $this->user = new User('user-1', 'tenant-a', 'Nina', 'Nouvelle', 'nina@a.test', null, Role::Manager, status: UserStatus::Invited);
    }

    private function configure(): void
    {
        $this->company->save(CompanySettings::blank()->patched(new CompanySettingsPatch(legalName: 'Casamea', contactName: 'Équipe Casamea', contactEmail: 'contact@casamea.test')));
    }

    public function testTheInviteeReceivesTheActivationLinkFromTheCompanyContact(): void
    {
        $this->configure();

        self::assertTrue($this->notifier->notify($this->user, 'tok-123'));

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertSame(['contact@casamea.test', 'Équipe Casamea', 'nina@a.test', 'Nina Nouvelle'], [$email->fromEmail, $email->fromName, $email->toEmail, $email->toName]);
        self::assertSame('Invitation à rejoindre Casamea', $email->subject);
        self::assertStringStartsWith('Bonjour Nina,', $email->text);
        self::assertStringContainsString(self::ORIGIN . '/activate?token=tok-123', $email->text);
        self::assertNull($email->copyEmail);
    }

    public function testNothingIsSentWithoutAContactEmail(): void
    {
        self::assertFalse($this->notifier->notify($this->user, 'tok-123'));

        self::assertSame([], $this->mailer->sent);
    }

    public function testAFailedSendDoesNotFailTheInvitation(): void
    {
        $this->configure();
        $this->mailer->failure = new NotFoundException('integration_not_configured');

        self::assertFalse($this->notifier->notify($this->user, 'tok-123'));

        self::assertSame([], $this->mailer->sent);
    }
}
