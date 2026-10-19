<?php
namespace VocabSync;

/**
 * Compares the text before and after, word by word, and marks only what changed.
 *
 * Adapted from Revision History, which solved the same problem first. It is
 * copied in whole rather than referenced, because Vocab Sync has to work
 * whether or not that module is installed.
 *
 * Why: a term can be a whole paragraph long (the longest seen was 531 characters).
 * Changing one date and then showing both whole texts leaves the reader to compare
 * them line by line, which tells them nothing.
 */
class Diff
{
    /**
     * @param string   $before the old text
     * @param string   $after  the new text
     * @param callable $e      HTML escape function
     * @return array{0:string,1:string} [HTML of the before side, HTML of the after side]
     */
    public static function marked($before, $after, callable $e)
    {
        $b = (string) $before;
        $a = (string) $after;

        $split = function ($s) {
            // // A Thai letter is kept together with the vowels and tone marks written on it,
            // // so the strike-through never cuts through what is written as one character.
            preg_match_all(
                '/(?=[\x{0E00}-\x{0E7F}])\X|[^\s\x{0E00}-\x{0E7F}]+|\s+/u',
                $s,
                $m
            );
            return $m[0];
        };

        $x = $split($b);
        $y = $split($a);
        $n = count($x);
        $m = count($y);

        // // Two very long texts at once make the comparison table too big.
        // // Then it only says the whole text was replaced, rather than hang the page.
        if ($n * $m > 250000) {
            return [
                '<del>' . nl2br($e($b)) . '</del>',
                '<ins>' . nl2br($e($a)) . '</ins>',
            ];
        }

        // // Take off the identical start and end first — most edits only touch the middle.
        $pre = 0;
        while ($pre < $n && $pre < $m && $x[$pre] === $y[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $n - $pre && $suf < $m - $pre && $x[$n - 1 - $suf] === $y[$m - 1 - $suf]) {
            $suf++;
        }

        $xs = array_slice($x, $pre, $n - $pre - $suf);
        $ys = array_slice($y, $pre, $m - $pre - $suf);
        $p = count($xs);
        $q = count($ys);

        $L = array_fill(0, $p + 1, array_fill(0, $q + 1, 0));
        for ($i = $p - 1; $i >= 0; $i--) {
            for ($j = $q - 1; $j >= 0; $j--) {
                $L[$i][$j] = ($xs[$i] === $ys[$j])
                    ? $L[$i + 1][$j + 1] + 1
                    : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }

        $bo = [];
        $ao = [];
        $i = 0;
        $j = 0;
        while ($i < $p || $j < $q) {
            if ($i < $p && $j < $q && $xs[$i] === $ys[$j]) {
                $bo[] = [0, $xs[$i]];
                $ao[] = [0, $ys[$j]];
                $i++;
                $j++;
            } elseif ($j < $q && ($i >= $p || $L[$i][$j + 1] >= $L[$i + 1][$j])) {
                $ao[] = [1, $ys[$j]];
                $j++;
            } else {
                $bo[] = [1, $xs[$i]];
                $i++;
            }
        }

        $join = function (array $head, array $mid, array $tail, $tag) use ($e) {
            $out = $e(implode('', $head));
            $run = '';
            $open = false;
            foreach ($mid as $pair) {
                list($diff, $tok) = $pair;
                if ($diff && !$open) {
                    $out .= $e($run);
                    $run = '';
                    $open = true;
                } elseif (!$diff && $open) {
                    $out .= "<$tag>" . $e($run) . "</$tag>";
                    $run = '';
                    $open = false;
                }
                $run .= $tok;
            }
            $out .= $open ? "<$tag>" . $e($run) . "</$tag>" : $e($run);
            $out .= $e(implode('', $tail));
            return nl2br($out);
        };

        $head = array_slice($x, 0, $pre);

        return [
            $b === '' ? '<span class="vs-none">empty</span>'
                : $join($head, $bo, array_slice($x, $n - $suf), 'del'),
            $a === '' ? '<span class="vs-none">empty</span>'
                : $join($head, $ao, array_slice($y, $m - $suf), 'ins'),
        ];
    }

    /**
     * The before and after boxes, with what changed marked.
     */
    public static function pair($before, $after, callable $e)
    {
        list($b, $a) = self::marked($before, $after, $e);
        return '<div class="vs-pair">'
            . '<div class="vs-before"><span class="vs-cap">Before</span>' . $b . '</div>'
            . '<div class="vs-after"><span class="vs-cap">After</span>' . $a . '</div>'
            . '</div>';
    }
}
