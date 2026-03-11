<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

final class TemplateWriter
{
    /**
     * @param array<int,string> $uses
     */
    public function render(
        string $namespace,
        array $uses,
        string $className,
        string $baseClassShort,
        string $attributeShort,
        string $id,
        string $name,
        string $connectionShort,
        string $blueprintShort,
        string $body,
    ): string {
        $usesBlock = $this->renderUses($uses);
        $idValue = $this->exportValue($id);
        $nameValue = $this->exportValue($name);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};
            {$usesBlock}

            #[{$attributeShort}(id: {$idValue}, name: {$nameValue})]
            final class {$className} extends {$baseClassShort}
            {
                public function __invoke({$connectionShort} \$runner, {$blueprintShort} \$blueprint): void
                {{$body}
                }
            }
            PHP;
    }

    /**
     * @param array<int,string> $uses
     */
    public function renderUses(array $uses): string
    {
        if (empty($uses)) {
            return '';
        }

        $lines = [];
        foreach ($uses as $use) {
            $lines[] = 'use ' . $use . ';';
        }

        return "\n" . implode("\n", $lines) . "\n";
    }

    /**
     * Export a PHP value into inline source code for generated migration templates.
     *
     * @param mixed $value Value to export.
     * @return string
     */
    public function exportValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
            return "'{$escaped}'";
        }

        if (is_array($value)) {
            return $this->exportArray($value);
        }

        return 'null';
    }

    private function exportArray(array $value): string
    {
        if ([] === $value) {
            return '[]';
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $items = [];

        foreach ($value as $key => $item) {
            $rendered = $this->exportValue($item);
            if ($isList) {
                $items[] = $rendered;
                continue;
            }
            $items[] = $this->exportValue($key) . ' => ' . $rendered;
        }

        return '[' . implode(', ', $items) . ']';
    }
}
