<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

final class MigrationFileRenderer
{
    private TemplateWriter $writer;

    public function __construct(?TemplateWriter $writer = null)
    {
        $this->writer = $writer ?? new TemplateWriter();
    }

    /**
     * Execute render blank for this migration file renderer.
     * @param string $className Class name.
     * @param string $id Id.
     * @param string $name Name.
     * @param MigrationTemplate $template Template.
     * @return string
     */

    public function renderBlank(
        string $className,
        string $id,
        string $name,
        MigrationTemplate $template,
    ): string {
        $uses = $template->usesForBlank();
        $imports = $this->buildImports($uses, $template);

        return $this->writer->render(
            namespace: $template->namespace,
            uses: $imports['uses'],
            className: $className,
            baseClassShort: $imports['base'],
            attributeShort: $imports['attribute'],
            id: $id,
            name: $name,
            connectionShort: $imports['connection'],
            blueprintShort: $imports['blueprint'],
            body: "\n        // -- migration code\n",
        );
    }

    /**
     * Execute render autogen for this migration file renderer.
     * @param string $className Class name.
     * @param string $id Id.
     * @param string $name Name.
     * @param MigrationTemplate $template Template.
     * @param string $body Body.
     * @return string
     */

    public function renderAutogen(
        string $className,
        string $id,
        string $name,
        MigrationTemplate $template,
        string $body,
    ): string {
        $uses = $template->usesForAutogen();
        $imports = $this->buildImports($uses, $template);

        return $this->writer->render(
            namespace: $template->namespace,
            uses: $imports['uses'],
            className: $className,
            baseClassShort: $imports['base'],
            attributeShort: $imports['attribute'],
            id: $id,
            name: $name,
            connectionShort: $imports['connection'],
            blueprintShort: $imports['blueprint'],
            body: $body,
        );
    }

    /**
     * @param array<int,string> $uses
     * @return array{uses:array<int,string>,attribute:string,base:string,connection:string,blueprint:string}
     */
    /**
     * @param array<int,string> $uses
     * @return array{uses:array<int,string>,attribute:string,base:string,connection:string,blueprint:string,map:array<string,string>}
     */
    private function buildImports(array $uses, MigrationTemplate $template): array
    {
        $imports = [];
        $aliasCounts = [];

        foreach ($uses as $use) {
            $alias = $this->shortName($use);
            if (isset($aliasCounts[$alias])) {
                $aliasCounts[$alias]++;
                $alias .= $aliasCounts[$alias];
                $imports[] = $use . ' as ' . $alias;
                continue;
            }

            $aliasCounts[$alias] = 1;
            $imports[] = $use;
        }

        $map = [];
        foreach ($imports as $index => $import) {
            $trimmed = trim($import);
            $parts = preg_split('/\s+as\s+/i', $trimmed);
            if (false === $parts || empty($parts)) {
                continue;
            }
            if (count($parts) === 2) {
                $map[$parts[0]] = $parts[1];
                continue;
            }
            $map[$parts[0]] = $this->shortName($parts[0]);
        }

        return [
            'uses' => $imports,
            'attribute' => $map[$template->migrationAttributeClass] ?? $this->shortName($template->migrationAttributeClass),
            'base' => $map[$template->baseMigrationClass] ?? $this->shortName($template->baseMigrationClass),
            'connection' => $map[$template->connectionClass] ?? $this->shortName($template->connectionClass),
            'blueprint' => $map[$template->blueprintClass] ?? $this->shortName($template->blueprintClass),
            'map' => $map,
        ];
    }

    /**
     * @return array{uses:array<int,string>,map:array<string,string>}
     */
    public function resolveImports(MigrationTemplate $template, bool $autogen = false): array
    {
        $uses = $autogen ? $template->usesForAutogen() : $template->usesForBlank();
        $imports = $this->buildImports($uses, $template);

        return [
            'uses' => $imports['uses'],
            'map' => $imports['map'],
        ];
    }

    private function shortName(string $class): string
    {
        $class = trim($class);
        if ('' === $class) {
            return '';
        }

        $class = ltrim($class, '\\');
        $parts = explode('\\', $class);

        return $parts[count($parts) - 1] ?? '';
    }
}
