<?php
namespace VocabSync;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleEvent;
use Laminas\ModuleManager\ModuleManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /**
     * Put the menu entry above Vocabularies.
     *
     * A module's config is always merged after the core's, so the order cannot be
     * changed with config alone · Laminas fires EVENT_MERGE_CONFIG after the merge
     * and before it is used, the one point where the place can be set properly.
     *
     * If the Vocabularies entry cannot be found (the core changed its structure), the
     * entry stays at the end — a menu in the wrong place beats a missing menu.
     */
    public function init(ModuleManagerInterface $moduleManager)
    {
        $moduleManager->getEventManager()->attach(
            ModuleEvent::EVENT_MERGE_CONFIG,
            [$this, 'onMergeConfig']
        );
    }

    public function onMergeConfig(ModuleEvent $event)
    {
        $listener = $event->getConfigListener();
        $config = $listener->getMergedConfig(false);

        if (empty($config['navigation']['AdminResource'])
            || !is_array($config['navigation']['AdminResource'])
        ) {
            return;
        }

        $items = $config['navigation']['AdminResource'];

        // // Take our entry out first.
        $ours = null;
        foreach ($items as $i => $item) {
            if (isset($item['route']) && $item['route'] === 'admin/vocab-sync') {
                $ours = $item;
                unset($items[$i]);
                break;
            }
        }
        if ($ours === null) {
            return;
        }
        $items = array_values($items);

        // // Find Vocabularies · both controller and label are compared, because
        // // either may change with the version or the translation.
        $target = null;
        foreach ($items as $i => $item) {
            $controller = isset($item['controller']) ? $item['controller'] : null;
            $label = isset($item['label']) ? $item['label'] : null;
            if ($controller === 'vocabulary' || $label === 'Vocabularies') {
                $target = $i;
                break;
            }
        }

        if ($target === null) {
            $items[] = $ours; // not found: append it as before
        } else {
            array_splice($items, $target, 0, [$ours]);
        }

        $config['navigation']['AdminResource'] = $items;
        $listener->setMergedConfig($config);
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            null,
            'VocabSync\Controller\Admin\Index'
        );
    }

    public function install(ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');

        $conn->exec(
            'CREATE TABLE vocab_sync_vocab (
                id INT AUTO_INCREMENT NOT NULL,
                label VARCHAR(190) NOT NULL,
                owner_id INT DEFAULT NULL,
                owner_name VARCHAR(190) DEFAULT NULL COMMENT "snapshot: survives user deletion",
                term_order VARCHAR(16) NOT NULL DEFAULT \'label\' COMMENT "label | position",
                created DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_vocab_sync_vocab_label (label),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

        $conn->exec(
            'CREATE TABLE vocab_sync_term (
                id INT AUTO_INCREMENT NOT NULL,
                vocab_id INT NOT NULL,
                label TEXT NOT NULL COMMENT "no length cap: real terms can be whole paragraphs",
                position INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created DATETIME NOT NULL,
                modified DATETIME DEFAULT NULL,
                INDEX IDX_vocab_sync_term_vocab (vocab_id),
                INDEX IDX_vocab_sync_term_label (label(255)),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

        $conn->exec(
            'ALTER TABLE vocab_sync_term
             ADD CONSTRAINT FK_vocab_sync_term_vocab
             FOREIGN KEY (vocab_id) REFERENCES vocab_sync_vocab (id) ON DELETE CASCADE;'
        );

        // // Keep a snapshot of each import so it can be reverted exactly — needed because
        // // once a term's label is edited after the import (the whole point of this
        // // module), the current label is no longer the text that was in Custom Vocab;
        // // "the exact text at import time" has to be kept apart.
        $conn->exec(
            'CREATE TABLE vocab_sync_import_log (
                id INT AUTO_INCREMENT NOT NULL,
                old_vocab_id INT NOT NULL COMMENT "source custom_vocab.id",
                new_vocab_id INT NOT NULL,
                after_type VARCHAR(190) DEFAULT NULL COMMENT "data type the batch wrote",
                note VARCHAR(190) DEFAULT NULL,
                reverted TINYINT(1) NOT NULL DEFAULT 0,
                created DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

        // // Log of edits to terms (rename / retire / add), so they can be undone.
        $conn->exec(
            'CREATE TABLE vocab_sync_term_log (
                id INT AUTO_INCREMENT NOT NULL,
                term_id INT NOT NULL,
                vocab_id INT NOT NULL,
                action VARCHAR(32) NOT NULL,
                before_label TEXT DEFAULT NULL,
                after_label TEXT DEFAULT NULL,
                before_active TINYINT(1) DEFAULT NULL,
                after_active TINYINT(1) DEFAULT NULL,
                reverted TINYINT(1) NOT NULL DEFAULT 0,
                created DATETIME NOT NULL,
                INDEX IDX_vstl_vocab (vocab_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

        // // One central log — every action writes one row; nothing is deleted or edited.
        // // An undo is a new row pointing at the old one (undo_of_event_id);
        // // undone_by_event_id only points back, for quick lookups.
        $conn->exec(
            'CREATE TABLE vocab_sync_event (
                id INT AUTO_INCREMENT NOT NULL,
                created DATETIME NOT NULL,
                user_id INT DEFAULT NULL,
                user_name VARCHAR(190) DEFAULT NULL COMMENT "snapshot at the time",
                action VARCHAR(64) NOT NULL,
                vocab_id INT DEFAULT NULL,
                term_id INT DEFAULT NULL,
                summary TEXT NOT NULL COMMENT "one human-readable sentence",
                detail LONGTEXT DEFAULT NULL COMMENT "JSON",
                ref_table VARCHAR(64) DEFAULT NULL COMMENT "snapshot table holding the undo data",
                ref_id INT DEFAULT NULL,
                undo_of_event_id INT DEFAULT NULL,
                undone_by_event_id INT DEFAULT NULL,
                INDEX IDX_vse_created (created),
                INDEX IDX_vse_vocab (vocab_id),
                INDEX IDX_vse_ref (ref_table, ref_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

        $conn->exec(
            'CREATE TABLE vocab_sync_import_value_log (
                id INT AUTO_INCREMENT NOT NULL,
                import_log_id INT NOT NULL,
                value_id INT NOT NULL COMMENT "id of the row in the value table",
                old_type VARCHAR(190) NOT NULL COMMENT "data type before the import, e.g. customvocab:9",
                old_value TEXT NOT NULL COMMENT "exact text held before the import",
                INDEX IDX_vsivl_import_log (import_log_id),
                INDEX IDX_vsivl_value (value_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

        $conn->exec(
            'ALTER TABLE vocab_sync_import_value_log
             ADD CONSTRAINT FK_vsivl_import_log
             FOREIGN KEY (import_log_id) REFERENCES vocab_sync_import_log (id) ON DELETE CASCADE;'
        );

        // // Log of data type changes in templates, with a snapshot of the values before,
        // // every row, so they can be put back exactly rather than switched back by guess.
        $conn->exec(
            'CREATE TABLE vocab_sync_template_log (
                id INT AUTO_INCREMENT NOT NULL,
                from_type VARCHAR(190) NOT NULL,
                to_type VARCHAR(190) NOT NULL,
                rows_before LONGTEXT NOT NULL COMMENT "JSON: rtp id -> data_type before",
                row_count INT NOT NULL,
                reverted TINYINT(1) NOT NULL DEFAULT 0,
                created DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
        );

    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');

        // // Guard against removing the module while vocabsync:* values remain — removing
        // // it now would drop vocab_sync_term with the uninstall, and the values left
        // // (term ids) would become bare numbers for good, with no way back.
        // // So it always checks first; values left over throw an exception that stops
        // // the removal at once (Omeka wraps uninstall in a transaction — an exception
        // // cancels the whole removal and the module stays as it was).
        //
        // // Not yet tested whether Omeka shows this exception as a tidy flash message
        // // or as a raw stack trace (that depends on debug mode) —
        // // but the Omeka S User Manual confirms uninstall is already behind Omeka's
        // // own confirmation (the "Confirm uninstall" box), and that deactivating
        // // alone removes no data.
        $remaining = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM value WHERE type REGEXP '^vocabsync:[0-9]+$'"
        );
        if ($remaining > 0) {
            throw new \Exception(sprintf(
                'Uninstall stopped: %d values still use a Vocab Sync data type. '
                . 'Removing the module now would leave them as bare numbers with no way to '
                . 'translate them back. Go to Vocab Sync, run "Freeze for removal", then '
                . 'uninstall again.',
                $remaining
            ));
        }

        // // Turn any type still using vocabsync:<id> back into a plain literal
        // // (none should be left by now, after the check above —
        // // this line is only a safety net and should do nothing if the check works).
        $conn->exec('UPDATE value SET type = "literal" WHERE type REGEXP "^vocabsync:[0-9]+$"');
        $conn->exec('UPDATE resource_template_property SET data_type = NULL WHERE data_type REGEXP "^vocabsync:[0-9]+$"');

        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_event');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_term_log');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_template_log');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_import_value_log');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_import_log');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_term');
        $conn->exec('DROP TABLE IF EXISTS vocab_sync_vocab');
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * For anyone who installed 1.0.0 (no import log table yet) —
     * creates the missing tables without touching vocab_sync_vocab/vocab_sync_term,
     * which already hold data. Much safer than deactivating and reinstalling
     * (a reinstall calls uninstall() first, which deletes everything).
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');

        if (version_compare($oldVersion, '1.1.0', '<')) {
            $conn->exec(
                'CREATE TABLE IF NOT EXISTS vocab_sync_import_log (
                    id INT AUTO_INCREMENT NOT NULL,
                    old_vocab_id INT NOT NULL,
                    new_vocab_id INT NOT NULL,
                    created DATETIME NOT NULL,
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
            );

            $conn->exec(
                'CREATE TABLE IF NOT EXISTS vocab_sync_import_value_log (
                    id INT AUTO_INCREMENT NOT NULL,
                    import_log_id INT NOT NULL,
                    value_id INT NOT NULL,
                    old_type VARCHAR(190) NOT NULL,
                    old_value TEXT NOT NULL,
                    INDEX IDX_vsivl_import_log (import_log_id),
                    INDEX IDX_vsivl_value (value_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
            );

            // // ADD CONSTRAINT may repeat if it already partly ran — guarded with try/catch.
            try {
                $conn->exec(
                    'ALTER TABLE vocab_sync_import_value_log
                     ADD CONSTRAINT FK_vsivl_import_log
                     FOREIGN KEY (import_log_id) REFERENCES vocab_sync_import_log (id) ON DELETE CASCADE;'
                );
            } catch (\Throwable $e) {
                // // the constraint is already there; skip
            }
        }

        if (version_compare($oldVersion, '1.14.0', '<')) {
            // // Columns that make the value log two-way (it knows which type a batch
            // // wrote, so it can be undone in either direction).
            foreach ([
                'ALTER TABLE vocab_sync_import_log ADD after_type VARCHAR(190) DEFAULT NULL',
                'ALTER TABLE vocab_sync_import_log ADD note VARCHAR(190) DEFAULT NULL',
                'ALTER TABLE vocab_sync_import_log ADD reverted TINYINT(1) NOT NULL DEFAULT 0',
            ] as $sql) {
                try {
                    $conn->exec($sql);
                } catch (\Throwable $e) {
                    // // the column is already there
                }
            }

            $conn->exec(
                'CREATE TABLE IF NOT EXISTS vocab_sync_term_log (
                    id INT AUTO_INCREMENT NOT NULL,
                    term_id INT NOT NULL,
                    vocab_id INT NOT NULL,
                    action VARCHAR(32) NOT NULL,
                    before_label TEXT DEFAULT NULL,
                    after_label TEXT DEFAULT NULL,
                    before_active TINYINT(1) DEFAULT NULL,
                    after_active TINYINT(1) DEFAULT NULL,
                    reverted TINYINT(1) NOT NULL DEFAULT 0,
                    created DATETIME NOT NULL,
                    INDEX IDX_vstl_vocab (vocab_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
            );

            $conn->exec(
                'CREATE TABLE IF NOT EXISTS vocab_sync_template_log (
                    id INT AUTO_INCREMENT NOT NULL,
                    from_type VARCHAR(190) NOT NULL,
                    to_type VARCHAR(190) NOT NULL,
                    rows_before LONGTEXT NOT NULL,
                    row_count INT NOT NULL,
                    reverted TINYINT(1) NOT NULL DEFAULT 0,
                    created DATETIME NOT NULL,
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
            );
        }

        if (version_compare($oldVersion, '1.19.0', '<')) {
            // // VARCHAR(500) was too small for real data — some terms are a whole paragraph
            // // (a full exhibition history, say); MySQL refused the whole row and the
            // // import stopped halfway · now TEXT, with an index on the first 255
            // // characters so JOINs on the label stay fast.
            foreach ([
                'ALTER TABLE vocab_sync_term MODIFY label TEXT NOT NULL',
                'ALTER TABLE vocab_sync_term ADD INDEX IDX_vocab_sync_term_label (label(255))',
                'ALTER TABLE vocab_sync_term_log MODIFY before_label TEXT DEFAULT NULL',
                'ALTER TABLE vocab_sync_term_log MODIFY after_label TEXT DEFAULT NULL',
            ] as $sql) {
                try {
                    $conn->exec($sql);
                } catch (\Throwable $e) {
                    // // the index is already there, or the column is already TEXT
                }
            }
        }

        if (version_compare($oldVersion, '1.21.0', '<')) {
            foreach ([
                'ALTER TABLE vocab_sync_vocab ADD owner_id INT DEFAULT NULL',
                'ALTER TABLE vocab_sync_vocab ADD owner_name VARCHAR(190) DEFAULT NULL',
            ] as $sql) {
                try {
                    $conn->exec($sql);
                } catch (\Throwable $e) {
                    // // the column is already there
                }
            }

            $conn->exec(
                'CREATE TABLE IF NOT EXISTS vocab_sync_event (
                    id INT AUTO_INCREMENT NOT NULL,
                    created DATETIME NOT NULL,
                    user_id INT DEFAULT NULL,
                    user_name VARCHAR(190) DEFAULT NULL,
                    action VARCHAR(64) NOT NULL,
                    vocab_id INT DEFAULT NULL,
                    term_id INT DEFAULT NULL,
                    summary TEXT NOT NULL,
                    detail LONGTEXT DEFAULT NULL,
                    ref_table VARCHAR(64) DEFAULT NULL,
                    ref_id INT DEFAULT NULL,
                    undo_of_event_id INT DEFAULT NULL,
                    undone_by_event_id INT DEFAULT NULL,
                    INDEX IDX_vse_created (created),
                    INDEX IDX_vse_vocab (vocab_id),
                    INDEX IDX_vse_ref (ref_table, ref_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;'
            );
        }

        if (version_compare($oldVersion, '1.25.0', '<')) {
            // // The order in the dropdown can be chosen · alphabetical by default,
            // // because the old order (position) is just the order terms happened to be added,
            // // which no page lets anyone arrange, so nobody meant it.
            try {
                $conn->exec(
                    "ALTER TABLE vocab_sync_vocab
                     ADD term_order VARCHAR(16) NOT NULL DEFAULT 'label'"
                );
            } catch (\Throwable $e) {
                // // the column is already there
            }
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // // The filter bar on search results: term names instead of term numbers (see readableSearchFilters).
        $sharedEventManager->attach('*', 'view.search.filters', [$this, 'readableSearchFilters']);

        // // Declare the dynamic data type names (vocabsync:1, vocabsync:2, ...)
        // // to Omeka\DataType\Manager, so they appear in the data type dropdown
        // // of the resource template page — the same way Custom Vocab does with
        // // customvocab:<id> (checked against its source).
        $sharedEventManager->attach(
            'Omeka\DataType\Manager',
            'service.registered_names',
            [$this, 'addVocabSyncDataTypeNames']
        );
    }

    /**
     * The search filter bar: show the term's name instead of its number (1.36.3).
     *
     * Vocab Sync values are stored as the term's number, so an "is exactly" filter searches by number
     * (links from other pages, such as a link on a keyword, send the number this way and find the right resources),
     * but the filter bar prints what was sent as it is, so the reader sees
     * "Keywords is exactly 803", which says nothing.
     *
     * Only the words shown change; the query is not touched, so the results are exactly the same.
     * A number is replaced only where that field really holds a Vocab Sync value with that number — a plain
     * text field that happens to be searched with a number (a year, 1970) is left alone.
     */
    public function readableSearchFilters(Event $event)
    {
        try {
            $filters = $event->getParam('filters');
            $query = $event->getParam('query');
            if (!is_array($filters) || !is_array($query) || empty($query['property']) || !is_array($query['property'])) {
                return;
            }
            $conn = $this->getServiceLocator()->get('Omeka\Connection');
            $swap = [];   // field name => [number => term name] — tied to the field, not to the number alone
            foreach ($query['property'] as $row) {
                if (!is_array($row)) { continue; }
                $type = (string) ($row['type'] ?? '');
                $texts = (array) ($row['text'] ?? []);
                if (!in_array($type, ['eq', 'neq', 'in', 'nin'], true)) { continue; }
                $prop = $row['property'] ?? null;
                if (is_array($prop)) { $prop = reset($prop); }
                if (!is_numeric($prop)) {
                    if (!is_string($prop) || strpos($prop, ':') === false) { continue; }
                    [$prefix, $local] = explode(':', $prop, 2);
                    $prop = $conn->fetchOne('SELECT p.id FROM property p JOIN vocabulary o ON o.id = p.vocabulary_id
                                               WHERE o.prefix = ? AND p.local_name = ?', [$prefix, $local]);
                    if (!$prop) { continue; }
                }
                $propLabel = (string) $conn->fetchOne('SELECT label FROM property WHERE id = ?', [(int) $prop]);
                foreach ($texts as $text) {
                    $text = trim((string) $text);
                    if ($text === '' || !ctype_digit($text) || isset($swap[$propLabel][$text])) { continue; }
                    $label = $conn->fetchOne(
                        "SELECT t.label FROM value v JOIN vocab_sync_term t ON t.id = v.value
                          WHERE v.property_id = ? AND v.type LIKE 'vocabsync:%' AND v.value = ? LIMIT 1",
                        [(int) $prop, $text]
                    );
                    if ($label !== false && $label !== null && $label !== '') { $swap[$propLabel][$text] = (string) $label; }
                }
            }
            if (!$swap) { return; }
            /* A filter reads "<field name> is exactly" (sometimes with AND/OR before it) — the number
               is replaced only in filters of fields where it really is a Vocab Sync term; a year and a
               term searched with the same number at once leave the year a number. */
            foreach ($filters as $label => $values) {
                if (!is_array($values)) { continue; }
                foreach ($swap as $propLabel => $map) {
                    if ($propLabel === '' || strpos((string) $label, $propLabel . ' ') === false) { continue; }
                    foreach ($values as $i => $value) {
                        $v = trim((string) $value);
                        if (isset($map[$v])) { $filters[$label][$i] = $map[$v]; }
                    }
                }
            }
            $event->setParam('filters', $filters);
        } catch (\Throwable $e) {
            // // If it cannot be read, the filter bar shows as before — nothing breaks.
        }
    }

    public function addVocabSyncDataTypeNames(Event $event)
    {
        $conn = $this->getServiceLocator()->get('Omeka\Connection');
        $ids = $conn->fetchFirstColumn('SELECT id FROM vocab_sync_vocab');

        $names = $event->getParam('registered_names');
        foreach ($ids as $id) {
            $names[] = 'vocabsync:' . $id;
        }
        $event->setParam('registered_names', $names);
    }
}
