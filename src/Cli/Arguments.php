<?php

declare(strict_types=1);

namespace PhpEpub\Cli;

/**
 * The arguments of one command: positional values, options that take a value (--to=pdf, --to pdf, -o file) and flags
 * (--force). Nothing outside the lists a command declares is accepted.
 *
 * @internal Used by Application.
 */
final readonly class Arguments
{
    /**
     * @param list<string> $positionals
     * @param array<string, list<string>> $values Option name => the values given, in order.
     * @param array<string, true> $flags
     */
    private function __construct(public array $positionals, private array $values, private array $flags)
    {
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $valueOptions The long names of the options that take a value.
     * @param list<string> $flags The long names of the options that do not.
     * @param array<string, string> $aliases Short name => long name.
     *
     * @throws UsageException For an option the command does not know, or a value option without a value.
     */
    public static function parse(array $arguments, array $valueOptions, array $flags, array $aliases = ['o' => 'output', 'h' => 'help']): self
    {
        $positionals = [];
        $values = [];
        $given = [];
        $onlyPositionals = false;

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];

            if ($onlyPositionals || $argument === '-' || ! str_starts_with($argument, '-')) {
                $positionals[] = $argument;

                continue;
            }

            if ($argument === '--') {
                $onlyPositionals = true;

                continue;
            }

            $inline = null;
            if (str_starts_with($argument, '--')) {
                $name = substr($argument, 2);
                if (str_contains($name, '=')) {
                    [$name, $inline] = explode('=', $name, 2);
                }
            } else {
                $name = $aliases[substr($argument, 1)] ?? throw new UsageException("Unknown option: {$argument}");
            }

            if (in_array($name, $flags, true)) {
                $inline === null || throw new UsageException("The option --{$name} takes no value");
                $given[$name] = true;

                continue;
            }

            in_array($name, $valueOptions, true) || throw new UsageException("Unknown option: --{$name}");
            $values[$name][] = $inline ?? $arguments[++$index] ?? throw new UsageException("The option --{$name} needs a value");
        }

        return new self($positionals, $values, $given);
    }

    public function flag(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    /**
     * The last value given for an option.
     */
    public function value(string $name): ?string
    {
        $values = $this->values[$name] ?? [];

        return $values === [] ? null : $values[count($values) - 1];
    }

    /**
     * Every value given for an option, in order.
     *
     * @return list<string>
     */
    public function values(string $name): array
    {
        return $this->values[$name] ?? [];
    }
}
