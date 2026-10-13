<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/** Identité de l'entreprise du tenant : mentions légales, contact, modèles d'e-mail et logo (admin seulement). */
final class CompanySettingsTest extends AuthTestCase
{
    private const URI = '/api/v1/settings/company';

    /** PNG de 1 × 1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';

    private static function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, $uri, $this->bearer($this->accessToken($email)), $json);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    private function upload(string $content, string $email = 'alice@a.test', string $field = 'logo'): ResponseInterface
    {
        $file = new UploadedFile((new StreamFactory())->createStream($content), 'logo.png', 'image/png', strlen($content), UPLOAD_ERR_OK);
        $request = (new ServerRequestFactory())->createServerRequest('POST', self::URI . '/logo', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken($email))
            ->withUploadedFiles([$field => $file]);

        return $this->app->handle($request);
    }

    public function testManagersCannotTouchCompanySettings(): void
    {
        foreach ([['GET', ''], ['PATCH', ''], ['GET', '/logo'], ['POST', '/logo'], ['DELETE', '/logo']] as [$method, $path]) {
            $response = $this->call($method, self::URI . $path, $method === 'PATCH' ? ['legalName' => 'X'] : null, 'marc@a.test');
            self::assertSame(403, $response->getStatusCode(), $method . $path);
            self::assertSame('forbidden', $this->errorCode($response));
        }
        self::assertSame([], $this->company->rows);
    }

    public function testATenantThatSavedNothingGetsBlankSettings(): void
    {
        $data = $this->data($this->call('GET', self::URI));

        self::assertSame('', $data['legalName']);
        self::assertNull($data['defaultCommission']);
        self::assertFalse($data['hasLogo']);
        self::assertSame([], $this->company->rows, 'lire ne crée rien');
    }

    public function testPartialUpdatesKeepTheOtherFieldsAndNormalizeValues(): void
    {
        $this->call('PATCH', self::URI, ['legalName' => ' SARL Conciergerie CASAMEA ', 'legalForm' => 'SARL', 'siret' => '123 456 789 00012', 'vatNumber' => 'fr 12 345678901', 'capital' => '1 500,50', 'iban' => 'fr76 3000 6000 0112 3456 7890 189', 'legalPhone' => '06 12 34 56 78', 'legalEmail' => 'contact@casamea.test', 'defaultCommission' => '20']);
        $response = $this->call('PATCH', self::URI, ['contactName' => 'Conciergerie Casamea', 'emailRecap' => "Bonjour {first_name},\n\nMerci."]);
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('SARL Conciergerie CASAMEA', $data['legalName']);
        self::assertSame('SARL', $data['legalForm']);
        self::assertSame('12345678900012', $data['siret']);
        self::assertSame('FR12345678901', $data['vatNumber']);
        self::assertSame('1500.50', $data['capital']);
        self::assertSame('FR7630006000011234567890189', $data['iban']);
        self::assertSame('06 12 34 56 78', $data['legalPhone']);
        self::assertEquals(20, $data['defaultCommission']);
        self::assertSame('Conciergerie Casamea', $data['contactName']);
        // Les sauts de ligne et les accolades des modèles sont conservés tels quels.
        self::assertSame("Bonjour {first_name},\n\nMerci.", $data['emailRecap']);
        self::assertSame($data, $this->data($this->call('GET', self::URI)));
    }

    public function testValuesCanBeClearedIncludingTheDefaultCommission(): void
    {
        $this->call('PATCH', self::URI, ['siret' => '12345678900012', 'defaultCommission' => 15.5, 'legalEmail' => 'a@a.test']);
        $data = $this->data($this->call('PATCH', self::URI, ['siret' => '', 'defaultCommission' => '', 'legalEmail' => null]));

        self::assertSame('', $data['siret']);
        self::assertNull($data['defaultCommission']);
        self::assertSame('', $data['legalEmail']);
    }

    public function testInvalidInputIsRejectedWithFieldCodes(): void
    {
        $response = $this->call('PATCH', self::URI, [
            'siret' => '1234',
            'vatNumber' => '12',
            'iban' => 'not-an-iban',
            'capital' => 'beaucoup',
            'legalPhone' => 'abc',
            'legalEmail' => 'pas-un-email',
            'contactEmail' => 'x@',
            'defaultCommission' => 101,
            'legalName' => str_repeat('x', 191),
            'legalForm' => ['SARL'],
            'logoPath' => 'logos/pirate.png',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
        $fields = $this->json($response)['error']['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertSame(['invalid_format'], $fields['siret']);
        self::assertSame(['invalid_format'], $fields['vatNumber']);
        self::assertSame(['invalid_format'], $fields['iban']);
        self::assertSame(['invalid_format'], $fields['capital']);
        self::assertSame(['invalid_format'], $fields['legalPhone']);
        self::assertSame(['invalid_email'], $fields['legalEmail']);
        self::assertSame(['invalid_email'], $fields['contactEmail']);
        self::assertSame(['out_of_range'], $fields['defaultCommission']);
        self::assertSame(['too_long'], $fields['legalName']);
        self::assertSame(['invalid_type'], $fields['legalForm']);
        self::assertSame(['unknown_field'], $fields['logoPath']);
        self::assertSame([], $this->company->rows, 'rien n\'est enregistré si une valeur est invalide');
    }

    public function testEachTenantHasItsOwnSettings(): void
    {
        $this->call('PATCH', self::URI, ['legalName' => 'Conciergerie A', 'siret' => '12345678900012']);

        $other = $this->data($this->call('GET', self::URI, null, 'bob@b.test'));
        self::assertSame('', $other['legalName']);

        $this->call('PATCH', self::URI, ['legalName' => 'Conciergerie B'], 'bob@b.test');
        self::assertSame('Conciergerie A', $this->data($this->call('GET', self::URI))['legalName']);
        self::assertSame('12345678900012', $this->data($this->call('GET', self::URI))['siret']);
    }

    public function testALogoCanBeUploadedReadReplacedAndRemoved(): void
    {
        self::assertSame(404, $this->call('GET', self::URI . '/logo')->getStatusCode());
        self::assertSame('logo_not_found', $this->errorCode($this->call('GET', self::URI . '/logo')));

        $response = $this->upload(self::png());
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->data($response)['hasLogo']);
        self::assertCount(1, $this->logos->files);

        $read = $this->call('GET', self::URI . '/logo');
        self::assertSame(200, $read->getStatusCode());
        self::assertSame('image/png', $read->getHeaderLine('Content-Type'));
        self::assertSame(self::png(), (string) $read->getBody());

        // Un nouvel envoi remplace le fichier : l'ancien est supprimé.
        $this->upload(self::png());
        self::assertCount(1, $this->logos->files);

        // Un autre tenant ne voit pas ce logo.
        self::assertSame('logo_not_found', $this->errorCode($this->call('GET', self::URI . '/logo', null, 'bob@b.test')));

        $removed = $this->call('DELETE', self::URI . '/logo');
        self::assertFalse($this->data($removed)['hasLogo']);
        self::assertSame([], $this->logos->files);
        self::assertSame(404, $this->call('GET', self::URI . '/logo')->getStatusCode());
        // Supprimer un logo absent n'est pas une erreur.
        self::assertSame(200, $this->call('DELETE', self::URI . '/logo')->getStatusCode());
    }

    public function testOnlyRealPngFilesUnderTheSizeLimitAreAccepted(): void
    {
        $cases = [
            'invalid_image' => ['GIF89a' . str_repeat("\0", 50), '<?php echo 1;', '', substr(self::png(), 0, 20)],
            'too_large' => [self::png() . str_repeat("\0", 1_048_577)],
        ];
        foreach ($cases as $code => $contents) {
            foreach ($contents as $content) {
                $response = $this->upload($content);
                self::assertSame(422, $response->getStatusCode(), $code);
                self::assertSame([$code], $this->json($response)['error']['fields']['logo'] ?? null, $code);
            }
        }
        self::assertSame([], $this->logos->files);

        $missing = $this->upload(self::png(), field: 'other');
        self::assertSame(['required'], $this->json($missing)['error']['fields']['logo'] ?? null);
        self::assertSame([], $this->logos->files);
    }
}
