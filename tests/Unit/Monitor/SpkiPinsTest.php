<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Monitor;

use Nowo\UptimeMonitorBundle\Monitor\SpkiPins;
use Nowo\UptimeMonitorBundle\Tests\Support\FakeSocketConnector;
use Nowo\UptimeMonitorBundle\Tests\Support\TestCertificateChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_slice;
use function array_unique;
use function base64_encode;
use function explode;
use function file_get_contents;
use function hash;
use function implode;
use function json_encode;
use function str_repeat;

#[CoversClass(SpkiPins::class)]
final class SpkiPinsTest extends TestCase
{
    /** SPKI pin of tests/Fixtures/tls/localhost.crt, computed with the openssl CLI (x509 -pubkey | pkey -outform der | dgst -sha256). */
    private const FIXTURE_PIN = 'sha256/T7PGSHVlfsuWyerRQibZteTUEkNEH2IdCgsc71+VDOc=';

    private const PIN_A = 'sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const PIN_B = 'sha256/YLh1dUR9y6Kja30RrAn7JKnbQG/uEtLMkBgFF2Fuihg=';

    public function testFormat(): void
    {
        self::assertTrue(SpkiPins::isValid(self::PIN_A));
        self::assertTrue(SpkiPins::isValid(self::FIXTURE_PIN));
        self::assertFalse(SpkiPins::isValid('sha1/AAAAAAAAAAAAAAAAAAAAAAAAAAA='));
        self::assertFalse(SpkiPins::isValid('T7PGSHVlfsuWyerRQibZteTUEkNEH2IdCgsc71+VDOc='), 'prefix required');
        self::assertFalse(SpkiPins::isValid('sha256/T7PGSHVlfsuWyerRQibZteTUEkNEH2IdCgsc71+VDOc'), 'padding required');
        self::assertFalse(SpkiPins::isValid('sha256/T7PGSHVlfsuWyerRQibZteTUEkNEH2IdCgsc71-VDOc='), 'standard base64 only');
        self::assertFalse(SpkiPins::isValid(self::PIN_A . "\n"));
        self::assertFalse(SpkiPins::isValid('sha256/' . str_repeat('A', 44) . '='));
        self::assertFalse(SpkiPins::isValid('sha256/' . str_repeat('A', 42) . 'B='), 'non-canonical last character (GA-I1)');
        self::assertTrue(SpkiPins::isValid($this->pin(7)));
    }

    public function testParsingStopsAfterOneMoreThanTheMaximum(): void
    {
        $lines = [];
        for ($i = 0; $i < 5000; ++$i) {
            $lines[] = $this->pin($i % 30);
        }

        $pins = SpkiPins::parse(implode("\n", $lines));

        self::assertCount(SpkiPins::MAX_PINS + 1, $pins);
        self::assertSame($this->pin(0), $pins[0]);
        self::assertSame(['123'], SpkiPins::parse('123'), 'numeric entries stay strings');
    }

    public function testParsesOnePinPerLineWithCommentsAndDuplicates(): void
    {
        $text = "\n  " . self::PIN_A . "  # primary\r\n# backup key\n\n" . self::PIN_B . "\n" . self::PIN_A . "\n";

        self::assertSame([self::PIN_A, self::PIN_B], SpkiPins::parse($text));
        self::assertSame([], SpkiPins::parse("  \n "));
    }

    public function testParsesAPastedPinsJsonList(): void
    {
        self::assertSame([self::PIN_A, self::PIN_B], SpkiPins::parse((string) json_encode([self::PIN_A, ' ' . self::PIN_B])));
        self::assertSame([self::PIN_B], SpkiPins::parse((string) json_encode(['pins' => [self::PIN_B]])));
        self::assertSame(['', self::PIN_A], SpkiPins::parse('[1, "' . self::PIN_A . '"]'), 'a non-string entry stays invalid');
        self::assertSame(['[not json'], SpkiPins::parse('[not json'), 'broken JSON is read as lines');
    }

    public function testInvalidListsTheMalformedEntries(): void
    {
        self::assertSame(['nope', ''], SpkiPins::invalid([self::PIN_A, 'nope', '']));
        self::assertSame([], SpkiPins::invalid([self::PIN_A, self::PIN_B]));
    }

    public function testFromConfigReturnsThePinsOrNullForAnInvalidValue(): void
    {
        self::assertSame([], SpkiPins::fromConfig([]));
        self::assertSame([], SpkiPins::fromConfig(['expected_spki_pins' => null]));
        self::assertSame([], SpkiPins::fromConfig(['expected_spki_pins' => []]));
        self::assertSame([self::PIN_A, self::PIN_B], SpkiPins::fromConfig(['expected_spki_pins' => [self::PIN_A, self::PIN_B, self::PIN_A]]));

        // Fail closed (GA-L2, GA-I4): a non-list, a malformed entry or too many pins is an invalid config.
        self::assertNull(SpkiPins::fromConfig(['expected_spki_pins' => self::PIN_A]));
        self::assertNull(SpkiPins::fromConfig(['expected_spki_pins' => 7]));
        self::assertNull(SpkiPins::fromConfig(['expected_spki_pins' => ['a' => self::PIN_A]]));
        self::assertNull(SpkiPins::fromConfig(['expected_spki_pins' => [self::PIN_A, 'bad']]));
        self::assertNull(SpkiPins::fromConfig(['expected_spki_pins' => [self::PIN_A, 7]]));

        $many = [];
        for ($i = 0; $i <= SpkiPins::MAX_PINS; ++$i) {
            $many[] = $this->pin($i);
        }
        self::assertNull(SpkiPins::fromConfig(['expected_spki_pins' => $many]));
        self::assertCount(SpkiPins::MAX_PINS, SpkiPins::fromConfig(['expected_spki_pins' => array_slice($many, 0, SpkiPins::MAX_PINS)]) ?? []);
    }

    public function testToFormTextKeepsInvalidValuesVisibleForTheValidator(): void
    {
        self::assertSame('', SpkiPins::toFormText(null));
        self::assertSame('?', SpkiPins::toFormText(self::PIN_A));
        self::assertSame('', SpkiPins::toFormText([]));
        self::assertSame(self::PIN_A . "\n?\n?", SpkiPins::toFormText([self::PIN_A, 'bad', 7]));
        self::assertCount(SpkiPins::MAX_PINS + 1, explode("\n", SpkiPins::toFormText(array_fill(0, 1000, self::PIN_A))));
    }

    public function testComputesTheSpkiPinOfACertificate(): void
    {
        self::assertSame(self::FIXTURE_PIN, SpkiPins::fromCertificate(FakeSocketConnector::fixtureCertificate()));
        self::assertSame(self::FIXTURE_PIN, SpkiPins::fromCertificate((string) file_get_contents(__DIR__ . '/../../Fixtures/tls/localhost.crt')), 'PEM string');

        $chain = TestCertificateChain::generate();
        $pins  = [SpkiPins::fromCertificate($chain->leaf), SpkiPins::fromCertificate($chain->intermediate), SpkiPins::fromCertificate($chain->root)];
        foreach ($pins as $pin) {
            self::assertTrue(SpkiPins::isValid((string) $pin));
        }
        self::assertCount(3, array_unique($pins), 'each certificate of the chain has its own key');
    }

    private function pin(int $i): string
    {
        return 'sha256/' . base64_encode(hash('sha256', (string) $i, true));
    }

    public function testUnreadableCertificatesHaveNoPin(): void
    {
        self::assertNull(SpkiPins::fromCertificate(null));
        self::assertNull(SpkiPins::fromCertificate('not a certificate'));
        self::assertNull(SpkiPins::fromCertificate(42));
        self::assertNull(SpkiPins::fromCertificate(__DIR__ . '/../../Fixtures/tls/localhost.crt'), 'never read as a file path');
        self::assertNull(SpkiPins::fromCertificate("-----BEGIN CERTIFICATE-----\nnope\n-----END CERTIFICATE-----"));
    }
}
