<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Monitor;

use Nowo\UptimeMonitorBundle\Monitor\SpkiPins;
use Nowo\UptimeMonitorBundle\Tests\Support\FakeSocketConnector;
use Nowo\UptimeMonitorBundle\Tests\Support\TestCertificateChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_unique;
use function file_get_contents;
use function json_encode;
use function sprintf;
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

    public function testFromConfigKeepsOnlyWellFormedPinsUpToTheMaximum(): void
    {
        self::assertSame([], SpkiPins::fromConfig([]));
        self::assertSame([], SpkiPins::fromConfig(['expected_spki_pins' => self::PIN_A]));
        self::assertSame([self::PIN_A, self::PIN_B], SpkiPins::fromConfig(['expected_spki_pins' => [self::PIN_A, 'bad', 7, self::PIN_B, self::PIN_A]]));

        $many = [];
        for ($i = 0; $i < 25; ++$i) {
            $many[] = sprintf('sha256/%s%02d=', str_repeat('A', 41), $i);
        }
        self::assertCount(SpkiPins::MAX_PINS, SpkiPins::fromConfig(['expected_spki_pins' => $many]));
        self::assertCount(1, SpkiPins::fromConfig(['expected_spki_pins' => array_fill(0, 30, self::PIN_A)]));
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

    public function testUnreadableCertificatesHaveNoPin(): void
    {
        self::assertNull(SpkiPins::fromCertificate(null));
        self::assertNull(SpkiPins::fromCertificate('not a certificate'));
        self::assertNull(SpkiPins::fromCertificate(42));
        self::assertNull(SpkiPins::fromCertificate(__DIR__ . '/../../Fixtures/tls/localhost.crt'), 'never read as a file path');
        self::assertNull(SpkiPins::fromCertificate("-----BEGIN CERTIFICATE-----\nnope\n-----END CERTIFICATE-----"));
    }
}
