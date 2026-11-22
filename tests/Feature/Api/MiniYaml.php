<?php

namespace Tests\Feature\Api;

use RuntimeException;

/**
 * Tiny YAML reader for docs/openapi/v2.yaml (symfony/yaml is not a dependency). It supports only
 * the subset the document uses: full-line comments, block mappings and sequences indented with
 * spaces, single-line plain/quoted scalars and simple one-line flow lists/empty maps. Anything
 * else (tabs, anchors, block scalars, duplicate keys, inconsistent indentation) throws, which
 * doubles as a structural lint of the file.
 */
final class MiniYaml
{
    /** @var list<array{0: int, 1: string, 2: int}> indent, text, source line number */
    private array $lines = [];

    private int $pos = 0;

    /** @return array<array-key, mixed> */
    public static function parse(string $yaml): array
    {
        $parser = new self;
        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $n => $raw) {
            if (str_contains($raw, "\t")) {
                throw new RuntimeException('Tabs are not allowed (line '.($n + 1).').');
            }
            if (trim($raw) === '' || str_starts_with(ltrim($raw), '#')) {
                continue;
            }
            $parser->lines[] = [strlen($raw) - strlen(ltrim($raw)), trim($raw), $n + 1];
        }

        $result = $parser->node($parser->lines[0][0] ?? 0);
        if ($parser->pos < count($parser->lines)) {
            throw new RuntimeException('Unexpected content at line '.$parser->lines[$parser->pos][2]);
        }

        return (array) $result;
    }

    private function node(int $indent): mixed
    {
        $text = $this->lines[$this->pos][1];

        return ($text === '-' || str_starts_with($text, '- ')) ? $this->sequence($indent) : $this->mapping($indent);
    }

    /** @return array<string, mixed> */
    private function mapping(int $indent): array
    {
        $map = [];
        while ($this->pos < count($this->lines) && $this->lines[$this->pos][0] === $indent) {
            [, $text, $no] = $this->lines[$this->pos];
            if ($text === '-' || str_starts_with($text, '- ')) {
                break;
            }
            if (! preg_match('/^("[^"]*"|\'[^\']*\'|[^\s:\'"\[\{][^:]*?):(?:\s+(.*))?$/', $text, $m)) {
                throw new RuntimeException("Cannot parse mapping entry at line {$no}.");
            }
            $key = $this->scalar($m[1]);
            $key = is_string($key) ? $key : (string) $key;
            if (array_key_exists($key, $map)) {
                throw new RuntimeException("Duplicate key '{$key}' at line {$no}.");
            }
            $value = $m[2] ?? '';
            $this->pos++;

            if ($value === '') {
                $map[$key] = ($this->pos < count($this->lines) && $this->lines[$this->pos][0] > $indent)
                    ? $this->node($this->lines[$this->pos][0])
                    : null;
            } else {
                $map[$key] = $this->scalar($value);
            }
        }

        if ($this->pos < count($this->lines) && $this->lines[$this->pos][0] > $indent) {
            throw new RuntimeException('Bad indentation at line '.$this->lines[$this->pos][2]);
        }

        return $map;
    }

    /** @return list<mixed> */
    private function sequence(int $indent): array
    {
        $list = [];
        while ($this->pos < count($this->lines) && $this->lines[$this->pos][0] === $indent
            && ($this->lines[$this->pos][1] === '-' || str_starts_with($this->lines[$this->pos][1], '- '))) {
            [, $text, $no] = $this->lines[$this->pos];
            $rest = trim(substr($text, 1));

            if ($rest === '') {
                $this->pos++;
                $list[] = ($this->pos < count($this->lines) && $this->lines[$this->pos][0] > $indent)
                    ? $this->node($this->lines[$this->pos][0]) : null;
            } elseif (preg_match('/^("[^"]*"|\'[^\']*\'|[^\s:\'"\[\{][^:]*?):(\s|$)/', $rest)) {
                // "- key: value" starts an inline mapping whose keys line up two columns in.
                $this->lines[$this->pos] = [$indent + 2, $rest, $no];
                $list[] = $this->mapping($indent + 2);
            } else {
                $this->pos++;
                $list[] = $this->scalar($rest);
            }
        }

        return $list;
    }

    private function scalar(string $text): mixed
    {
        $text = trim($text);

        if ($text === '[]') {
            return [];
        }
        if ($text === '{}') {
            return [];
        }
        if (str_starts_with($text, '[')) {
            if (! str_ends_with($text, ']')) {
                throw new RuntimeException("Unterminated flow list: {$text}");
            }
            $inner = trim(substr($text, 1, -1));

            return $inner === '' ? [] : array_map(fn (string $p) => $this->scalar($p), $this->splitFlow($inner));
        }
        if ($text[0] === '"' || $text[0] === "'") {
            $quote = $text[0];
            if (strlen($text) < 2 || substr($text, -1) !== $quote) {
                throw new RuntimeException("Unterminated quoted scalar: {$text}");
            }
            $inner = substr($text, 1, -1);

            return $quote === "'" ? str_replace("''", "'", $inner) : stripcslashes($inner);
        }
        if ($text[0] === '&' || $text[0] === '*' || $text[0] === '|' || $text[0] === '>' || $text[0] === '{') {
            throw new RuntimeException("Unsupported YAML construct: {$text}");
        }

        return match (true) {
            $text === 'true' => true,
            $text === 'false' => false,
            $text === 'null' || $text === '~' => null,
            (bool) preg_match('/^-?\d+$/', $text) => (int) $text,
            (bool) preg_match('/^-?\d+\.\d+$/', $text) => (float) $text,
            default => $text,
        };
    }

    /** @return list<string> */
    private function splitFlow(string $inner): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        foreach (str_split($inner) as $ch) {
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $current .= $ch;
            } elseif ($ch === ',') {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $parts[] = trim($current);

        return $parts;
    }
}
