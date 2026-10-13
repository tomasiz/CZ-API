<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Infrastructure\Integration\StripeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class StripeClientTest extends TestCase
{
    private MockHandler $responses;

    /** @var \ArrayObject<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private \ArrayObject $calls;

    private StripeClient $client;

    protected function setUp(): void
    {
        $this->responses = new MockHandler();
        $calls = new \ArrayObject();
        $this->calls = $calls;
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($calls));
        $this->client = new StripeClient(new Client(['handler' => $stack]));
    }

    /** @param array<array-key, mixed> $body */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, [], (string) json_encode($body));
    }

    public function testFeesAreTheSumOfTheBalanceTransactionFeeDetails(): void
    {
        $this->responses->append(self::json(200, ['id' => 'pi_1', 'latest_charge' => ['balance_transaction' => ['fee_details' => [
            ['type' => 'stripe_fee', 'amount' => 1234],
            ['type' => 'tax', 'amount' => 11],
        ]]]]));

        $fees = $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1');

        self::assertSame(12.45, $fees->amount);
        self::assertNull($fees->errorCode);
    }

    public function testTheRequestIsAReadOnlyGetWithTheOwnerKeyAndTheExpandedCharge(): void
    {
        $this->responses->append(self::json(200, ['latest_charge' => ['balance_transaction' => ['fee_details' => []]]]));

        $this->client->paymentIntentFees('sk_live_secret123456', 'pi_3AbC');

        $request = $this->calls->getArrayCopy()[0]['request'] ?? null;
        self::assertNotNull($request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/v1/payment_intents/pi_3AbC', $request->getUri()->getPath());
        self::assertSame('Bearer sk_live_secret123456', $request->getHeaderLine('Authorization'));
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame(['latest_charge.balance_transaction'], $query['expand'] ?? null);
    }

    public function testAStripeErrorBecomesItsCode(): void
    {
        $this->responses->append(self::json(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent']]));
        $this->responses->append(self::json(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key']]));
        $this->responses->append(new Response(502, [], 'Bad gateway'));

        self::assertSame('resource_missing', $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1')->errorCode);
        self::assertSame('invalid_request_error', $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1')->errorCode);
        self::assertSame('http_502', $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1')->errorCode);
    }

    public function testAPaymentWithoutBalanceTransactionHasUnknownFees(): void
    {
        $this->responses->append(self::json(200, ['latest_charge' => null]));
        $this->responses->append(self::json(200, ['latest_charge' => ['balance_transaction' => 'txn_123']]));

        $codes = [
            $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1')->errorCode,
            $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1')->errorCode,
        ];

        self::assertSame(['no_balance_transaction', 'no_balance_transaction'], $codes);
    }

    public function testANetworkFailureIsReportedNotThrown(): void
    {
        $this->responses->append(new ConnectException('timeout', new Request('GET', 'https://api.stripe.com')));

        $fees = $this->client->paymentIntentFees('sk_live_secret123456', 'pi_1');

        self::assertSame(0.0, $fees->amount);
        self::assertSame('unreachable', $fees->errorCode);
    }
}
