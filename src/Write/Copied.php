<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use TresBienTech\Drupatch\Fetch\Vendoring;

/**
 * What a copy step left in the site, and the declarations as they stand once it has.
 *
 * @phpstan-import-type CopiedRow from Vendoring
 * @phpstan-import-type RefusedRow from Vendoring
 *
 * @phpstan-type Declaration array{package: string, title: string, source: string, provenance: array<string, string>}
 */
class Copied
{
    /**
     * @param list<CopiedRow>   $vendored     the files this run wrote
     * @param list<CopiedRow>   $kept         the files the site already held
     * @param list<CopiedRow>   $moved        the files whose merge request moved since the site copied it
     * @param list<RefusedRow>  $refused      the declarations the run could not copy
     * @param list<Declaration> $declarations every declaration, each copied one naming its file in the site
     */
    public function __construct(
        public readonly array $vendored,
        public readonly array $kept,
        public readonly array $moved,
        public readonly array $refused,
        public readonly array $declarations,
    ) {
    }

    /**
     * The copy's rows over the declarations they came from, each copied declaration naming its file rather than the URL.
     *
     * @param list<Declaration> $declarations
     * @param list<CopiedRow>   $vendored
     * @param list<CopiedRow>   $kept
     * @param list<CopiedRow>   $moved
     * @param list<RefusedRow>  $refused
     */
    public static function after(array $declarations, array $vendored, array $kept, array $moved, array $refused): self
    {
        foreach ([...$vendored, ...$kept, ...$moved] as $row) {
            foreach ($declarations as $i => $declaration) {
                if ($declaration['package'] === $row['package'] && $declaration['title'] === $row['title']) {
                    $declarations[$i]['source'] = $row['path'];
                    $declarations[$i]['provenance'] = [] === $row['provenance'] ? $declaration['provenance'] : $row['provenance'];
                }
            }
        }

        return new self($vendored, $kept, $moved, $refused, $declarations);
    }

    /**
     * A run that copies nothing.
     *
     * @param list<Declaration> $declarations
     */
    public static function none(array $declarations): self
    {
        return new self([], [], [], [], $declarations);
    }

    /**
     * The same copies over the declarations a later read of the site holds.
     *
     * @param list<Declaration> $declarations
     */
    public function over(array $declarations): self
    {
        return new self($this->vendored, $this->kept, $this->moved, $this->refused, $declarations);
    }

    /**
     * The files this run wrote itself, which no refusal protects.
     *
     * A copy's bytes came from the URL its declaration still records, so git
     * has nothing to restore and a refusal would protect nobody.
     *
     * @return list<string>
     */
    public function created(): array
    {
        return \array_column($this->vendored, 'path');
    }

    /**
     * One repoint per declaration whose bytes are now a file in the site.
     *
     * A copy already declared at its path still gets one when the run lifted
     * a record onto its declaration.
     *
     * @return list<array{action: string, package: string, title: string, path: string, provenance: array<string, string>}>
     */
    public function changes(): array
    {
        $out = [];
        foreach ([...$this->vendored, ...$this->kept, ...$this->moved] as $row) {
            if ($row['source'] !== $row['path'] || [] !== $row['provenance']) {
                $out[] = ['action' => ConfigRewriter::REPOINTED, 'package' => $row['package'], 'title' => $row['title'], 'path' => $row['path'], 'provenance' => $row['provenance']];
            }
        }

        return $out;
    }
}
