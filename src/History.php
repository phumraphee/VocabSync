<?php
namespace VocabSync;

/**
 * Value rewrites of this module, told to Revision History when it is installed (1.39.0).
 *
 * Vocab Sync writes values with its own SQL — one statement for a whole vocabulary, because a
 * row at a time took longer than the proxy waits. Revision History only hears saves made through
 * Omeka, so without this it found these rewrites later and could only say "changed by nobody".
 * Revision History's Outside reads the records before and after a write and writes the entry
 * under the person signed in. With Revision History 1.2.1 or later a rewrite of any size is one
 * statement, and Outside::valuesRewritten writes the entries from a copy of the values as they were
 * (rewritten()); with 1.2.2, Outside::valuesShared writes ONE entry for the statement, each record
 * keeping its own lines, and a term renamed is one entry too (Entries::addShared). With 1.2.0:
 * CHUNK records at a time through Outside::around, up to MAX_RECORDS records; a larger rewrite is one statement plus one Entries row per record (noted()). All of one
 * press under one run key.
 *
 * Without Revision History every write is made exactly as before, in one statement.
 */
final class History
{
    const CHUNK = 500;

    /** Revision History 1.2.0 only: above this many records a rewrite through Outside::around is too
     *  slow for a page (about 7 ms a record against one statement for all), so it stays one statement
     *  and each record gets one Entries row instead (noted()). */
    const MAX_RECORDS = 200;

    public static function on(): bool
    {
        return class_exists(\RevisionHistory\Outside::class);
    }

    /**
     * @param int[] $ids items, media or item sets the write changes
     * @return mixed what $write returned
     */
    public static function around($services, array $ids, callable $write, string $note, string $run = '')
    {
        if (!self::on() || !$ids) {
            return $write();
        }
        /* the fourth argument is read by Revision History versions that fold a run into one card */
        return \RevisionHistory\Outside::fromServices($services)->around($ids, $write, $note, $run === '' ? [] : ['run' => mb_substr($run, 0, 40)]);
    }

    /**
     * A term's wording changed (Rename, or the undo of one): no value row changes, yet every record
     * citing the term now reads differently. Each of them gets one entry in Revision History (1.2.0
     * Entries), automatic and folded away on its page, with the wording before and after.
     */
    public static function wording($services, int $vocabId, int $termId, ?string $before, string $after, string $ref): void
    {
        if (!class_exists(\RevisionHistory\Entries::class)) {
            return;
        }
        try {
            $conn = $services->get('Omeka\Connection');
            $ids = array_map('intval', $conn->fetchFirstColumn(
                'SELECT DISTINCT resource_id FROM value WHERE type = ? AND value = ?',
                ['vocabsync:' . $vocabId, (string) $termId]
            ));
            if (!$ids) {
                return;
            }
            $vocab = (string) $conn->fetchOne('SELECT label FROM vocab_sync_vocab WHERE id = ?', [$vocabId]);
            $entry = [
                'resources' => $ids,
                'module' => 'VocabSync',
                'label' => 'Vocab Sync',
                'kind' => 'term.renamed',
                'kind_label' => 'Wording changed',
                'summary' => sprintf('A term of "%s" now reads "%s" (was "%s")', $vocab, $after, (string) $before),
                'before' => $before,
                'after' => $after,
                'automatic' => true,
                'run' => mb_substr('vocabsync:' . $ref, 0, 40),
                'ref' => $ref,
            ];
            $entries = \RevisionHistory\Entries::fromServices($services);
            if (method_exists($entries, 'addShared')) {
                /* 1.2.2: one entry for all of them, each record's page shows it */
                $entries->addShared($entry);
            } else {
                /* 'limit' => 0: every citing record, however many (RH 1.2.1; 1.2.0 ignores it) */
                $entries->addMany([$entry], ['limit' => 0]);
            }
        } catch (\Throwable $e) {
            /* the rename stands; Entries logs its own failures */
        }
    }

    /**
     * Revision History 1.2.1 or later: the values the statement is about to change are copied as
     * they are into a temporary table, the one statement runs, and Outside::valuesRewritten writes
     * each record's entry from that copy (exactly the lines a save would have) and takes the data
     * check's new numbers in the same pass. Any size: the records are not read twice.
     */
    private static function rewritten($services, $conn, string $idsSql, array $idsParams, string $updateSql, array $params, string $note, string $run): int
    {
        $head = 'SELECT DISTINCT v.resource_id FROM value v';
        if (strpos(ltrim($idsSql), $head) !== 0) {
            return (int) $conn->executeStatement($updateSql, $params);
        }
        $copy = 'vocab_sync_before_' . bin2hex(random_bytes(4));
        $conn->executeStatement("CREATE TEMPORARY TABLE $copy (value_id INT NOT NULL PRIMARY KEY, resource_id INT NOT NULL,
            property_id INT NOT NULL, before_type VARCHAR(190) NOT NULL, before_value LONGTEXT NULL) ENGINE = InnoDB");
        try {
            /* IGNORE: a value two terms match (words that differ only in case) is one row */
            $conn->executeStatement("INSERT IGNORE INTO $copy SELECT v.id, v.resource_id, v.property_id, v.type, v.value FROM value v"
                . substr(ltrim($idsSql), strlen($head)), $idsParams);
            $n = (int) $conn->executeStatement($updateSql, $params);
            $outside = \RevisionHistory\Outside::fromServices($services);
            $sql = "SELECT value_id, resource_id, property_id, before_type, before_value FROM $copy";
            if (method_exists($outside, 'valuesShared')) {
                /* 1.2.2: one entry for the whole statement, each record keeping its own lines */
                $outside->valuesShared($sql, [], $note, ['run' => mb_substr($run, 0, 40)]);
            } else {
                $outside->valuesRewritten($sql, [], $note, ['run' => mb_substr($run, 0, 40)]);
            }
            return $n;
        } finally {
            $conn->executeStatement("DROP TEMPORARY TABLE IF EXISTS $copy");
        }
    }

    /** record id => how many of its values the statement matches ($idsSql with a count) */
    private static function counts($conn, string $idsSql, array $params): array
    {
        $head = 'SELECT DISTINCT v.resource_id FROM value v';
        if (strpos(ltrim($idsSql), $head) !== 0) {
            return [];
        }
        $sql = 'SELECT v.resource_id, COUNT(*) FROM value v' . substr(ltrim($idsSql), strlen($head)) . ' GROUP BY v.resource_id';
        return array_map('intval', $conn->fetchAllKeyValue($sql, $params));
    }

    /** One entry per record for a rewrite too large to hand over record by record. */
    private static function noted($services, array $counts, string $note, string $run): void
    {
        if (!class_exists(\RevisionHistory\Entries::class) || !$counts) {
            return;
        }
        $what = preg_replace('/^Vocab Sync: /', '', $note);
        $rows = [];
        foreach ($counts as $id => $n) {
            $rows[] = [
                'resource' => (int) $id,
                'module' => 'VocabSync',
                'label' => 'Vocab Sync',
                'kind' => 'values.rewritten',
                'kind_label' => 'Values rewritten',
                'summary' => ucfirst($what) . ($n ? sprintf(' (%d value%s of this record)', $n, $n === 1 ? '' : 's') : ''),
                'values' => true,   /* this record's values changed now: the data check may take its new number */
                'ref' => $run . '#' . $id,
            ];
        }
        try {
            \RevisionHistory\Entries::fromServices($services)->addMany($rows);
        } catch (\Throwable $e) {
        }
    }

    /**
     * An UPDATE of `value v … WHERE …`, run as it is without Revision History, or once per
     * CHUNK records with "AND v.resource_id IN (…)" added to its WHERE.
     *
     * @param string $idsSql SELECT DISTINCT v.resource_id … of exactly the rows $updateSql changes
     * @return int rows changed
     */
    public static function rewrite($services, string $idsSql, array $idsParams, string $updateSql, array $params, string $note, string $run): int
    {
        $conn = $services->get('Omeka\Connection');
        if (!self::on()) {
            return (int) $conn->executeStatement($updateSql, $params);
        }
        if (method_exists(\RevisionHistory\Outside::class, 'valuesRewritten')) {
            return self::rewritten($services, $conn, $idsSql, $idsParams, $updateSql, $params, $note, $run);
        }
        $ids = array_map('intval', $conn->fetchFirstColumn($idsSql, $idsParams));
        if (count($ids) > self::MAX_RECORDS) {
            /* one statement, as before; then one entry per record from Revision History's Entries,
               which writes rows and reads nothing of the records */
            $counts = self::counts($conn, $idsSql, $idsParams);
            $n = (int) $conn->executeStatement($updateSql, $params);
            self::noted($services, $counts ?: array_fill_keys($ids, 0), $note, $run);
            return $n;
        }
        $n = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $sql = $updateSql . ' AND v.resource_id IN (' . implode(',', $chunk) . ')';
            $n += (int) self::around($services, $chunk, function () use ($conn, $sql, $params) {
                return $conn->executeStatement($sql, $params);
            }, $note, $run);
        }
        return $n;
    }
}
