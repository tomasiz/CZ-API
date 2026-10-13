<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Reservation\PaymentFeeResolver;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\StripeFees;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Tests\Support\RecordingStripe;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PaymentFeeResolverTest extends TestCase
{
    private Encryptor $encryptor;

    private RecordingStripe $stripe;

    private PaymentFeeResolver $resolver;

    protected function setUp(): void
    {
        $this->encryptor = new Encryptor(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->stripe = new RecordingStripe();
        $this->resolver = new PaymentFeeResolver($this->stripe, $this->encryptor);
    }

    private function owner(?string $key = 'sk_live_secret123456'): Owner
    {
        return new Owner('owner-1', 'g-1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, $key === null ? null : $this->encryptor->encrypt($key), null);
    }

    /** @param list<array<string, mixed>> $payments */
    private static function reservation(string $source = 'manual', float $paymentFees = 0.0, array $payments = []): Reservation
    {
        return Reservation::fromGuesty('res-1', new GuestyReservation(
            'R1',
            'L1',
            $source,
            [],
            '',
            'Jean',
            new DateTimeImmutable('2026-09-10 14:00:00 UTC'),
            new DateTimeImmutable('2026-09-14 08:00:00 UTC'),
            4,
            2,
            1000.0,
            0.0,
            $paymentFees,
            0.0,
            80.0,
            80.0,
            0.0,
            600.0,
            $payments,
            [],
        ), 'owner-1', 'prop-1');
    }

    public function testFeesOfEverySuccessfulStripePaymentAreAddedUp(): void
    {
        $reservation = self::reservation(payments: [
            ['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_A', 'amount' => 500],
            ['status' => 'FAILED', 'confirmationCode' => 'pi_X', 'amount' => 500],
            ['status' => 'SUCCEEDED', 'confirmationCode' => 'BC-123', 'amount' => 20],
            ['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_B', 'amount' => 500],
        ]);

        $fees = $this->resolver->resolve($reservation, $this->owner());

        self::assertNotNull($fees);
        self::assertSame(15.35, $fees->amount);
        self::assertNull($fees->errorCode);
        // La clé est déchiffrée pour l'appel, et seuls les paiements Stripe réussis sont lus.
        self::assertSame([['sk_live_secret123456', 'pi_A'], ['sk_live_secret123456', 'pi_B']], $this->stripe->calls);
    }

    public function testAFailedReadKeepsTheFeesAlreadyReadAndReportsTheFirstError(): void
    {
        $reservation = self::reservation(payments: [
            ['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_A'],
            ['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_gone'],
        ]);

        $fees = $this->resolver->resolve($reservation, $this->owner());

        self::assertEquals(new StripeFees(10.10, 'resource_missing'), $fees);
    }

    public function testNothingIsReadWhenGuestyAlreadyGivesThePaymentFees(): void
    {
        $reservation = self::reservation(paymentFees: 12.0, payments: [['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_A']]);

        self::assertNull($this->resolver->resolve($reservation, $this->owner()));
        self::assertSame([], $this->stripe->calls);
    }

    public function testNothingIsReadWithoutStripePaymentOrWithoutOwnerKey(): void
    {
        $stripe = [['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_A']];

        self::assertNull($this->resolver->resolve(self::reservation(payments: [['status' => 'SUCCEEDED', 'confirmationCode' => 'BC-1']]), $this->owner()));
        self::assertNull($this->resolver->resolve(self::reservation(payments: $stripe), $this->owner(null)));
        self::assertNull($this->resolver->resolve(self::reservation(payments: $stripe), null));
        self::assertSame([], $this->stripe->calls);
    }

    public function testAirbnbAndOwnerStaysNeverCallStripe(): void
    {
        $stripe = [['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_A']];

        self::assertNull($this->resolver->resolve(self::reservation('airbnb2', payments: $stripe), $this->owner()));
        self::assertNull($this->resolver->resolve(self::reservation('owner', payments: $stripe), $this->owner()));
        self::assertSame([], $this->stripe->calls);
    }

    public function testAnUnreadableKeyIsReportedAsAnError(): void
    {
        $owner = new Owner('owner-1', 'g-1', 'Alice', 'Alice', '', '', '', '', OwnerType::Individual, '', '', 20.0, 'pas-chiffre', null);

        $fees = $this->resolver->resolve(self::reservation(payments: [['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_A']]), $owner);

        self::assertEquals(new StripeFees(0.0, 'stripe_key_unreadable'), $fees);
        self::assertSame([], $this->stripe->calls);
    }
}
