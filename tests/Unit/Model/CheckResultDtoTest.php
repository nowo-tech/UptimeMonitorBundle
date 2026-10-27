<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Model;

use Nowo\UptimeMonitorBundle\Enum\CheckStatus;
use Nowo\UptimeMonitorBundle\Model\CheckResultDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function mb_strlen;
use function range;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CheckResultDto::class)]
final class CheckResultDtoTest extends TestCase
{
    public function testSanitizedScrubsInvalidUtf8AndBoundsTheData(): void
    {
        $deep = ['l2' => ['l3' => ['l4' => ['l5' => 'dropped']]]];
        $dto  = new CheckResultDto(CheckStatus::Down, 12, 500, "bad \xff byte " . str_repeat('m', 3000), [
            "k\xfe"  => "v\xff",
            'list'   => range(1, 80),
            'long'   => str_repeat('é', 1500),
            'deep'   => $deep,
            'number' => 7,
        ]);

        $clean = $dto->sanitized();

        self::assertSame(CheckStatus::Down, $clean->status);
        self::assertSame(12, $clean->latencyMs);
        self::assertSame(500, $clean->statusCode);
        self::assertSame(CheckResultDto::MAX_MESSAGE_LENGTH + 1, mb_strlen((string) $clean->message));
        self::assertStringStartsWith('bad ? byte ', (string) $clean->message);
        self::assertIsArray($clean->metadata);
        self::assertSame('v?', $clean->metadata['k?']);
        self::assertCount(CheckResultDto::MAX_METADATA_ITEMS, $clean->metadata['list']);
        self::assertSame(CheckResultDto::MAX_METADATA_STRING_LENGTH + 1, mb_strlen($clean->metadata['long']));
        self::assertSame(['l2' => ['l3' => []]], $clean->metadata['deep']);
        self::assertSame(7, $clean->metadata['number']);
        json_encode([$clean->message, $clean->metadata], JSON_THROW_ON_ERROR);
    }

    public function testSanitizedKeepsNullMessageAndMetadata(): void
    {
        $clean = (new CheckResultDto(CheckStatus::Up, 1))->sanitized();

        self::assertNull($clean->message);
        self::assertNull($clean->metadata);
    }

    public function testSanitizedMasksCredentialsInUrlsOfTheMessageAndMetadata(): void
    {
        $dto = (new CheckResultDto(
            CheckStatus::Down,
            10,
            message: 'Redirect to https://admin:hunter2@intra.test/?api_key=k failed',
            metadata: ['location' => 'https://bob:pw@intra.test/'],
        ))->sanitized();

        self::assertSame('Redirect to https://***@intra.test/?api_key=*** failed', $dto->message);
        self::assertSame(['location' => 'https://***@intra.test/'], $dto->metadata);
    }
}
