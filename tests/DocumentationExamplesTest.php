<?php

declare(strict_types=1);

namespace PhpEpub\Test;

use ParseError;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every fenced php block in README.md and docs/ must be valid PHP, call only methods that exist and import
 * only classes that exist, so the documentation cannot drift from the code unnoticed.
 *
 * A block that is deliberately partial (a fragment of an array, a placeholder) is marked by putting this
 * comment on the line directly above its opening fence:
 *
 *     <!-- example:skip -->
 */
final class DocumentationExamplesTest extends TestCase
{
    private const string SKIP_MARKER = '<!-- example:skip -->';

    /**
     * Classes outside src/ whose methods examples may call. Others (PHP built-ins, third-party libraries) are
     * listed here so that a typo in a call on them is still not flagged, but a renamed PhpEpub method is.
     */
    private const array EXTERNAL_CLASSES = [
        'ZipArchive',
        'DOMDocument',
        'DOMXPath',
        'DOMElement',
        'DOMNode',
        'SimpleXMLElement',
        'DateTime',
        'DateTimeImmutable',
        'Exception',
        'Throwable',
        'TCPDF',
        'Dompdf\\Dompdf',
        'Dompdf\\Options',
    ];

    /**
     * Lower-case methods of other libraries that examples call, such as a PSR-7 request in the web upload example.
     */
    private const array OTHER_METHODS = ['getbody', 'getcontents'];

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function exampleProvider(): iterable
    {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/README.md'], self::markdownFiles($root . '/docs'));
        foreach ($files as $file) {
            $relative = ltrim(str_replace([$root, '\\'], ['', '/'], $file), '/');
            foreach (self::extractBlocks((string) file_get_contents($file)) as [$line, $code]) {
                yield $relative . ':' . $line => [$relative, $line, $code];
            }
        }
    }

    #[DataProvider('exampleProvider')]
    public function testExampleIsValid(string $file, int $line, string $code): void
    {
        $php = self::toPhp($code);
        $where = sprintf('%s, php block at line %d', $file, $line);

        try {
            PhpToken::tokenize($php, TOKEN_PARSE);
        } catch (ParseError $e) {
            $this->fail(sprintf(
                '%s does not parse: %s (line %d of the block). Fix the example, or put %s above it if it is meant to be a fragment.',
                $where,
                $e->getMessage(),
                $e->getLine(),
                self::SKIP_MARKER
            ));
        }

        $problems = [];
        if (preg_match_all('/^use\s+(PhpEpub\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $php, $uses)) {
            foreach ($uses[1] as $class) {
                if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
                    $problems[] = sprintf('imports %s, which does not exist', $class);
                }
            }
        }

        $known = self::knownMethods() + array_fill_keys(self::OTHER_METHODS, true);
        $names = [];
        if (preg_match_all('/(?:->|\?->|::)\s*([A-Za-z_]\w*)\s*\(/', $php, $calls)) {
            $names = array_unique($calls[1]);
        }
        foreach ($names as $name) {
            if (! isset($known[strtolower($name)])) {
                $problems[] = sprintf('calls %s(), which is not a public method of any class in src/ (renamed or removed?)', $name);
            }
        }

        $this->assertSame([], $problems, sprintf("%s:\n  %s", $where, implode("\n  ", $problems)));
    }

    public function testTheExamplesAreFound(): void
    {
        $count = 0;
        foreach (self::exampleProvider() as $_) {
            ++$count;
        }
        $this->assertGreaterThan(40, $count, 'The documentation examples were not found.');
    }

    /**
     * Turns a block into a complete PHP file. A block that is a method signature (the API reference blocks, such
     * as "public function save(?string $path = null): void") is wrapped in an interface so that it can be parsed.
     */
    private static function toPhp(string $code): string
    {
        if (str_starts_with(ltrim($code), '<?php')) {
            return $code;
        }
        if (preg_match('/^\s*public\s/', $code) === 1) {
            // The reference blocks write generics as "array<int, string>"; PHP only knows "array".
            $code = (string) preg_replace('/\b(array|list)<[^>]*>/', 'array', $code);
            // A signature ends with ")" or ": type"; a multi-line one ends on its own closing line.
            $code = (string) preg_replace('/^(\s*public\s.*?[^(,\s])\s*;?[ \t]*$/m', '$1;', $code);
            $code = (string) preg_replace('/^(\)(?::\s*.+?)?)\s*;?[ \t]*$/m', '$1;', $code);

            return "<?php\ninterface Signature\n{\n" . rtrim($code) . "\n}\n";
        }

        return "<?php\n" . $code;
    }

    /**
     * @return list<string>
     */
    private static function markdownFiles(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<array{int, string}> line number of the opening fence and the code of each php block
     */
    private static function extractBlocks(string $markdown): array
    {
        $blocks = [];
        $lines = preg_split('/\R/', $markdown) ?: [];
        $inside = false;
        $skip = false;
        $start = 0;
        $code = [];
        $previous = '';
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (! $inside && preg_match('/^```php\s*$/', $trimmed) === 1) {
                $inside = true;
                $skip = $previous === self::SKIP_MARKER;
                $start = $index + 1;
                $code = [];
            } elseif ($inside && str_starts_with($trimmed, '```')) {
                $inside = false;
                if (! $skip) {
                    $blocks[] = [$start, implode("\n", $code) . "\n"];
                }
            } elseif ($inside) {
                $code[] = $line;
            }
            if (! $inside && $trimmed !== '') {
                $previous = $trimmed;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, true> lower-case names of the public methods of src/ and of the external classes
     */
    private static function knownMethods(): array
    {
        static $methods = null;
        if ($methods !== null) {
            return $methods;
        }

        $methods = [];
        $src = dirname(__DIR__) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        $classes = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $src)) + 1, -4);
                $classes[] = 'PhpEpub\\' . str_replace('/', '\\', $relative);
            }
        }
        foreach (array_merge($classes, self::EXTERNAL_CLASSES) as $class) {
            if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $methods[strtolower($method->getName())] = true;
            }
        }

        return $methods;
    }
}
