<?php

declare(strict_types=1);

namespace TresBienTech\Drupatch\Write;

use TresBienTech\Drupatch\Fetch\Vendoring;
use TresBienTech\Drupatch\Plan\Plan;
use TresBienTech\Drupatch\Read\Scope;

/**
 * The patches half of a **manager upgrade**: copies into the site the ones a re-roll has to land on, writes the re-rolls, and hands back the declarations as they now stand.
 *
 * 2.x applies with `git apply` alone, so a patch only a lenient
 * apply took stops working. The service re-rolled those, and a re-roll has
 * nowhere to go while a declaration names a URL.
 *
 * @phpstan-import-type CopiedRow from Vendoring
 * @phpstan-import-type RefusedRow from Vendoring
 * @phpstan-import-type WrittenRow from \TresBienTech\Drupatch\Render\Outcomes
 *
 * @phpstan-type Declaration array{package: string, title: string, source: string, provenance: array<string, string>}
 * @phpstan-type Moved array{vendored: list<CopiedRow>, refused: list<RefusedRow>, written: list<WrittenRow>, unwritten: list<array{package: string, title: string, path: string, reason: string, lifts: string, shipped: bool}>, open: list<array{path: string, regions: int}>, declarations: list<Declaration>}
 */
class Move
{
    public function __construct(
        private readonly string $root,
        private readonly Vendoring $vendoring,
        /** Asked before a file is replaced; null replaces everything. `--force`. */
        private readonly ?WorkingTree $tree,
    ) {
    }

    /**
     * Copies and re-rolls, in that order, because a re-roll is written over the file the declaration names.
     *
     * @param list<Declaration> $declarations every patch the site declares
     * @param Scope             $scope        which of them are copied into the site
     *
     * @return Moved
     */
    public function run(Plan $plan, array $declarations, Scope $scope): array
    {
        $copied = $this->vendoring->run($declarations, $scope, false);
        $written = (new PatchFiles($this->root, $this->tree, $copied))->write($plan);

        return [
            'vendored' => $copied->vendored,
            'refused' => $copied->refused,
            'written' => $written['written'],
            'unwritten' => $written['refused'],
            'open' => self::open($written['written']),
            'declarations' => self::recorded($copied->declarations, $written['written']),
        ];
    }

    /**
     * The declarations carrying what each re-roll recorded: the release it was merged against, over the record the copy arrived with.
     *
     * The source is left alone. A clean re-roll replaced the file the
     * declaration already names, and a conflicted one went to a file no
     * declaration may point at.
     *
     * @param list<Declaration> $declarations
     * @param list<WrittenRow>  $rows
     *
     * @return list<Declaration>
     */
    private static function recorded(array $declarations, array $rows): array
    {
        foreach ($rows as $row) {
            foreach ($declarations as $i => $declaration) {
                if ($declaration['package'] === $row['package'] && $declaration['title'] === $row['title'] && [] !== $row['provenance']) {
                    $declarations[$i]['provenance'] = $row['provenance'];
                }
            }
        }

        return $declarations;
    }

    /**
     * The re-rolls a person still has to decide, which is what stops the move.
     *
     * @param list<WrittenRow> $rows
     *
     * @return list<array{path: string, regions: int}>
     */
    private static function open(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['regions'] > 0) {
                $out[] = ['path' => $row['path'], 'regions' => $row['regions']];
            }
        }

        return $out;
    }
}
