<?php

declare(strict_types=1);

namespace PhpEpub\Test\Cli;

use PhpEpub\Cli\Arguments;
use PhpEpub\Cli\UsageException;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    public function testParsesPositionalsOptionsAndFlags(): void
    {
        $arguments = Arguments::parse(['a.epub', '--to=pdf', '-o', 'out', '--force', '--author', 'Ann', '--author=Bob', '-', 'b.epub'], ['to', 'output', 'author'], ['force']);

        $this->assertSame(['a.epub', '-', 'b.epub'], $arguments->positionals);
        $this->assertSame('pdf', $arguments->value('to'));
        $this->assertSame('out', $arguments->value('output'));
        $this->assertSame(['Ann', 'Bob'], $arguments->values('author'));
        $this->assertSame('Bob', $arguments->value('author'));
        $this->assertTrue($arguments->flag('force'));
        $this->assertFalse($arguments->flag('other'));
        $this->assertNull($arguments->value('missing'));
        $this->assertSame([], $arguments->values('missing'));
    }

    public function testDoubleDashEndsTheOptions(): void
    {
        $arguments = Arguments::parse(['--', '--not-an-option', '-x'], [], []);

        $this->assertSame(['--not-an-option', '-x'], $arguments->positionals);
    }

    public function testAValueMayStartWithADash(): void
    {
        $arguments = Arguments::parse(['--title', '-1'], ['title'], []);

        $this->assertSame('-1', $arguments->value('title'));
    }

    /**
     * @param list<string> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badArguments')]
    public function testRejectsWhatTheCommandDoesNotKnow(array $input, string $message): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage($message);

        Arguments::parse($input, ['to'], ['force']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function badArguments(): iterable
    {
        yield 'unknown long option' => [['--bogus'], 'Unknown option: --bogus'];
        yield 'unknown short option' => [['-z'], 'Unknown option: -z'];
        yield 'value option without a value' => [['--to'], 'needs a value'];
        yield 'flag with a value' => [['--force=yes'], 'takes no value'];
    }
}
