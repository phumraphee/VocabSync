<?php
namespace VocabSync\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use VocabSync\DataType\VocabSyncDataType;

class IndexController extends AbstractActionController
{
    /**
     * Pages whose POST rewrites values across the whole archive (1.38.0), keyed the way
     * actionKey() writes an action name. Who may: VocabSyncDataType::WHOLE_ARCHIVE_ROLES.
     */
    const WHOLE_ARCHIVE_ACTIONS = ['freeze', 'revert', 'revertimport', 'templates'];

    /**
     * Who may POST, decided once before any action runs (1.38.0).
     *
     * The module's ACL lets every signed-in role in, because every role must see the terms and
     * choose them on the edit form. Until 1.38.0 only some actions then asked for a role, so a
     * Researcher could import, delete a vocabulary or freeze every value in the archive. Now
     * every POST needs an editor role, and the whole-archive ones need WHOLE_ARCHIVE_ROLES; an
     * action added later is covered without anybody remembering to add a check to it. The
     * checks inside each action stay as they were.
     */
    public function onDispatch(\Laminas\Mvc\MvcEvent $e)
    {
        if ($this->getRequest()->isPost()) {
            $action = self::actionKey((string) $e->getRouteMatch()->getParam('action', 'index'));
            $user = $this->currentIdentity();
            $allowed = in_array($action, self::WHOLE_ARCHIVE_ACTIONS, true)
                ? VocabSyncDataType::canChangeWholeArchive($user)
                : VocabSyncDataType::canEditTerms($user);
            if (!$allowed) {
                $response = $this->postDenied($action);
                $e->setResult($response);
                return $response;
            }
        }
        return parent::onDispatch($e);
    }

    /** An action name as Laminas finds its method: "revert-import", "revert_import", "revertImport" are one. */
    public static function actionKey($action)
    {
        return strtolower(str_replace(['.', '-', '_'], '', (string) $action));
    }

    private function postDenied($action)
    {
        $whole = in_array($action, self::WHOLE_ARCHIVE_ACTIONS, true);
        $message = $whole ? self::WHOLE_ARCHIVE_DENIED : self::TERM_EDIT_DENIED;
        if ($action === 'termapi') {
            return $this->jsonResponse(['status' => 'error', 'message' => $message], 403);
        }
        $this->messenger()->addError($message);
        $response = $this->redirect()->refresh();
        return $response;
    }

    const WHOLE_ARCHIVE_DENIED = 'Freeze, Revert, undoing an import and switching templates change '
        . 'values across the whole archive, so they need a Global Administrator account. '
        . 'Nothing was changed.';

    protected function conn()
    {
        return $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Connection');
    }

    /**
     * The user doing the work.
     *
     * The name is written to the log too, not only the id, because if the user is deleted later
     * the history would be left with a number pointing at somebody who no longer exists.
     *
     * @return array ['id' => int|null, 'name' => string|null]
     */
    protected function currentUser()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = ['id' => null, 'name' => null];
        try {
            $auth = $this->getEvent()->getApplication()->getServiceManager()
                ->get('Omeka\AuthenticationService');
            $user = $auth->getIdentity();
            if ($user) {
                $cached = [
                    'id' => method_exists($user, 'getId') ? (int) $user->getId() : null,
                    'name' => method_exists($user, 'getName') ? $user->getName() : null,
                ];
            }
        } catch (\Throwable $e) {
            // // Not knowing who still lets the event be logged, just without a name.
        }

        return $cached;
    }

    /**
     * Write one row to the central log.
     *
     * Rows of this table are never deleted and their content never edited · an undo writes
     * a new row whose undoOfEventId points back at the old one, and fills the
     * undone_by_event_id pointer on the old row for quick lookups only.
     *
     * @return int|null the id of the event just written
     */
    protected function logEvent(array $e)
    {
        $user = $this->currentUser();
        try {
            $this->conn()->executeStatement(
                'INSERT INTO vocab_sync_event
                    (created, user_id, user_name, action, vocab_id, term_id,
                     summary, detail, ref_table, ref_id, undo_of_event_id)
                 VALUES (NOW(), :uid, :uname, :action, :vid, :tid,
                         :summary, :detail, :reft, :refi, :undo)',
                [
                    'uid' => $user['id'],
                    'uname' => $user['name'],
                    'action' => $e['action'],
                    'vid' => $e['vocabId'] ?? null,
                    'tid' => $e['termId'] ?? null,
                    'summary' => $e['summary'],
                    'detail' => isset($e['detail']) ? json_encode($e['detail']) : null,
                    'reft' => $e['refTable'] ?? null,
                    'refi' => $e['refId'] ?? null,
                    'undo' => $e['undoOfEventId'] ?? null,
                ]
            );
            $eventId = (int) $this->conn()->lastInsertId();

            if (!empty($e['undoOfEventId'])) {
                $this->conn()->executeStatement(
                    'UPDATE vocab_sync_event SET undone_by_event_id = :new WHERE id = :old',
                    ['new' => $eventId, 'old' => (int) $e['undoOfEventId']]
                );
            }

            return $eventId;
        } catch (\Throwable $err) {
            // // Failing to log must not break the main work.
            return null;
        }
    }

    /** /** Find the event tied to one snapshot set, so an undo points at the right row. */
    protected function eventIdFor($refTable, $refId)
    {
        try {
            $id = $this->conn()->fetchOne(
                'SELECT id FROM vocab_sync_event
                 WHERE ref_table = :t AND ref_id = :i AND undo_of_event_id IS NULL
                 ORDER BY id ASC LIMIT 1',
                ['t' => $refTable, 'i' => $refId]
            );
            return ($id === false || $id === null) ? null : (int) $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Count the length in characters without relying on mbstring.
     *
     * Some servers have no ext/mbstring · calling mb_strlen()
     * directly is a fatal error there at once.
     */
    protected function charLength($text)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen((string) $text, 'UTF-8');
        }
        // // Count UTF-8 code points without mbstring: continuation bytes start with 10xxxxxx
        return strlen(preg_replace('/[\x80-\xBF]/', '', (string) $text));
    }

    /**
     * Make position 1..N with no repeats before swapping.
     *
     * Imported or later-added terms may have repeated positions or 0;
     * without tidying them first, a swap gives unpredictable results
     * · it only runs when they really are untidy, so it does not write on every click.
     */
    protected function normalizePositions($vocabId)
    {
        $conn = $this->conn();
        $ids = $conn->fetchFirstColumn(
            'SELECT id FROM vocab_sync_term WHERE vocab_id = :vid
             ORDER BY position ASC, id ASC',
            ['vid' => $vocabId]
        );
        if (!$ids) {
            return;
        }

        $distinct = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT position) FROM vocab_sync_term WHERE vocab_id = :vid',
            ['vid' => $vocabId]
        );
        $max = (int) $conn->fetchOne(
            'SELECT MAX(position) FROM vocab_sync_term WHERE vocab_id = :vid',
            ['vid' => $vocabId]
        );
        $min = (int) $conn->fetchOne(
            'SELECT MIN(position) FROM vocab_sync_term WHERE vocab_id = :vid',
            ['vid' => $vocabId]
        );
        if ($distinct === count($ids) && $min === 1 && $max === count($ids)) {
            return; // already tidy
        }

        $pos = 1;
        foreach ($ids as $id) {
            $conn->executeStatement(
                'UPDATE vocab_sync_term SET position = :p WHERE id = :id',
                ['p' => $pos++, 'id' => (int) $id]
            );
        }
    }

    /**
     * Move a term up or down one step in the hand-set order.
     *
     * It swaps position with the neighbour really next to it in the current order,
     * not plus or minus one, because gaps between the numbers would make that move nothing.
     *
     * @return array|null ['moved' => label, 'with' => label]
     */
    protected function moveTerm($vocabId, $termId, $direction)
    {
        $conn = $this->conn();
        $this->normalizePositions($vocabId);

        $term = $conn->fetchAssociative(
            'SELECT id, label, position FROM vocab_sync_term
             WHERE id = :id AND vocab_id = :vid',
            ['id' => $termId, 'vid' => $vocabId]
        );
        if (!$term) {
            return null;
        }

        if ($direction === 'up') {
            $neighbour = $conn->fetchAssociative(
                'SELECT id, label, position FROM vocab_sync_term
                 WHERE vocab_id = :vid AND position < :p
                 ORDER BY position DESC LIMIT 1',
                ['vid' => $vocabId, 'p' => (int) $term['position']]
            );
        } else {
            $neighbour = $conn->fetchAssociative(
                'SELECT id, label, position FROM vocab_sync_term
                 WHERE vocab_id = :vid AND position > :p
                 ORDER BY position ASC LIMIT 1',
                ['vid' => $vocabId, 'p' => (int) $term['position']]
            );
        }
        if (!$neighbour) {
            return null; // already at the edge
        }

        $conn->executeStatement(
            'UPDATE vocab_sync_term SET position = :p, modified = NOW() WHERE id = :id',
            ['p' => (int) $neighbour['position'], 'id' => (int) $term['id']]
        );
        $conn->executeStatement(
            'UPDATE vocab_sync_term SET position = :p, modified = NOW() WHERE id = :id',
            ['p' => (int) $term['position'], 'id' => (int) $neighbour['id']]
        );

        return ['moved' => $term['label'], 'with' => $neighbour['label']];
    }

    /** /** Log every edit to a term, so it can be undone. */
    /**
     * @return int|null the id of the row just written — always use it as ref_id
     *
     * Never find the id later with SELECT MAX(id): if two people save at once, one
     * person's event would point at the other's log row and the undo would restore the wrong word
     * (pointed out when another tool started writing to the same table).
     */
    protected function logTerm($termId, $vocabId, $action, $beforeLabel, $afterLabel, $beforeActive, $afterActive)
    {
        try {
            $this->conn()->executeStatement(
                'INSERT INTO vocab_sync_term_log
                    (term_id, vocab_id, action, before_label, after_label,
                     before_active, after_active, created)
                 VALUES (:tid, :vid, :act, :bl, :al, :ba, :aa, NOW())',
                [
                    'tid' => $termId,
                    'vid' => $vocabId,
                    'act' => $action,
                    'bl' => $beforeLabel,
                    'al' => $afterLabel,
                    'ba' => $beforeActive,
                    'aa' => $afterActive,
                ]
            );
            return (int) $this->conn()->lastInsertId();
        } catch (\Throwable $e) {
            // // Logging failed: the work still goes on, but there will be no undo button for it.
            return null;
        }
    }

    /**
     * Add one term, with its snapshot (term_log) and event.
     *
     * Every way of adding a term must go through here — the Manage terms page and the add button on
     * the item edit page · logic copied to several places would one day disagree.
     *
     * The duplicate check uses VocabSyncDataType::findTermByLabel(), the same that
     * resolveTermId() uses to find the id — so both sides always agree on "the same word"
     * (case does not count, accents do · see sameLabelSql()).
     *
     * Check, then INSERT, so it is wrapped in a GET_LOCK per vocab: two requests arriving
     * at once cannot add the same word twice · if the lock cannot be had (another database / waiting over
     * 5 seconds), it goes on as before rather than refuse the user.
     *
     * @param string|null $summary the summary sentence in the history (null = the standard one)
     * @return array status: 'empty' | 'exists' | 'added'
     *               + id, label, active when there is a term
     */
    protected function addTerm($vocabId, $label, $summary = null)
    {
        $conn = $this->conn();
        $label = trim((string) $label);
        if ($label === '') {
            return ['status' => 'empty'];
        }

        $lockName = 'vocabsync_term_' . (int) $vocabId;
        $locked = false;
        try {
            $locked = ((int) $conn->fetchOne('SELECT GET_LOCK(:n, 5)', ['n' => $lockName]) === 1);
        } catch (\Throwable $e) {
            $locked = false;
        }
        try {
            return $this->addTermUnlocked($vocabId, $label, $summary);
        } finally {
            if ($locked) {
                try {
                    $conn->fetchOne('SELECT RELEASE_LOCK(:n)', ['n' => $lockName]);
                } catch (\Throwable $e) {
                    // // the lock is released when the connection ends anyway
                }
            }
        }
    }

    /** /** The check-and-write part of addTerm() — only called through addTerm(). */
    protected function addTermUnlocked($vocabId, $label, $summary)
    {
        $conn = $this->conn();
        $existing = VocabSyncDataType::findTermByLabel($conn, $vocabId, $label);
        if ($existing) {
            return [
                'status' => 'exists',
                'id' => (int) $existing['id'],
                'label' => $existing['label'],
                'active' => ((int) $existing['is_active'] === 1),
            ];
        }

        $maxPos = (int) $conn->fetchOne(
            'SELECT COALESCE(MAX(position), 0) FROM vocab_sync_term WHERE vocab_id = :vid',
            ['vid' => $vocabId]
        );
        $conn->executeStatement(
            'INSERT INTO vocab_sync_term (vocab_id, label, position, is_active, created)
             VALUES (:vid, :label, :pos, 1, NOW())',
            ['vid' => $vocabId, 'label' => $label, 'pos' => $maxPos + 1]
        );
        $newTermId = (int) $conn->lastInsertId();
        $termLogId = $this->logTerm($newTermId, $vocabId, 'add', null, $label, null, 1);
        $this->logEvent([
            'action' => 'term.add',
            'vocabId' => $vocabId,
            'termId' => $newTermId,
            'summary' => $summary !== null ? $summary : sprintf('Added the term "%s".', $label),
            'detail' => ['before' => null, 'after' => $label],
            'refTable' => 'vocab_sync_term_log',
            'refId' => $termLogId,
        ]);

        return ['status' => 'added', 'id' => $newTermId, 'label' => $label, 'active' => true];
    }

    /**
     * Bring a retired term back — only if it really is still retired now.
     *
     * The condition `is_active = 0` is in the UPDATE itself, not checked first and written after,
     * so nobody can change the state in between · logged in the same form
     * as toggle_active on the Manage terms page, so it can be undone the same way.
     *
     * @param string $from the end of the summary sentence, e.g. ' from the edit form of resource #12'
     * @return array status: 'reactivated' | 'already_active' | 'gone'
     */
    protected function reactivateTerm($vocabId, $termId, $from = '')
    {
        $conn = $this->conn();
        $changed = $conn->executeStatement(
            'UPDATE vocab_sync_term SET is_active = 1, modified = NOW()
             WHERE id = :id AND vocab_id = :vid AND is_active = 0',
            ['id' => $termId, 'vid' => $vocabId]
        );
        $term = $conn->fetchAssociative(
            'SELECT id, label, is_active FROM vocab_sync_term WHERE id = :id AND vocab_id = :vid',
            ['id' => $termId, 'vid' => $vocabId]
        );
        if (!$term) {
            return ['status' => 'gone'];
        }
        $info = ['id' => (int) $term['id'], 'label' => $term['label'], 'active' => true];
        if ((int) $changed === 0) {
            return ['status' => 'already_active'] + $info;
        }

        $termLogId = $this->logTerm($termId, $vocabId, 'reactivate', null, null, 0, 1);
        $this->logEvent([
            'action' => 'term.reactivate',
            'vocabId' => $vocabId,
            'termId' => (int) $termId,
            'summary' => sprintf('Reactivated the term "%s"%s.', $term['label'], (string) $from),
            'refTable' => 'vocab_sync_term_log',
            'refId' => $termLogId,
        ]);

        return ['status' => 'reactivated'] + $info;
    }

    /**
     * Read a resource_template_property's data_type always as an array.
     *
     * Newer Omeka allows one property several data types, so the stored value may be
     * a JSON array · older versions store a single string · both must be handled,
     * and written back in the same form, otherwise other values in the same field are lost.
     *
     * @return array [list of data types, whether it was stored as JSON]
     */
    protected function parseDataType($raw)
    {
        if ($raw === null || $raw === '') {
            return [[], false];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return [$decoded, true];
        }
        return [[$raw], false];
    }

    protected function encodeDataType(array $list, $wasJson)
    {
        if (!$list) {
            return null;
        }
        if ($wasJson) {
            return json_encode(array_values($list));
        }
        return reset($list);
    }

    /**
     * Scan resource_template_property once and group by data type.
     *
     * The whole table has to be scanned, because a data type a template uses "does not need any
     * saved value at all" — a mistake made once: the status page built its list
     * only from saved values, so when every value of a customvocab had been moved
     * (0 left), templates still pointing at it were never found.
     *
     * @return array type => list of rows
     */
    protected $templateMapCache = null;

    /** /** The id of the template log row repointTemplates() just wrote (not MAX(id)). */
    protected $lastTemplateLogId = null;

    /** /** Clear the cache after writing templates, otherwise the next page reads the old ones. */
    protected function resetTemplateCache()
    {
        $this->templateMapCache = null;
    }

    protected function templateDataTypeMap()
    {
        if ($this->templateMapCache !== null) {
            return $this->templateMapCache;
        }

        $conn = $this->conn();

        // // Template/field names are extras; if the join fails, the rows must still come back
        try {
            $rows = $conn->fetchAllAssociative(
                "SELECT rtp.id, rtp.data_type, rtp.resource_template_id,
                        rt.label AS template_label,
                        CONCAT(voc.prefix, ':', prop.local_name) AS property_term
                 FROM resource_template_property rtp
                 LEFT JOIN resource_template rt ON rt.id = rtp.resource_template_id
                 LEFT JOIN property prop ON prop.id = rtp.property_id
                 LEFT JOIN vocabulary voc ON voc.id = prop.vocabulary_id
                 ORDER BY rt.label ASC, prop.local_name ASC"
            );
        } catch (\Throwable $e) {
            try {
                $rows = $conn->fetchAllAssociative(
                    'SELECT id, data_type, resource_template_id FROM resource_template_property'
                );
            } catch (\Throwable $e2) {
                $this->templateMapCache = [];
                return $this->templateMapCache;
            }
        }

        $map = [];
        foreach ($rows as $row) {
            list($list, ) = $this->parseDataType($row['data_type']);
            foreach ($list as $dt) {
                if (!preg_match('/^(customvocab|vocabsync):[0-9]+$/', (string) $dt)) {
                    continue;
                }
                $row['data_types'] = $list;
                if (!isset($map[$dt])) {
                    $map[$dt] = [];
                }
                $map[$dt][] = $row;
            }
        }

        $this->templateMapCache = $map;
        return $this->templateMapCache;
    }

    /** /** Rows that use the given data type. */
    protected function templatesUsing($dataType)
    {
        $map = $this->templateDataTypeMap();
        return isset($map[$dataType]) ? $map[$dataType] : [];
    }

    /**
     * Switch a data type in templates from one to another.
     *
     * Only the exact entry is replaced; other data types in the same field are not touched
     * · if the target is already there, the old one is only taken out, not added twice.
     *
     * @return int the number of rows changed
     */
    protected function repointTemplates($fromType, $toType)
    {
        $conn = $this->conn();
        $targets = $this->templatesUsing($fromType);
        if (!$targets) {
            return 0;
        }

        // // Keep every row's old value before touching anything, so it can be put back exactly,
        // // not just switched back (which would go wrong if the row mixes in other data types).
        $before = [];
        foreach ($targets as $row) {
            $before[(int) $row['id']] = $row['data_type'];
        }

        $changed = 0;
        $done = [];
        foreach ($targets as $row) {
            list($list, $wasJson) = $this->parseDataType($row['data_type']);
            $out = [];
            foreach ($list as $dt) {
                $out[] = ($dt === $fromType) ? $toType : $dt;
            }
            $out = array_values(array_unique($out));

            try {
                $conn->executeStatement(
                    'UPDATE resource_template_property SET data_type = :dt WHERE id = :id',
                    ['dt' => $this->encodeDataType($out, $wasJson), 'id' => $row['id']]
                );
                $done[(int) $row['id']] = $before[(int) $row['id']];
                $changed++;
            } catch (\Throwable $e) {
                // // one row failing must not stop the whole set
            }
        }

        if ($done) {
            $this->resetTemplateCache();
            try {
                $conn->executeStatement(
                    'INSERT INTO vocab_sync_template_log
                        (from_type, to_type, rows_before, row_count, reverted, created)
                     VALUES (:from, :to, :rows, :n, 0, NOW())',
                    [
                        'from' => $fromType,
                        'to' => $toType,
                        'rows' => json_encode($done),
                        'n' => count($done),
                    ]
                );
                $this->lastTemplateLogId = (int) $conn->lastInsertId();
            } catch (\Throwable $e) {
                // // if it cannot be logged the work still goes on, but it cannot be undone automatically
            }
        }

        return $changed;
    }

    /**
     * Put a template's data type change back from the snapshot that was kept.
     *
     * It writes the old value back whole, so it comes back exactly even when the row
     * mixed several data types.
     */
    protected function revertTemplateLog($logId)
    {
        $conn = $this->conn();
        $log = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_template_log WHERE id = :id',
            ['id' => $logId]
        );
        if (!$log) {
            return null;
        }

        $rows = json_decode($log['rows_before'], true);
        if (!is_array($rows)) {
            return null;
        }

        $restored = 0;
        foreach ($rows as $rtpId => $dataType) {
            try {
                $conn->executeStatement(
                    'UPDATE resource_template_property SET data_type = :dt WHERE id = :id',
                    ['dt' => $dataType, 'id' => (int) $rtpId]
                );
                $restored++;
            } catch (\Throwable $e) {
                // // skip rows that are gone
            }
        }

        $this->resetTemplateCache();

        $conn->executeStatement(
            'UPDATE vocab_sync_template_log SET reverted = 1 WHERE id = :id',
            ['id' => $logId]
        );

        return $restored;
    }

    /**
     * Is Custom Vocab still installed?
     *
     * When Custom Vocab is uninstalled its custom_vocab table is DROPped,
     * and every page that queries it (Import / Recheck / Revert) would break at once
     * · so it always checks first and hides those commands rather than let them error.
     */
    protected function customVocabAvailable()
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        try {
            $this->conn()->fetchOne('SELECT 1 FROM custom_vocab LIMIT 1');
            $available = true;
        } catch (\Throwable $e) {
            $available = false;
        }
        return $available;
    }

    /** /** Close the pages that need Custom Vocab when that module is gone. */
    protected function requireCustomVocab()
    {
        if ($this->customVocabAvailable()) {
            return null;
        }
        $this->messenger()->addError(
            'The Custom Vocab module is no longer installed, so there is nothing to '
            . 'import from or revert to. Vocab Sync keeps working on its own.'
        );
        return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
    }

    /**
     * Find the resource type of each id, to link to the right admin page
     * (item / item-set / media have different URLs).
     *
     * Doctrine's discriminator column is not used because its name could not be confirmed
     * from the Omeka source · the item / item_set / media tables are checked directly, which
     * the CustomVocab source confirms (it refers to `item_set (id)`).
     *
     * The numbers go straight into IN, not as an array parameter, because DBAL's constant
     * for array parameters changed name between versions (ArrayParameterType vs
     * Connection::PARAM_INT_ARRAY) and cannot be tested here · safe, because every value
     * has been through (int), so it is a plain number, never text from a user.
     *
     * If the query fails for any reason, an empty array comes back and the page shows plain ids
     * as before — better than the whole page breaking.
     *
     * @return array id => 'item'|'item-set'|'media'
     */
    protected function resourceTypes(array $ids)
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        if (!$clean) {
            return [];
        }
        $inList = implode(',', $clean);

        $conn = $this->conn();
        $map = [];
        $tables = ['item' => 'item', 'item_set' => 'item-set', 'media' => 'media'];

        foreach ($tables as $table => $urlSegment) {
            try {
                $found = $conn->fetchFirstColumn(
                    "SELECT id FROM `$table` WHERE id IN ($inList)"
                );
            } catch (\Throwable $e) {
                return [];
            }
            foreach ($found as $id) {
                $map[(int) $id] = $urlSegment;
            }
        }

        return $map;
    }

    /** /** Every vocab + its number of terms + the number of values really using it. */
    /** The name of the hidden field every form of this module carries (1.37.0). */
    const CSRF = 'vocabsync_csrf';

    /**
     * Whether a POST came from a form this site gave out (1.37.0).
     *
     * Every form carries a token (view: a Laminas Csrf element of the same name), so a page
     * on another site cannot make a signed-in admin's browser import, freeze or delete.
     */
    private function csrfOk(): bool
    {
        $validator = new \Laminas\Validator\Csrf(['name' => self::CSRF, 'timeout' => 3600]);
        return $validator->isValid((string) $this->params()->fromPost(self::CSRF, ''));
    }

    private function csrfRefused()
    {
        $this->messenger()->addError('This form has expired or did not come from this site. Nothing was changed; please try again.'); // @translate
        return $this->redirect()->refresh();
    }

    public function indexAction()
    {
        $conn = $this->conn();

        $sort = (string) $this->params()->fromQuery('sort', 'label');
        // // ORDER BY only from the defined list, never a string from input
        $orderMap = [
            'label' => 'v.label ASC',
            'label_desc' => 'v.label DESC',
            'terms_desc' => 'term_count DESC, v.label ASC',
            'terms_asc' => 'term_count ASC, v.label ASC',
            'newest' => 'v.id DESC',
            'oldest' => 'v.id ASC',
        ];
        // // values_desc is sorted in PHP because the counts come from another query,
        // // so it is a valid key but not in orderMap
        if ($sort !== 'values_desc' && !isset($orderMap[$sort])) {
            $sort = 'label';
        }
        $sqlOrder = $orderMap[$sort] ?? $orderMap['label'];

        $vocabs = $conn->fetchAllAssociative(
            "SELECT v.id, v.label, v.owner_name, v.created,
                    COUNT(t.id) AS term_count,
                    SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) AS active_count
             FROM vocab_sync_vocab v
             LEFT JOIN vocab_sync_term t ON t.vocab_id = v.id
             GROUP BY v.id, v.label, v.owner_name, v.created
             ORDER BY $sqlOrder"
        );

        // // Values really used per vocab — one query for all, not one per vocab
        $valueCounts = [];
        try {
            $rows = $conn->fetchAllAssociative(
                "SELECT type, COUNT(*) AS n FROM value
                 WHERE type LIKE 'vocabsync:%' GROUP BY type"
            );
            foreach ($rows as $r) {
                $valueCounts[$r['type']] = (int) $r['n'];
            }
        } catch (\Throwable $e) {
            $valueCounts = [];
        }

        // // Sorting by number of values happens in PHP because the numbers come from another query
        if ($sort === 'values_desc') {
            usort($vocabs, function ($a, $b) use ($valueCounts) {
                $na = $valueCounts['vocabsync:' . $a['id']] ?? 0;
                $nb = $valueCounts['vocabsync:' . $b['id']] ?? 0;
                if ($na === $nb) {
                    return strcasecmp($a['label'], $b['label']);
                }
                return $nb <=> $na;
            });
        }

        return new ViewModel([
            'vocabs' => $vocabs,
            'valueCounts' => $valueCounts,
            'sort' => $sort,
            'customVocabAvailable' => $this->customVocabAvailable(),
        ]);
    }

    /** /** Make a new vocab. */
    public function addVocabAction()
    {
        $conn0 = $this->conn();
        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $label = trim((string) $this->params()->fromPost('label'));
            $taken = $label === '' ? false : (bool) $conn0->fetchOne(
                'SELECT 1 FROM vocab_sync_vocab WHERE label = :label',
                ['label' => $label]
            );
            if ($label === '') {
                $this->messenger()->addError('Enter a name for the vocabulary.');
            } elseif ($this->charLength($label) > 190) {
                $this->messenger()->addError(sprintf(
                    'That name is %d characters. Vocabulary names are limited to 190.',
                    $this->charLength($label)
                ));
            } elseif ($taken) {
                $this->messenger()->addError(sprintf(
                    'A vocabulary called "%s" already exists. Choose a different name.',
                    $label
                ));
            } else {
                $user = $this->currentUser();
                $conn0->executeStatement(
                    'INSERT INTO vocab_sync_vocab (label, owner_id, owner_name, created)
                     VALUES (:label, :uid, :uname, NOW())',
                    ['label' => $label, 'uid' => $user['id'], 'uname' => $user['name']]
                );
                $newId = (int) $conn0->lastInsertId();
                $this->logEvent([
                    'action' => 'vocab.create',
                    'vocabId' => $newId,
                    'summary' => sprintf('Created the vocabulary "%s".', $label),
                ]);
                $this->messenger()->addSuccess(sprintf('Created the vocabulary "%s". Add terms to it next.', $label));
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
            }
        }
        return new ViewModel();
    }

    /**
     * One page for everything about one vocab: edit the vocab's label,
     * add new terms, edit an existing term's label (the one that, once edited,
     * reaches every resource automatically, because storage points at the id),
     * retire/reactivate terms (never really deleted while something refers to them).
     */
    public function editVocabAction()
    {
        $conn = $this->conn();
        $vocabId = (int) $this->params()->fromRoute('id');
        $vocab = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $vocabId]
        );
        if (!$vocab) {
            $this->messenger()->addError('That vocabulary no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        if ($this->getRequest()->isPost() && !$this->canEditTerms()) {
            // // Every role may view this page but change nothing · the buttons are already hidden; this
            // // stops someone POSTing directly, or a page left open from before their role was lowered
            $this->messenger()->addError(self::TERM_EDIT_DENIED);
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'edit-vocab', 'id' => $vocabId]);
        }

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $op = $this->params()->fromPost('op');

            if ($op === 'add_term') {
                // // The add-term logic lives in addTerm() only — the item edit page calls the same
                $result = $this->addTerm($vocabId, $this->params()->fromPost('term_label'));
                if ($result['status'] === 'exists') {
                    // // A word must be unique within a vocab, because the item edit form sends the word
                    // // back and the module turns it into an id; with two identical words
                    // // it would turn into the wrong one without anybody knowing
                    $this->messenger()->addError(sprintf(
                        'This vocabulary already has a term called "%s". Terms must be unique '
                        . 'because the value stored on each resource is matched by wording.',
                        trim((string) $this->params()->fromPost('term_label'))
                    ));
                } elseif ($result['status'] === 'added') {
                    $this->messenger()->addSuccess(sprintf('Added the term "%s".', $result['label']));
                }
            } elseif ($op === 'rename_term') {
                $termId = (int) $this->params()->fromPost('term_id');
                $newLabel = trim((string) $this->params()->fromPost('term_label'));
                $dup = $newLabel === '' ? false : (bool) $conn->fetchOne(
                    'SELECT 1 FROM vocab_sync_term
                     WHERE vocab_id = :vid AND ' . VocabSyncDataType::sameLabelSql('label', ':label', ':label_b') . '
                       AND id <> :id',
                    ['vid' => $vocabId, 'label' => $newLabel, 'label_b' => $newLabel, 'id' => $termId]
                );
                if ($dup) {
                    $this->messenger()->addError(sprintf(
                        'Another term in this vocabulary is already called "%s". Merge them '
                        . 'instead: retire one and reassign its values.',
                        $newLabel
                    ));
                } elseif ($newLabel !== '') {
                    $beforeLabel = $conn->fetchOne(
                        'SELECT label FROM vocab_sync_term WHERE id = :id AND vocab_id = :vid',
                        ['id' => $termId, 'vid' => $vocabId]
                    );
                    $conn->executeStatement(
                        'UPDATE vocab_sync_term SET label = :label, modified = NOW()
                         WHERE id = :id AND vocab_id = :vid',
                        ['label' => $newLabel, 'id' => $termId, 'vid' => $vocabId]
                    );
                    $termLogId = $this->logTerm(
                        $termId, $vocabId, 'rename',
                        ($beforeLabel === false) ? null : $beforeLabel,
                        $newLabel, null, null
                    );
                    $this->logEvent([
                        'action' => 'term.rename',
                        'vocabId' => $vocabId,
                        'termId' => $termId,
                        'summary' => sprintf(
                            'Renamed "%s" to "%s".',
                            ($beforeLabel === false) ? '(unknown)' : $beforeLabel,
                            $newLabel
                        ),
                        // // Keep both sides as they are, so the history page can draw the comparison
                        // // without reading another table later
                        'detail' => [
                            'before' => ($beforeLabel === false) ? null : $beforeLabel,
                            'after' => $newLabel,
                        ],
                        'refTable' => 'vocab_sync_term_log',
                        'refId' => $termLogId,
                    ]);
                    // // Not one row of the value table is touched — every resource
                    // // pointing at this id sees the new label at once when render() runs
                    $this->messenger()->addSuccess(
                        sprintf('Renamed to "%s". Every resource using this term now shows the new wording.', $newLabel)
                    );
                }
            } elseif ($op === 'move_term') {
                $termId = (int) $this->params()->fromPost('term_id');
                $direction = ($this->params()->fromPost('direction') === 'up') ? 'up' : 'down';
                $result = $this->moveTerm($vocabId, $termId, $direction);
                if ($result === null) {
                    $this->messenger()->addWarning(
                        'That term is already at the end of the list in that direction.'
                    );
                } else {
                    $this->logEvent([
                        'action' => 'term.move',
                        'vocabId' => $vocabId,
                        'termId' => $termId,
                        'summary' => sprintf(
                            'Moved "%s" %s, swapping places with "%s".',
                            $result['moved'], $direction, $result['with']
                        ),
                    ]);
                }
            } elseif ($op === 'set_term_order') {
                $allowedOrders = [
                    'label', 'label_desc', 'added', 'added_desc', 'used', 'used_asc', 'custom',
                ];
                $order = (string) $this->params()->fromPost('term_order');
                if (!in_array($order, $allowedOrders, true)) {
                    $order = 'label';
                }
                if ($order === 'custom') {
                    // // Make position 1..N as soon as this mode is chosen, otherwise
                    // // the starting order is a mix of numbers left from the import
                    $this->normalizePositions($vocabId);
                }
                $conn->executeStatement(
                    'UPDATE vocab_sync_vocab SET term_order = :o WHERE id = :id',
                    ['o' => $order, 'id' => $vocabId]
                );
                $orderNames = [
                    'label' => 'alphabetical, A to Z',
                    'label_desc' => 'alphabetical, Z to A',
                    'added' => 'oldest added first',
                    'added_desc' => 'newest added first',
                    'used' => 'most used first',
                    'used_asc' => 'least used first',
                    'custom' => 'the order you arrange by hand',
                ];
                $this->logEvent([
                    'action' => 'vocab.term_order',
                    'vocabId' => $vocabId,
                    'summary' => sprintf('Set the dropdown order to %s.', $orderNames[$order]),
                ]);
                $this->messenger()->addSuccess(sprintf(
                    'The dropdown now lists terms %s.', $orderNames[$order]
                ));
            } elseif ($op === 'set_owner') {
                // // Who added a term can be stated afterwards, but it must be logged clearly as "stated",
                // // not what the system saw at the time · terms made before this column existed
                // // have no source that says who made them; guessing would be making up history
                $current = $conn->fetchOne(
                    'SELECT owner_name FROM vocab_sync_vocab WHERE id = :id',
                    ['id' => $vocabId]
                );
                if ($current !== false && $current !== null && $current !== '') {
                    $this->messenger()->addError('This vocabulary already records who added it.');
                } else {
                    $user = $this->currentUser();
                    $conn->executeStatement(
                        'UPDATE vocab_sync_vocab SET owner_id = :uid, owner_name = :uname
                         WHERE id = :id',
                        ['uid' => $user['id'], 'uname' => $user['name'], 'id' => $vocabId]
                    );
                    $this->logEvent([
                        'action' => 'vocab.owner_set',
                        'vocabId' => $vocabId,
                        'summary' => sprintf(
                            'Recorded %s as the person who added this vocabulary. Stated after '
                            . 'the fact, not observed at the time.',
                            $user['name'] ?: 'an unnamed user'
                        ),
                    ]);
                    $this->messenger()->addSuccess(
                        'Recorded. The activity log notes that this was stated after the fact.'
                    );
                }
            } elseif ($op === 'undo_term_log') {
                $logId = (int) $this->params()->fromPost('log_id');
                $entry = $conn->fetchAssociative(
                    'SELECT * FROM vocab_sync_term_log WHERE id = :id AND vocab_id = :vid',
                    ['id' => $logId, 'vid' => $vocabId]
                );
                if (!$entry || $entry['reverted']) {
                    $this->messenger()->addError('That change is no longer on record.');
                } elseif ($entry['action'] === 'add') {
                    // // Undoing an "add" = retire, not delete, because a resource may already
                    // // point at that term; a real delete would leave those values orphaned
                    $conn->executeStatement(
                        'UPDATE vocab_sync_term SET is_active = 0, modified = NOW() WHERE id = :id',
                        ['id' => $entry['term_id']]
                    );
                    $conn->executeStatement(
                        'UPDATE vocab_sync_term_log SET reverted = 1 WHERE id = :id',
                        ['id' => $logId]
                    );
                    $this->logEvent([
                        'action' => 'undo',
                        'vocabId' => $vocabId,
                        'termId' => (int) $entry['term_id'],
                        'summary' => 'Cancelled a term addition by retiring the term.',
                        'refTable' => 'vocab_sync_term_log',
                        'refId' => $logId,
                        'undoOfEventId' => $this->eventIdFor('vocab_sync_term_log', $logId),
                    ]);
                    $this->messenger()->addSuccess(
                        'Retired that term. It was not deleted, because resources may already '
                        . 'point at it.'
                    );
                } elseif ($entry['action'] === 'delete') {
                    $clash = (bool) $conn->fetchOne(
                        'SELECT 1 FROM vocab_sync_term WHERE vocab_id = :vid AND '
                        . VocabSyncDataType::sameLabelSql('label', ':label', ':label_b'),
                        ['vid' => $vocabId, 'label' => $entry['before_label'], 'label_b' => $entry['before_label']]
                    );
                    if ($clash) {
                        $this->messenger()->addError(sprintf(
                            'Cannot bring "%s" back: a term with that wording already exists.',
                            $entry['before_label']
                        ));
                    } else {
                        $maxPos = (int) $conn->fetchOne(
                            'SELECT COALESCE(MAX(position), 0) FROM vocab_sync_term
                             WHERE vocab_id = :vid',
                            ['vid' => $vocabId]
                        );
                        $conn->executeStatement(
                            'INSERT INTO vocab_sync_term
                                (vocab_id, label, position, is_active, created)
                             VALUES (:vid, :label, :pos, 0, NOW())',
                            [
                                'vid' => $vocabId,
                                'label' => $entry['before_label'],
                                'pos' => $maxPos + 1,
                            ]
                        );
                        $recreatedId = (int) $conn->lastInsertId();
                        $conn->executeStatement(
                            'UPDATE vocab_sync_term_log SET reverted = 1 WHERE id = :id',
                            ['id' => $logId]
                        );
                        $this->logEvent([
                            'action' => 'undo',
                            'vocabId' => $vocabId,
                            'termId' => $recreatedId,
                            'summary' => sprintf(
                                'Cancelled a deletion: "%s" is back, retired, with a new ID '
                                . '(%d instead of %d).',
                                $entry['before_label'], $recreatedId, (int) $entry['term_id']
                            ),
                            'refTable' => 'vocab_sync_term_log',
                            'refId' => $logId,
                            'undoOfEventId' => $this->eventIdFor('vocab_sync_term_log', $logId),
                        ]);
                        $this->messenger()->addSuccess(sprintf(
                            'Brought "%s" back as a retired term. It has a new ID, because the '
                            . 'original one is gone for good.',
                            $entry['before_label']
                        ));
                    }
                } elseif ($entry['action'] === 'rename') {
                    $clash = (bool) $conn->fetchOne(
                        'SELECT 1 FROM vocab_sync_term
                         WHERE vocab_id = :vid AND ' . VocabSyncDataType::sameLabelSql('label', ':label', ':label_b') . '
                           AND id <> :id',
                        ['vid' => $vocabId, 'label' => $entry['before_label'],
                         'label_b' => $entry['before_label'], 'id' => $entry['term_id']]
                    );
                    if ($clash) {
                        $this->messenger()->addError(sprintf(
                            'Cannot undo: another term is now called "%s".',
                            $entry['before_label']
                        ));
                    } else {
                        $conn->executeStatement(
                            'UPDATE vocab_sync_term SET label = :label, modified = NOW() WHERE id = :id',
                            ['label' => $entry['before_label'], 'id' => $entry['term_id']]
                        );
                        $conn->executeStatement(
                            'UPDATE vocab_sync_term_log SET reverted = 1 WHERE id = :id',
                            ['id' => $logId]
                        );
                        $this->logEvent([
                            'action' => 'undo',
                            'vocabId' => $vocabId,
                            'termId' => (int) $entry['term_id'],
                            'summary' => sprintf(
                                'Cancelled a rename: back to "%s".', $entry['before_label']
                            ),
                            'refTable' => 'vocab_sync_term_log',
                            'refId' => $logId,
                            'undoOfEventId' => $this->eventIdFor('vocab_sync_term_log', $logId),
                        ]);
                        $this->messenger()->addSuccess(sprintf(
                            'Renamed back to "%s".', $entry['before_label']
                        ));
                    }
                } else {
                    $conn->executeStatement(
                        'UPDATE vocab_sync_term SET is_active = :a, modified = NOW() WHERE id = :id',
                        ['a' => (int) $entry['before_active'], 'id' => $entry['term_id']]
                    );
                    $conn->executeStatement(
                        'UPDATE vocab_sync_term_log SET reverted = 1 WHERE id = :id',
                        ['id' => $logId]
                    );
                    $this->logEvent([
                        'action' => 'undo',
                        'vocabId' => $vocabId,
                        'termId' => (int) $entry['term_id'],
                        'summary' => 'Cancelled a status change.',
                        'refTable' => 'vocab_sync_term_log',
                        'refId' => $logId,
                        'undoOfEventId' => $this->eventIdFor('vocab_sync_term_log', $logId),
                    ]);
                    $this->messenger()->addSuccess('Status restored.');
                }
            } elseif ($op === 'toggle_active') {
                $termId = (int) $this->params()->fromPost('term_id');
                $beforeActive = $conn->fetchOne(
                    'SELECT is_active FROM vocab_sync_term WHERE id = :id AND vocab_id = :vid',
                    ['id' => $termId, 'vid' => $vocabId]
                );
                $conn->executeStatement(
                    'UPDATE vocab_sync_term SET is_active = 1 - is_active, modified = NOW()
                     WHERE id = :id AND vocab_id = :vid',
                    ['id' => $termId, 'vid' => $vocabId]
                );
                if ($beforeActive !== false && $beforeActive !== null) {
                    $wasActive = ((int) $beforeActive === 1);
                    $termLogId = $this->logTerm(
                        $termId, $vocabId,
                        $wasActive ? 'retire' : 'reactivate',
                        null, null, (int) $beforeActive, 1 - (int) $beforeActive
                    );
                    $label = $conn->fetchOne(
                        'SELECT label FROM vocab_sync_term WHERE id = :id',
                        ['id' => $termId]
                    );
                    $this->logEvent([
                        'action' => $wasActive ? 'term.retire' : 'term.reactivate',
                        'vocabId' => $vocabId,
                        'termId' => $termId,
                        'summary' => sprintf(
                            '%s the term "%s".',
                            $wasActive ? 'Retired' : 'Reactivated',
                            ($label === false) ? '(unknown)' : $label
                        ),
                        'refTable' => 'vocab_sync_term_log',
                        'refId' => $termLogId,
                    ]);
                }
                $this->messenger()->addSuccess('Term status updated.');
            }

            // // Back to the same place in the list (search/sort/page being viewed)
            // // but without ?edit=, because the field has to close once the edit is done
            $keep = [];
            foreach (['q', 'sort', 'status', 'page', 'per_page'] as $k) {
                $v = $this->params()->fromPost($k, '');
                if ($v !== '') {
                    $keep[$k] = $v;
                }
            }

            return $this->redirect()->toRoute(
                'admin/vocab-sync',
                ['action' => 'edit-vocab', 'id' => $vocabId],
                ['query' => $keep]
            );
        }

        // // ---- The term list: search / filter / sort / pages ----
        // // 378 terms on one page with no way to find one is a list nobody can really use
        $q = trim((string) $this->params()->fromQuery('q', ''));
        $status = (string) $this->params()->fromQuery('status', 'all');
        // // If this vocab uses a hand-set order, open the list in that order,
        // // otherwise it shows a different order from what data entry sees, and arranging goes wrong
        $defaultSort = (isset($vocab['term_order']) && $vocab['term_order'] === 'custom')
            ? 'custom' : 'label';
        $sort = (string) $this->params()->fromQuery('sort', $defaultSort);
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $editId = (int) $this->params()->fromQuery('edit', 0);

        // // Number per page can be chosen · 0 = all (a high ceiling instead of no LIMIT,
        // // so a vocab that grows later cannot hang the page)
        $allowedPerPage = [25, 50, 100, 200, 0];
        $perPage = (int) $this->params()->fromQuery('per_page', 50);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }
        $effectivePerPage = ($perPage === 0) ? 5000 : $perPage;

        // // ORDER BY only from the defined list, never a string from input
        $orderMap = [
            'label' => 't.label ASC',
            'label_desc' => 't.label DESC',
            'usage' => 'usage_count DESC, t.label ASC',
            'usage_asc' => 'usage_count ASC, t.label ASC',
            'newest' => 't.id DESC',
            'oldest' => 't.id ASC',
            'custom' => 't.position ASC, t.id ASC',
        ];
        if (!isset($orderMap[$sort])) {
            $sort = 'label';
        }

        $where = ['t.vocab_id = :vid'];
        $params = ['vid' => $vocabId, 'type' => 'vocabsync:' . $vocabId];
        if ($q !== '') {
            $where[] = 't.label LIKE :q';
            $params['q'] = '%' . $q . '%';
        }
        if ($status === 'active') {
            $where[] = 't.is_active = 1';
        } elseif ($status === 'retired') {
            $where[] = 't.is_active = 0';
        } else {
            $status = 'all';
        }
        $whereSql = implode(' AND ', $where);

        $totalAll = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM vocab_sync_term WHERE vocab_id = :vid',
            ['vid' => $vocabId]
        );
        $totalFound = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM vocab_sync_term t WHERE $whereSql",
            array_diff_key($params, ['type' => null])
        );
        $totalPages = max(1, (int) ceil($totalFound / $effectivePerPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $effectivePerPage;

        $terms = $conn->fetchAllAssociative(
            "SELECT t.*,
                (SELECT COUNT(*) FROM value WHERE type = :type AND value = t.id) AS usage_count
             FROM vocab_sync_term t
             WHERE $whereSql
             ORDER BY {$orderMap[$sort]}
             LIMIT $effectivePerPage OFFSET $offset",
            $params
        );

        // // Duplicate words already there (from an earlier import, say) must be shown,
        // // because turning a word into an id is then ambiguous
        $duplicates = $conn->fetchAllAssociative(
            'SELECT label, COUNT(*) AS n FROM vocab_sync_term
             WHERE vocab_id = :vid
             GROUP BY label HAVING COUNT(*) > 1
             ORDER BY label ASC',
            ['vid' => $vocabId]
        );

        $termHistory = [];
        try {
            $termHistory = $conn->fetchAllAssociative(
                'SELECT l.*, t.label AS current_label
                 FROM vocab_sync_term_log l
                 LEFT JOIN vocab_sync_term t ON t.id = l.term_id
                 WHERE l.vocab_id = :vid
                 ORDER BY l.created DESC, l.id DESC
                 LIMIT 20',
                ['vid' => $vocabId]
            );
        } catch (\Throwable $e) {
            $termHistory = [];
        }

        return new ViewModel([
            'vocab' => $vocab,
            'terms' => $terms,
            'termHistory' => $termHistory,
            'duplicates' => $duplicates,
            'customVocabAvailable' => $this->customVocabAvailable(),
            'q' => $q,
            'status' => $status,
            'sort' => $sort,
            'page' => $page,
            'perPage' => $perPage,
            'allowedPerPage' => $allowedPerPage,
            'totalPages' => $totalPages,
            'totalFound' => $totalFound,
            'totalAll' => $totalAll,
            'editId' => $editId,
            'canEdit' => $this->canEditTerms(),
        ]);
    }

    /**
     * Delete one term — always through a confirmation page.
     *
     * It used to be a button in the row that deleted at once, next to other buttons in a long list,
     * very easy to hit by mistake on a phone · a delete does not give the old id back, so there has to be
     * a moment to see which word is about to go.
     */
    public function deleteTermAction()
    {
        $conn = $this->conn();
        $termId = (int) $this->params()->fromRoute('id');

        $term = $conn->fetchAssociative(
            'SELECT t.*, v.label AS vocab_label
             FROM vocab_sync_term t
             JOIN vocab_sync_vocab v ON v.id = t.vocab_id
             WHERE t.id = :id',
            ['id' => $termId]
        );
        if (!$term) {
            $this->messenger()->addError('That term no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        $vocabId = (int) $term['vocab_id'];

        if (!$this->canEditTerms()) {
            $this->messenger()->addError(self::TERM_EDIT_DENIED);
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'edit-vocab', 'id' => $vocabId]);
        }

        // // The state of the list the user was on, to go back to exactly that place
        $listState = [];
        foreach (['q', 'sort', 'status', 'page', 'per_page'] as $k) {
            $v = $this->params()->fromQuery($k, $this->params()->fromPost($k, ''));
            if ($v !== '') {
                $listState[$k] = $v;
            }
        }
        // // url() returns the plugin, not an address; fromRoute() must be called (before 1.36.4 this page gave a 500)
        $backUrl = $this->url()->fromRoute(
            'admin/vocab-sync',
            ['action' => 'edit-vocab', 'id' => $vocabId],
            ['query' => $listState]
        );

        // // Checked again every time, when showing the page and on confirm; what is sent is not trusted
        $inUse = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM value WHERE type = :type AND value = :tid',
            ['type' => 'vocabsync:' . $vocabId, 'tid' => $termId]
        );
        $blocked = null;
        if ((int) $term['is_active'] === 1) {
            $blocked = 'active';
        } elseif ($inUse > 0) {
            $blocked = 'in_use';
        }

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            if ($blocked === 'active') {
                $this->messenger()->addError(
                    'Only a retired term can be deleted. Retire it first, so anyone using it '
                    . 'has a chance to notice before it goes.'
                );
                return $this->redirect()->toUrl($backUrl);
            }
            if ($blocked === 'in_use') {
                $this->messenger()->addError(sprintf(
                    'Cannot delete "%s": %d values now use it. Deleting it would leave them '
                    . 'with nothing to display.',
                    $term['label'], $inUse
                ));
                return $this->redirect()->toUrl($backUrl);
            }

            // // Keep the word in the log before deleting, so a cancel can make it again
            $termLogId = $this->logTerm($termId, $vocabId, 'delete', $term['label'], null, 0, null);
            $conn->executeStatement(
                'DELETE FROM vocab_sync_term WHERE id = :id AND vocab_id = :vid',
                ['id' => $termId, 'vid' => $vocabId]
            );
            $this->logEvent([
                'action' => 'term.delete',
                'vocabId' => $vocabId,
                'termId' => $termId,
                'summary' => sprintf('Deleted the retired, unused term "%s".', $term['label']),
                'detail' => ['before' => $term['label'], 'after' => null],
                'refTable' => 'vocab_sync_term_log',
                'refId' => $termLogId,
            ]);
            $this->messenger()->addSuccess(sprintf(
                'Deleted "%s". No values were affected because none were using it.',
                $term['label']
            ));
            return $this->redirect()->toUrl($backUrl);
        }

        return new ViewModel([
            'term' => $term,
            'vocabId' => $vocabId,
            'inUse' => $inUse,
            'blocked' => $blocked,
            'listState' => $listState,
            'backUrl' => $backUrl,
        ]);
    }

    /**
     * View and copy the list of terms · download it as CSV.
     *
     * The terms are work that people spent time making; they need to be taken out to check, shared
     * or moved elsewhere, not only locked in the system.
     */
    public function exportAction()
    {
        $conn = $this->conn();
        $vocabId = (int) $this->params()->fromRoute('id');

        $vocab = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $vocabId]
        );
        if (!$vocab) {
            $this->messenger()->addError('That vocabulary no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        $orderMap = [
            'label' => 't.label ASC',
            'added' => 't.id ASC',
            'custom' => 't.position ASC, t.id ASC',
            'usage' => 'usage_count DESC, t.label ASC',
        ];
        $order = (string) $this->params()->fromQuery('order', 'label');
        if (!isset($orderMap[$order])) {
            $order = 'label';
        }

        $status = (string) $this->params()->fromQuery('status', 'active');
        $statusSql = '';
        if ($status === 'active') {
            $statusSql = ' AND t.is_active = 1';
        } elseif ($status === 'retired') {
            $statusSql = ' AND t.is_active = 0';
        } else {
            $status = 'all';
        }

        $terms = $conn->fetchAllAssociative(
            "SELECT t.id, t.label, t.is_active, t.position,
                    (SELECT COUNT(*) FROM value
                      WHERE type = :type AND value = t.id) AS usage_count
             FROM vocab_sync_term t
             WHERE t.vocab_id = :vid $statusSql
             ORDER BY {$orderMap[$order]}",
            ['vid' => $vocabId, 'type' => 'vocabsync:' . $vocabId]
        );

        if ((string) $this->params()->fromQuery('format', '') === 'csv') {
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['id', 'term', 'status', 'values_in_use', 'position']);
            foreach ($terms as $t) {
                fputcsv($handle, [
                    (int) $t['id'],
                    $t['label'],
                    $t['is_active'] ? 'active' : 'retired',
                    (int) $t['usage_count'],
                    (int) $t['position'],
                ]);
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            // // BOM so Excel reads non-Latin text correctly · without it Excel guesses
            // // the wrong encoding and the words turn into garbage
            $csv = chr(0xEF) . chr(0xBB) . chr(0xBF) . $csv;

            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $vocab['label']);
            $filename = trim($filename, '-');
            if ($filename === '') {
                $filename = 'vocabulary-' . $vocabId;
            }

            $response = $this->getResponse();
            $response->setContent($csv);
            $headers = $response->getHeaders();
            $headers->addHeaderLine('Content-Type', 'text/csv; charset=utf-8');
            $headers->addHeaderLine(
                'Content-Disposition',
                'attachment; filename="' . $filename . '.csv"'
            );
            $headers->addHeaderLine('Content-Length', strlen($csv));
            return $response;
        }

        return new ViewModel([
            'vocab' => $vocab,
            'terms' => $terms,
            'order' => $order,
            'status' => $status,
        ]);
    }

    /**
     * Delete a vocab — only allowed when no value uses it at all.
     *
     * It is there to clean up what an import that failed halfway leaves behind (the vocab made but the values
     * not yet moved), which used to stay for ever and clash with the name on the next import.
     *
     * If any value uses it, it is never deleted, because deleting the terms would leave those values
     * with an id and no word to show — Revert or Freeze first.
     */
    public function deleteVocabAction()
    {
        $conn = $this->conn();
        $vocabId = (int) $this->params()->fromRoute('id');

        $vocab = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $vocabId]
        );
        if (!$vocab) {
            $this->messenger()->addError('That vocabulary no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        $type = 'vocabsync:' . $vocabId;
        $inUse = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM value WHERE type = :type',
            ['type' => $type]
        );
        $termCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM vocab_sync_term WHERE vocab_id = :vid',
            ['vid' => $vocabId]
        );
        $templates = $this->templatesUsing($type);

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            if ($inUse > 0) {
                $this->messenger()->addError(sprintf(
                    'Cannot delete: %d values still use this vocabulary. Revert or freeze them '
                    . 'first, otherwise they would be left with nothing to display.',
                    $inUse
                ));
                return $this->redirect()->toRoute(
                    'admin/vocab-sync',
                    ['action' => 'edit-vocab', 'id' => $vocabId]
                );
            }

            $confirm = trim((string) $this->params()->fromPost('confirm_text'));
            if ($confirm !== 'DELETE') {
                $this->messenger()->addError('Nothing was deleted. Type DELETE in capitals to confirm.');
                return $this->redirect()->toRoute(
                    'admin/vocab-sync',
                    ['action' => 'delete-vocab', 'id' => $vocabId]
                );
            }

            // // Take the data type out of templates first, otherwise templates would point at something
            // // that no longer exists · repointTemplates cannot be used, there is no target,
            // // so it is written here and logged so it can be undone the same way
            if ($templates) {
                $before = [];
                foreach ($templates as $row) {
                    $before[(int) $row['id']] = $row['data_type'];
                    list($list, $wasJson) = $this->parseDataType($row['data_type']);
                    $out = array_values(array_filter($list, function ($dt) use ($type) {
                        return $dt !== $type;
                    }));
                    $conn->executeStatement(
                        'UPDATE resource_template_property SET data_type = :dt WHERE id = :id',
                        ['dt' => $this->encodeDataType($out, $wasJson), 'id' => $row['id']]
                    );
                }
                try {
                    $conn->executeStatement(
                        'INSERT INTO vocab_sync_template_log
                            (from_type, to_type, rows_before, row_count, reverted, created)
                         VALUES (:from, :to, :rows, :n, 0, NOW())',
                        [
                            'from' => $type,
                            'to' => '(removed)',
                            'rows' => json_encode($before),
                            'n' => count($before),
                        ]
                    );
                } catch (\Throwable $e) {
                    // // logging failed; the delete still goes on
                }
                $this->resetTemplateCache();
            }

            $conn->executeStatement('DELETE FROM vocab_sync_term WHERE vocab_id = :vid', ['vid' => $vocabId]);
            $conn->executeStatement('DELETE FROM vocab_sync_vocab WHERE id = :id', ['id' => $vocabId]);

            $this->logEvent([
                'action' => 'vocab.delete',
                'vocabId' => $vocabId,
                'summary' => sprintf(
                    'Deleted the vocabulary "%s" and its %d terms (no values were using it).',
                    $vocab['label'], $termCount
                ),
            ]);
            $this->messenger()->addSuccess(sprintf(
                'Deleted the vocabulary "%s" and its %d terms. No values were affected because '
                . 'none were using it.',
                $vocab['label'], $termCount
            ));
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        return new ViewModel([
            'vocab' => $vocab,
            'inUse' => $inUse,
            'termCount' => $termCount,
            'templates' => $templates,
        ]);
    }

    /**
     * Import from an existing Custom Vocab (Terms mode only) — nothing old is touched
     * until it is confirmed, and the original custom_vocab is not deleted even after the import.
     */
    public function importAction()
    {
        if ($redirect = $this->requireCustomVocab()) {
            return $redirect;
        }

        $conn = $this->conn();

        // // Only vocabs really in Terms mode (item_set_id NULL
        // // and terms not empty) — Items/URI modes have nothing to do with this problem
        $oldVocabs = $conn->fetchAllAssociative(
            'SELECT id, label, terms FROM custom_vocab
             WHERE item_set_id IS NULL AND terms IS NOT NULL'
        );

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $oldId = (int) $this->params()->fromPost('old_vocab_id');
            $old = null;
            foreach ($oldVocabs as $v) {
                if ((int) $v['id'] === $oldId) {
                    $old = $v;
                    break;
                }
            }
            if (!$old) {
                $this->messenger()->addError('That Custom Vocab was not found, or it is not in Terms mode.');
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'import']);
            }

            // // This may touch tens of thousands of values · lift PHP's time limit as far as possible
            // // (a proxy in front still has its own limit, so speed is what matters,
            // // not only a longer time limit)
            @set_time_limit(0);
            @ignore_user_abort(true);

            $termTexts = json_decode($old['terms'], true) ?: [];
            $newLabel = trim((string) $this->params()->fromPost('new_label')) ?: $old['label'];

            // // A vocab name is stored in VARCHAR(190) because of its UNIQUE index
            // // (MySQL limits key length), so it is checked first rather than left to clash
            // // and throw an exception onto a raw error page
            if ($this->charLength($newLabel) > 190) {
                $this->messenger()->addError(sprintf(
                    'That name is %d characters. Vocabulary names are limited to 190. '
                    . 'Nothing was imported — shorten the name and try again.',
                    $this->charLength($newLabel)
                ));
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'import']);
            }

            // // A vocab name must be unique (the table has a UNIQUE key) · always checked here first,
            // // rather than left to clash and throw an exception onto a raw error page
            //
            // // A case really met: an earlier import timed out after making the vocab,
            // // so pressing again with the same name clashed with what was left
            $existing = $conn->fetchAssociative(
                'SELECT id, label FROM vocab_sync_vocab WHERE label = :label',
                ['label' => $newLabel]
            );
            if ($existing) {
                $inUse = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM value WHERE type = :type',
                    ['type' => 'vocabsync:' . (int) $existing['id']]
                );
                $termCount = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM vocab_sync_term WHERE vocab_id = :vid',
                    ['vid' => (int) $existing['id']]
                );

                $this->messenger()->addError(sprintf(
                    'A vocabulary called "%s" already exists (%d terms, %d values in use). '
                    . 'Nothing was imported. Either give the new one a different name, or '
                    . 'open the existing one to check it first.',
                    $newLabel, $termCount, $inUse
                ));

                if ($inUse === 0) {
                    $this->messenger()->addWarning(
                        'That vocabulary holds no values at all, which is what a timed-out or '
                        . 'abandoned import leaves behind. You can delete it from its own page '
                        . 'and then import again.'
                    );
                }

                return $this->redirect()->toRoute(
                    'admin/vocab-sync',
                    ['action' => 'edit-vocab', 'id' => (int) $existing['id']]
                );
            }

            $importUser = $this->currentUser();
            $conn->executeStatement(
                'INSERT INTO vocab_sync_vocab (label, owner_id, owner_name, created)
                 VALUES (:label, :uid, :uname, NOW())',
                ['label' => $newLabel, 'uid' => $importUser['id'], 'uname' => $importUser['name']]
            );
            $newVocabId = (int) $conn->lastInsertId();

            // // Custom Vocab allows the same word twice in a list, but ours must not
            // // (the form sends the word back and we turn it into an id), so duplicates are folded
            $labelToId = [];
            $skippedDuplicates = 0;
            $pos = 1;
            try {
                foreach ($termTexts as $text) {
                    $text = trim((string) $text);
                    if ($text === '') {
                        continue;
                    }
                    if (isset($labelToId[$text])) {
                        $skippedDuplicates++;
                        continue;
                    }
                    $conn->executeStatement(
                        'INSERT INTO vocab_sync_term (vocab_id, label, position, is_active, created)
                         VALUES (:vid, :label, :pos, 1, NOW())',
                        ['vid' => $newVocabId, 'label' => $text, 'pos' => $pos++]
                    );
                    $labelToId[$text] = (int) $conn->lastInsertId();
                }
            } catch (\Throwable $e) {
                // // Failing while making terms = no resource value has been touched yet;
                // // clean away the vocab just made and report something readable,
                // // instead of leaving remains to clash with the name on the next import
                try {
                    $conn->executeStatement(
                        'DELETE FROM vocab_sync_term WHERE vocab_id = :vid',
                        ['vid' => $newVocabId]
                    );
                    $conn->executeStatement(
                        'DELETE FROM vocab_sync_vocab WHERE id = :id',
                        ['id' => $newVocabId]
                    );
                } catch (\Throwable $cleanupError) {
                    // // if it cannot be cleaned up, the original cause must still be shown
                }

                $this->messenger()->addError(sprintf(
                    'The import stopped while copying terms, so no resource values were touched '
                    . 'and the half-made vocabulary was removed. The database reported: %s',
                    $e->getMessage()
                ));
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'import']);
            }
            if ($skippedDuplicates > 0) {
                $this->messenger()->addWarning(sprintf(
                    'The Custom Vocab listed %d duplicate %s; each was kept only once.',
                    $skippedDuplicates,
                    ($skippedDuplicates === 1) ? 'term' : 'terms'
                ));
            }

            $oldType = 'customvocab:' . $oldId;
            $newType = 'vocabsync:' . $newVocabId;

            $conn->executeStatement(
                'INSERT INTO vocab_sync_import_log
                    (old_vocab_id, new_vocab_id, after_type, note, created)
                 VALUES (:oldId, :newId, :after, :note, NOW())',
                [
                    'oldId' => $oldId,
                    'newId' => $newVocabId,
                    'after' => 'vocabsync:' . $newVocabId,
                    'note' => 'Import',
                ]
            );
            $importLogId = (int) $conn->lastInsertId();

            // // Move the values with one set of statements, not one row at a time
            //
            // // It used to loop INSERT+UPDATE per value = ~33,000 statements for 16,498 values,
            // // which took longer than the proxy's timeout (a real 504 was seen)
            // // Now three statements: keep the snapshot · move the values · find what is left
            //
            // // The trade-off to know: words are now matched by MySQL, not PHP
            // // · before 1.36.1 it used t.label = v.value with the collation, which ignores
            // //   accents and tone marks — a value could be tied to a term that differs only by a tone mark
            // //   (checked on MySQL 8.0) · now sameLabelSql(): only case is ignored
            // // · if a vocab has words that differ only in case ("Public" and "PUBLIC")
            // //   one value matches two terms and MySQL picks one (a known limit)
            $matched = 0;
            $importFailed = null;

            try {
                $conn->executeStatement(
                    'INSERT INTO vocab_sync_import_value_log
                        (import_log_id, value_id, old_type, old_value)
                     SELECT :logId, v.id, v.type, v.value
                     FROM value v
                     JOIN vocab_sync_term t ON t.vocab_id = :vid
                      AND ' . VocabSyncDataType::sameLabelSql('t.label', 'v.value') . '
                     WHERE v.type = :oldType',
                    ['logId' => $importLogId, 'vid' => $newVocabId, 'oldType' => $oldType]
                );

                $matched = (int) $conn->executeStatement(
                    'UPDATE value v
                     JOIN vocab_sync_term t ON t.vocab_id = :vid
                      AND ' . VocabSyncDataType::sameLabelSql('t.label', 'v.value') . '
                     SET v.type = :newType, v.value = t.id
                     WHERE v.type = :oldType',
                    ['vid' => $newVocabId, 'newType' => $newType, 'oldType' => $oldType]
                );
            } catch (\Throwable $e) {
                $importFailed = $e->getMessage();
            }

            // // What is left are values matching no term; they keep their old type
            $orphanValues = [];
            if ($importFailed === null) {
                $orphanRows = $conn->fetchAllAssociative(
                    'SELECT id, value, resource_id FROM value WHERE type = :type LIMIT 500',
                    ['type' => $oldType]
                );
                foreach ($orphanRows as $row) {
                    $orphanValues[] = [
                        'resource_id' => $row['resource_id'],
                        'text' => $row['value'],
                    ];
                }
            }

            if ($importFailed !== null) {
                $this->messenger()->addError(sprintf(
                    'The import could not be completed: %s. Nothing was left half-done — '
                    . 'values either moved or stayed as they were. Check the Status page.',
                    $importFailed
                ));
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'templates']);
            }

            // // Change the data type in templates too, when ticked (ticked by default)
            // // — not doing it here means items made after the import still use the dropdown
            // // of the old vocab, the step most easily forgotten
            $repointed = 0;
            if ($this->params()->fromPost('repoint_templates')) {
                $repointed = $this->repointTemplates($oldType, $newType);
            }

            $this->logEvent([
                'action' => 'vocab.import',
                'vocabId' => $newVocabId,
                'summary' => sprintf(
                    'Imported "%s" from Custom Vocab id %d: %d terms created, %d values moved.',
                    $newLabel, $oldId, count($labelToId), $matched
                ),
                'detail' => ['oldType' => $oldType, 'newType' => $newType, 'moved' => $matched],
                'refTable' => 'vocab_sync_import_log',
                'refId' => $importLogId,
            ]);

            $msg = sprintf(
                'Imported "%s": created %d terms and moved %d matching values.',
                $newLabel, count($labelToId), $matched
            );
            if ($orphanValues) {
                $msg .= sprintf(
                    ' %d values matched no term and were left as they were.',
                    count($orphanValues)
                );
            }
            $this->messenger()->addSuccess($msg);

            if ($repointed > 0) {
                $this->messenger()->addSuccess(sprintf(
                    'Switched %d resource template %s from "%s" to "%s". New resources will '
                    . 'now use the new dropdown.',
                    $repointed,
                    ($repointed === 1) ? 'property' : 'properties',
                    $oldType,
                    $newType
                ));
            } else {
                $this->messenger()->addWarning(sprintf(
                    'No resource template was changed. Until a template points at "%s", new '
                    . 'resources will keep using the old dropdown. Check this on the Resource '
                    . 'templates page in Vocab Sync.',
                    $newType
                ));
            }
            $this->messenger()->addWarning(sprintf(
                'The original Custom Vocab (id %d) itself is unchanged and was not deleted.',
                $oldId
            ));

            $orphanIds = [];
            foreach ($orphanValues as $o) {
                $orphanIds[] = $o['resource_id'];
            }

            return new ViewModel([
                'orphanValues' => $orphanValues,
                'newVocabId' => $newVocabId,
                'importLogId' => $importLogId,
                'oldId' => $oldId,
                'resourceTypes' => $this->resourceTypes($orphanIds),
            ]);
        }

        // // Which ones were imported — counting only those whose target vocab "still exists";
        // // if the target was deleted, it must be importable again, not hidden for ever
        $importedInto = [];
        try {
            $rows = $conn->fetchAllAssociative(
                'SELECT DISTINCT l.old_vocab_id, l.new_vocab_id, v.label AS new_label
                 FROM vocab_sync_import_log l
                 JOIN vocab_sync_vocab v ON v.id = l.new_vocab_id
                 ORDER BY v.label ASC'
            );
            foreach ($rows as $r) {
                $importedInto[(int) $r['old_vocab_id']][] = [
                    'id' => (int) $r['new_vocab_id'],
                    'label' => $r['new_label'],
                ];
            }
        } catch (\Throwable $e) {
            $importedInto = [];
        }

        return new ViewModel([
            'oldVocabs' => $oldVocabs,
            'importedInto' => $importedInto,
            'showAll' => (bool) $this->params()->fromQuery('show_all'),
        ]);
    }

    /**
     * One Revert button per vocab — first check whether an import log
     * belongs to this vocab, then choose a different way:
     *
     * - with a log (imports from 1.1.0 on) → exact: the old values come back as they
     *   were recorded at import, row by row
     * - no log (imports before 1.1.0, or a vocab made by hand rather than
     *   imported) → "best effort":
     *   overwrite with the term's current label (not the text at import time,
     *   because there is no snapshot to go by) — if the label was edited after the import,
     *   the text that comes back is the edited one, not the original text
     *   from before the import; the user must be told clearly how this differs from having a log
     */
    public function revertAction()
    {
        if ($redirect = $this->requireCustomVocab()) {
            return $redirect;
        }

        $conn = $this->conn();
        $vocabId = (int) $this->params()->fromRoute('id');
        $vocab = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $vocabId]
        );
        if (!$vocab) {
            $this->messenger()->addError('That vocabulary no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        $logs = $conn->fetchAllAssociative(
            'SELECT l.id, l.old_vocab_id, l.created, COUNT(vl.id) AS logged_count
             FROM vocab_sync_import_log l
             LEFT JOIN vocab_sync_import_value_log vl ON vl.import_log_id = l.id
             WHERE l.new_vocab_id = :vid
             GROUP BY l.id, l.old_vocab_id, l.created',
            ['vid' => $vocabId]
        );

        if ($logs) {
            // // Exact, with a log — just show the link to the existing revert-import page
            // // (one log at a time, because the same vocab may have been imported into several times)
            return new ViewModel(['vocab' => $vocab, 'hasLog' => true, 'logs' => $logs]);
        }

        // // No log — best-effort mode has to choose which Custom Vocab
        // // to go back to (because no log says)
        $oldVocabs = $conn->fetchAllAssociative(
            'SELECT id, label FROM custom_vocab WHERE item_set_id IS NULL AND terms IS NOT NULL'
        );

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $confirm = trim((string) $this->params()->fromPost('confirm_text'));
            $oldId = (int) $this->params()->fromPost('old_vocab_id');

            if ($confirm !== 'REVERT') {
                $this->messenger()->addError('Nothing was changed. Type REVERT in capitals to confirm.');
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'revert', 'id' => $vocabId]);
            }
            if (!$oldId) {
                $this->messenger()->addError('Choose which Custom Vocab to write these values back to.');
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'revert', 'id' => $vocabId]);
            }

            @set_time_limit(0);

            $newType = 'vocabsync:' . $vocabId;
            $oldType = 'customvocab:' . $oldId;

            // // Uses the term's current label, because there is no snapshot from the import
            // // to go by — the key difference from the mode with a log; the user must be told plainly
            //
            // // It used to be one UPDATE...JOIN, faster, but leaving no
            // // trace of what each value had been, so one wrong click could not be undone
            // // at all · now a snapshot is kept row by row, exactly as for import
            $conn->executeStatement(
                'INSERT INTO vocab_sync_import_log
                    (old_vocab_id, new_vocab_id, after_type, note, created)
                 VALUES (:o, :n, :after, :note, NOW())',
                [
                    'o' => $oldId,
                    'n' => $vocabId,
                    'after' => $oldType,
                    'note' => 'Revert to Custom Vocab',
                ]
            );
            $batchId = (int) $conn->lastInsertId();

            $conn->executeStatement(
                'INSERT INTO vocab_sync_import_value_log
                    (import_log_id, value_id, old_type, old_value)
                 SELECT :logId, v.id, v.type, v.value
                 FROM value v
                 JOIN vocab_sync_term t ON t.id = v.value AND t.vocab_id = :vid
                 WHERE v.type = :newType',
                ['logId' => $batchId, 'vid' => $vocabId, 'newType' => $newType]
            );

            $affected = (int) $conn->executeStatement(
                'UPDATE value v
                 JOIN vocab_sync_term t ON t.id = v.value AND t.vocab_id = :vid
                 SET v.type = :oldType, v.value = t.label
                 WHERE v.type = :newType',
                ['vid' => $vocabId, 'oldType' => $oldType, 'newType' => $newType]
            );

            $this->logEvent([
                'action' => 'value.revert',
                'vocabId' => $vocabId,
                'summary' => sprintf(
                    'Reverted %d values from %s back to %s.',
                    $affected, $newType, $oldType
                ),
                'detail' => ['mode' => 'best-effort'],
                'refTable' => 'vocab_sync_import_log',
                'refId' => $batchId,
            ]);
            $this->messenger()->addSuccess(sprintf(
                'Reverted %d values to Custom Vocab id %d, using each term\'s current label. '
                . 'With no import log for this vocabulary, the original pre-import wording '
                . 'could not be restored. This revert is itself recorded and can be undone '
                . 'from Import history.',
                $affected, $oldId
            ));

            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'edit-vocab', 'id' => $vocabId]);
        }

        return new ViewModel([
            'vocab' => $vocab,
            'hasLog' => false,
            'oldVocabs' => $oldVocabs,
        ]);
    }

    /**
     * "Recheck" orphans — an orphan is a value still customvocab:<oldId>,
     * never moved, from whichever import (even before there was a
     * log, because it checks the value table live and does not rely on the log).
     *
     * Two groups: those matched automatically (the text equals an existing term
     * exactly) and those to choose by hand (the text matches no term).
     * Both ways write the log the same way as an import, on every change, so
     * revert can undo them all alike.
     */
    public function recheckAction()
    {
        if ($redirect = $this->requireCustomVocab()) {
            return $redirect;
        }

        $conn = $this->conn();
        $vocabId = (int) $this->params()->fromRoute('id');
        $vocab = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $vocabId]
        );
        if (!$vocab) {
            $this->messenger()->addError('That vocabulary no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        $oldId = (int) $this->params()->fromPost('old_vocab_id');
        if (!$oldId) {
            $oldId = (int) $this->params()->fromQuery('old_vocab_id');
        }
        if (!$oldId) {
            // // A guess from the last import log tied to this vocab, if there is one
            $oldId = (int) $conn->fetchOne(
                'SELECT old_vocab_id FROM vocab_sync_import_log
                 WHERE new_vocab_id = :vid ORDER BY created DESC LIMIT 1',
                ['vid' => $vocabId]
            );
        }

        if (!$oldId) {
            $oldVocabs = $conn->fetchAllAssociative(
                'SELECT id, label FROM custom_vocab WHERE item_set_id IS NULL AND terms IS NOT NULL'
            );
            return new ViewModel([
                'vocab' => $vocab,
                'needOldVocab' => true,
                'oldVocabs' => $oldVocabs,
            ]);
        }

        $oldType = 'customvocab:' . $oldId;
        $newType = 'vocabsync:' . $vocabId;

        $terms = $conn->fetchAllAssociative(
            'SELECT id, label FROM vocab_sync_term WHERE vocab_id = :vid AND is_active = 1',
            ['vid' => $vocabId]
        );
        $labelToId = [];
        foreach ($terms as $t) {
            $labelToId[trim($t['label'])] = (int) $t['id'];
        }

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $op = $this->params()->fromPost('op');

            if ($op === 'recheck_all') {
                @set_time_limit(0);

                $conn->executeStatement(
                    'INSERT INTO vocab_sync_import_log
                        (old_vocab_id, new_vocab_id, after_type, note, created)
                     VALUES (:o, :n, :after, :note, NOW())',
                    ['o' => $oldId, 'n' => $vocabId, 'after' => $newType, 'note' => 'Recheck all']
                );
                $batchId = (int) $conn->lastInsertId();

                // // One set of statements like import, not row by row
                $conn->executeStatement(
                    'INSERT INTO vocab_sync_import_value_log
                        (import_log_id, value_id, old_type, old_value)
                     SELECT :logId, v.id, v.type, v.value
                     FROM value v
                     JOIN vocab_sync_term t
                       ON t.vocab_id = :vid AND t.is_active = 1
                      AND ' . VocabSyncDataType::sameLabelSql('t.label', 'v.value') . '
                     WHERE v.type = :oldType',
                    ['logId' => $batchId, 'vid' => $vocabId, 'oldType' => $oldType]
                );

                $matched = (int) $conn->executeStatement(
                    'UPDATE value v
                     JOIN vocab_sync_term t
                       ON t.vocab_id = :vid AND t.is_active = 1
                      AND ' . VocabSyncDataType::sameLabelSql('t.label', 'v.value') . '
                     SET v.type = :newType, v.value = t.id
                     WHERE v.type = :oldType',
                    ['vid' => $vocabId, 'newType' => $newType, 'oldType' => $oldType]
                );

                $this->logEvent([
                    'action' => 'value.recheck',
                    'vocabId' => $vocabId,
                    'summary' => sprintf('Matched %d unmatched values by rechecking.', $matched),
                    'refTable' => 'vocab_sync_import_log',
                    'refId' => $batchId,
                ]);
                $this->messenger()->addSuccess(sprintf(
                    'Matched %d more values. This is batch %d in Import history and can be reverted.',
                    $matched, $batchId
                ));
            } elseif ($op === 'assign_manual') {
                $valueId = (int) $this->params()->fromPost('value_id');
                $termId = (int) $this->params()->fromPost('term_id');
                $row = $conn->fetchAssociative(
                    'SELECT id, value, resource_id FROM value WHERE id = :id AND type = :type',
                    ['id' => $valueId, 'type' => $oldType]
                );
                $termOk = $termId && $conn->fetchOne(
                    'SELECT 1 FROM vocab_sync_term WHERE id = :id AND vocab_id = :vid',
                    ['id' => $termId, 'vid' => $vocabId]
                );
                if ($row && $termOk) {
                    $conn->executeStatement(
                        'INSERT INTO vocab_sync_import_log
                            (old_vocab_id, new_vocab_id, after_type, note, created)
                         VALUES (:o, :n, :after, :note, NOW())',
                        ['o' => $oldId, 'n' => $vocabId, 'after' => $newType, 'note' => 'Assign one value']
                    );
                    $batchId = (int) $conn->lastInsertId();
                    $conn->executeStatement(
                        'INSERT INTO vocab_sync_import_value_log
                            (import_log_id, value_id, old_type, old_value)
                         VALUES (:l, :v, :ot, :ov)',
                        ['l' => $batchId, 'v' => $row['id'], 'ot' => $oldType, 'ov' => $row['value']]
                    );
                    $conn->executeStatement(
                        'UPDATE value SET type = :nt, value = :tid WHERE id = :id',
                        ['nt' => $newType, 'tid' => $termId, 'id' => $row['id']]
                    );
                    $this->logEvent([
                        'action' => 'value.assign',
                        'vocabId' => $vocabId,
                        'termId' => $termId,
                        'summary' => sprintf(
                            'Assigned the value on resource #%d to an existing term.',
                            $row['resource_id']
                        ),
                        'refTable' => 'vocab_sync_import_log',
                        'refId' => $batchId,
                    ]);
                    $this->messenger()->addSuccess(sprintf(
                        'Assigned the value on resource #%d to the term you chose. '
                        . 'This can be cancelled from Import history.',
                        $row['resource_id']
                    ));
                } else {
                    $this->messenger()->addError('That value or term is no longer available. Reload the page and try again.');
                }
            } elseif ($op === 'add_and_assign' && !$this->canEditTerms()) {
                // // This button makes a new word, so it needs the same rights as the Manage terms page
                $this->messenger()->addError(self::TERM_EDIT_DENIED);
            } elseif ($op === 'add_and_assign') {
                // // Add a new term from that value's own text and tie it in one go
                // // — the page used to say "add the term first, then come back", advice
                // // that could not be followed on that page; it meant leaving for another one
                $valueId = (int) $this->params()->fromPost('value_id');
                $row = $conn->fetchAssociative(
                    'SELECT id, value, resource_id FROM value WHERE id = :id AND type = :type',
                    ['id' => $valueId, 'type' => $oldType]
                );
                $newTermLabel = trim((string) $this->params()->fromPost('new_term_label'));
                if ($newTermLabel === '' && $row) {
                    $newTermLabel = trim((string) $row['value']);
                }

                if (!$row || $newTermLabel === '') {
                    $this->messenger()->addError('That value is no longer available. Reload the page and try again.');
                } elseif (isset($labelToId[$newTermLabel])) {
                    $this->messenger()->addError(sprintf(
                        'A term named "%s" already exists. Use the dropdown to assign it instead.',
                        $newTermLabel
                    ));
                } elseif (($added = $this->addTerm(
                    $vocabId,
                    $newTermLabel,
                    sprintf('Added the term "%s" while rechecking imported values.', $newTermLabel)
                )) && $added['status'] === 'exists') {
                    // // 1.36.1: it used to add the word here itself without writing term_log / term.add
                    // // (against the contract), and checked only active words, exactly · now it goes through
                    // // addTerm(), the same as everywhere, so retired words and case-only differences are found too
                    $this->messenger()->addError(sprintf(
                        $added['active']
                            ? 'A term named "%s" already exists. Use the dropdown to assign it instead.'
                            : 'A term named "%s" already exists but is retired. Reactivate it in '
                                . 'Manage terms first, then assign it from the dropdown.',
                        $added['label']
                    ));
                } else {
                    $newTermId = (int) $added['id'];

                    $conn->executeStatement(
                        'INSERT INTO vocab_sync_import_log
                            (old_vocab_id, new_vocab_id, after_type, note, created)
                         VALUES (:o, :n, :after, :note, NOW())',
                        ['o' => $oldId, 'n' => $vocabId, 'after' => $newType, 'note' => 'Add term and assign']
                    );
                    $batchId = (int) $conn->lastInsertId();
                    $conn->executeStatement(
                        'INSERT INTO vocab_sync_import_value_log
                            (import_log_id, value_id, old_type, old_value)
                         VALUES (:l, :v, :ot, :ov)',
                        ['l' => $batchId, 'v' => $row['id'], 'ot' => $oldType, 'ov' => $row['value']]
                    );
                    $conn->executeStatement(
                        'UPDATE value SET type = :nt, value = :tid WHERE id = :id',
                        ['nt' => $newType, 'tid' => $newTermId, 'id' => $row['id']]
                    );

                    $this->logEvent([
                        'action' => 'value.add_assign',
                        'vocabId' => $vocabId,
                        'termId' => $newTermId,
                        'summary' => sprintf(
                            'Added "%s" as a new term and assigned resource #%d to it.',
                            $newTermLabel, $row['resource_id']
                        ),
                        'refTable' => 'vocab_sync_import_log',
                        'refId' => $batchId,
                    ]);
                    $this->messenger()->addSuccess(sprintf(
                        'Added "%s" as a new term and assigned resource #%d to it. '
                        . 'The assignment can be cancelled from Import history; the term stays.',
                        $newTermLabel, $row['resource_id']
                    ));
                }
            }

            return $this->redirect()->toRoute(
                'admin/vocab-sync',
                ['action' => 'recheck', 'id' => $vocabId],
                ['query' => ['old_vocab_id' => $oldId]]
            );
        }

        $orphanRows = $conn->fetchAllAssociative(
            'SELECT id, value, resource_id FROM value WHERE type = :type',
            ['type' => $oldType]
        );

        $autoMatch = [];
        $needManual = [];
        foreach ($orphanRows as $row) {
            $text = trim((string) $row['value']);
            if (isset($labelToId[$text])) {
                $autoMatch[] = $row;
            } else {
                $needManual[] = $row;
            }
        }

        $allIds = [];
        foreach (array_merge($autoMatch, $needManual) as $r) {
            $allIds[] = $r['resource_id'];
        }

        return new ViewModel([
            'vocab' => $vocab,
            'needOldVocab' => false,
            'oldId' => $oldId,
            'autoMatch' => $autoMatch,
            'needManual' => $needManual,
            'terms' => $terms,
            'resourceTypes' => $this->resourceTypes($allIds),
            'canEdit' => $this->canEditTerms(),
        ]);
    }

    /**
     * The resources using one term — reached from the "In use" number on the
     * Manage terms page · a number alone says something is there but not where,
     * so nothing could be checked or fixed from it.
     */
    public function usageAction()
    {
        $conn = $this->conn();
        $termId = (int) $this->params()->fromRoute('id');

        $term = $conn->fetchAssociative(
            'SELECT t.*, v.label AS vocab_label
             FROM vocab_sync_term t
             JOIN vocab_sync_vocab v ON v.id = t.vocab_id
             WHERE t.id = :id',
            ['id' => $termId]
        );
        if (!$term) {
            $this->messenger()->addError('That term no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        $type = 'vocabsync:' . (int) $term['vocab_id'];
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 50;

        // // Counted two ways, because one resource can use the same term in several fields · the number
        // // on the term list counts values, this page shows resources; when the two
        // // differ it must say so, otherwise clicking "4 values" finds 3 rows with no reason given
        $valueCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM value WHERE type = :type AND value = :tid',
            ['type' => $type, 'tid' => $termId]
        );
        $total = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT resource_id) FROM value WHERE type = :type AND value = :tid',
            ['type' => $type, 'tid' => $termId]
        );
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $conn->fetchAllAssociative(
            "SELECT DISTINCT resource_id
             FROM value
             WHERE type = :type AND value = :tid
             ORDER BY resource_id ASC
             LIMIT $perPage OFFSET $offset",
            ['type' => $type, 'tid' => $termId]
        );

        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['resource_id'];
        }

        // // Resource name: whether the resource table really has a title column could not be
        // // confirmed from the Omeka source · try it, and if not, show the id instead of failing
        $titles = [];
        if ($ids) {
            $inList = implode(',', $ids);
            try {
                $titleRows = $conn->fetchAllAssociative(
                    "SELECT id, title FROM resource WHERE id IN ($inList)"
                );
                foreach ($titleRows as $tr) {
                    $titles[(int) $tr['id']] = $tr['title'];
                }
            } catch (\Throwable $e) {
                $titles = [];
            }
        }

        $types = $this->resourceTypes($ids);

        // // Fetch representations so the view can use Omeka's thumbnail helper
        // // — never guess a file URL, because the folders/extensions of derivatives
        // // are internal details that may change · read one at a time and swallow each error,
        // // because a resource the user may not see, or one already deleted, must not break the page
        $apiNames = ['item' => 'items', 'item-set' => 'item_sets', 'media' => 'media'];
        $resources = [];
        foreach ($ids as $id) {
            if (!isset($types[$id])) {
                continue;
            }
            try {
                $resources[$id] = $this->api()
                    ->read($apiNames[$types[$id]], $id)
                    ->getContent();
            } catch (\Throwable $e) {
                // // skip it; that row has no picture but still has its link
            }
        }

        return new ViewModel([
            'term' => $term,
            'ids' => $ids,
            'titles' => $titles,
            'resourceTypes' => $types,
            'resources' => $resources,
            'total' => $total,
            'valueCount' => $valueCount,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * One page that answers "is the data right now?" and lets it be fixed from there.
     *
     * No longer relies on the import log — it scans the real thing in both places:
     *   1. saved values (`value.type`) — whether the data really moved
     *   2. templates (`resource_template_property.data_type`) — which dropdown
     *      editing a resource will show
     * so imports made before the log existed can be checked and fixed just the same.
     */
    public function templatesAction()
    {
        $conn = $this->conn();

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $op = (string) $this->params()->fromPost('op', 'switch');

            if ($op === 'revert_template_log') {
                $logId = (int) $this->params()->fromPost('log_id');
                $n = $this->revertTemplateLog($logId);
                if ($n === null) {
                    $this->messenger()->addError('That change is no longer on record.');
                } else {
                    $this->logEvent([
                        'action' => 'undo',
                        'summary' => sprintf(
                            'Cancelled a template change: %d %s restored.',
                            $n, ($n === 1) ? 'property' : 'properties'
                        ),
                        'refTable' => 'vocab_sync_template_log',
                        'refId' => $logId,
                        'undoOfEventId' => $this->eventIdFor('vocab_sync_template_log', $logId),
                    ]);
                    $this->messenger()->addSuccess(sprintf(
                        'Restored %d template %s to how they were before that change.',
                        $n, ($n === 1) ? 'property' : 'properties'
                    ));
                }
            } else {
                $fromType = trim((string) $this->params()->fromPost('from_type'));
                $toType = trim((string) $this->params()->fromPost('to_type'));

                // // Only the forms this module knows; no arbitrary data type may be written
                $ok = preg_match('/^(customvocab|vocabsync):[0-9]+$/', $fromType)
                    && preg_match('/^(customvocab|vocabsync):[0-9]+$/', $toType)
                    && $fromType !== $toType;

                if (!$ok) {
                    $this->messenger()->addError('Choose a different data type to switch to.');
                } else {
                    $n = $this->repointTemplates($fromType, $toType);
                    if ($n > 0) {
                        $this->logEvent([
                            'action' => 'template.switch',
                            'summary' => sprintf(
                                'Switched %d template %s from %s to %s.',
                                $n, ($n === 1) ? 'property' : 'properties', $fromType, $toType
                            ),
                            'refTable' => 'vocab_sync_template_log',
                            'refId' => $this->lastTemplateLogId,
                        ]);
                        $this->messenger()->addSuccess(sprintf(
                            'Switched %d template %s from "%s" to "%s". This is on record and '
                            . 'can be undone below.',
                            $n, ($n === 1) ? 'property' : 'properties', $fromType, $toType
                        ));
                    } else {
                        $this->messenger()->addWarning(sprintf(
                            'No template property was using "%s", so nothing changed.',
                            $fromType
                        ));
                    }
                }
            }

            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'templates']);
        }

        // // ---- 1. The values really saved ----
        $valueCounts = [];
        try {
            $rows = $conn->fetchAllAssociative(
                "SELECT type, COUNT(*) AS n FROM value
                 WHERE type LIKE 'vocabsync:%' OR type LIKE 'customvocab:%'
                 GROUP BY type ORDER BY type ASC"
            );
            foreach ($rows as $r) {
                $valueCounts[$r['type']] = (int) $r['n'];
            }
        } catch (\Throwable $e) {
            $valueCounts = [];
        }

        // // ---- 2. Where the templates point ----
        // // Templates are scanned separately, not from the list of saved values,
        // // because a template may point at a data type with no values left at all (and that
        // // is exactly the case to find: an old vocab whose values all moved but whose template stayed)
        $templateUse = $this->templateDataTypeMap();

        // // ---- 3. Every type name, from every source ----
        $ourVocabs = $conn->fetchAllAssociative(
            'SELECT id, label FROM vocab_sync_vocab ORDER BY label ASC'
        );
        foreach ($ourVocabs as $v) {
            $valueCounts['vocabsync:' . (int) $v['id']] = $valueCounts['vocabsync:' . (int) $v['id']] ?? 0;
        }
        foreach (array_keys($templateUse) as $type) {
            $valueCounts[$type] = $valueCounts[$type] ?? 0;
        }
        foreach (array_keys($valueCounts) as $type) {
            $templateUse[$type] = $templateUse[$type] ?? [];
        }

        // // Labels so a reader knows which type is which
        $labels = [];
        foreach ($ourVocabs as $v) {
            $labels['vocabsync:' . (int) $v['id']] = 'Vocab Sync — ' . $v['label'];
        }
        if ($this->customVocabAvailable()) {
            try {
                foreach ($conn->fetchAllAssociative('SELECT id, label FROM custom_vocab') as $cv) {
                    $labels['customvocab:' . (int) $cv['id']] = 'Custom Vocab — ' . $cv['label'];
                }
            } catch (\Throwable $e) {
                // // no label: show the raw type name
            }
        }

        ksort($valueCounts);

        $history = [];
        try {
            $history = $conn->fetchAllAssociative(
                'SELECT * FROM vocab_sync_template_log ORDER BY created DESC LIMIT 50'
            );
        } catch (\Throwable $e) {
            $history = [];
        }

        return new ViewModel([
            'valueCounts' => $valueCounts,
            'templateUse' => $templateUse,
            'labels' => $labels,
            'history' => $history,
            'customVocabAvailable' => $this->customVocabAvailable(),
        ]);
    }

    /**
     * The central log — everything that happened in the module, by time.
     *
     * It answers what separate tables cannot: "who did what this week".
     * Rows that were undone are not deleted; they are tied to the row that undid them, both ways.
     */
    public function activityAction()
    {
        $conn = $this->conn();

        $vocabId = (int) $this->params()->fromQuery('vocab', 0);
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 50;

        $where = [];
        $params = [];
        if ($vocabId > 0) {
            $where[] = 'e.vocab_id = :vid';
            $params['vid'] = $vocabId;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $events = [];
        $total = 0;
        try {
            $total = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM vocab_sync_event e $whereSql",
                $params
            );
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            // // Fetch before and after from the snapshot table too · events recorded later
            // // carry the details in detail, older ones do not — the real values were in
            // // vocab_sync_term_log all along, just never read for display
            $events = $conn->fetchAllAssociative(
                "SELECT e.*, v.label AS vocab_label,
                        tl.before_label AS log_before, tl.after_label AS log_after
                 FROM vocab_sync_event e
                 LEFT JOIN vocab_sync_vocab v ON v.id = e.vocab_id
                 LEFT JOIN vocab_sync_term_log tl
                        ON e.ref_table = 'vocab_sync_term_log' AND tl.id = e.ref_id
                 $whereSql
                 ORDER BY e.id DESC
                 LIMIT $perPage OFFSET $offset",
                $params
            );
        } catch (\Throwable $e) {
            $totalPages = 1;
        }

        $vocabs = $conn->fetchAllAssociative(
            'SELECT id, label FROM vocab_sync_vocab ORDER BY label ASC'
        );

        return new ViewModel([
            'events' => $events,
            'vocabs' => $vocabs,
            'vocabId' => $vocabId,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages ?? 1,
        ]);
    }

    /** /** Every import, with how many values can still be reverted. */
    public function importsAction()
    {
        $conn = $this->conn();
        $logs = $conn->fetchAllAssociative(
            "SELECT l.id, l.old_vocab_id, l.new_vocab_id, l.created,
                    l.note, l.reverted, l.after_type,
                    nv.label AS new_label,
                    COUNT(vl.id) AS logged_count
             FROM vocab_sync_import_log l
             LEFT JOIN vocab_sync_vocab nv ON nv.id = l.new_vocab_id
             LEFT JOIN vocab_sync_import_value_log vl ON vl.import_log_id = l.id
             GROUP BY l.id, l.old_vocab_id, l.new_vocab_id, l.created, l.note,
                      l.reverted, l.after_type, nv.label
             ORDER BY l.created DESC"
        );
        return new ViewModel(['logs' => $logs]);
    }

    /**
     * Put imported values back to their old text and old type (customvocab:<id>)
     * row by row from the log kept at import — rows edited further after the
     * import (their current type no longer what the import set) are skipped, so later
     * edits are not overwritten · the new vocab/terms made at import are not
     * deleted (terms may have been added or edited since, which deleting the vocab
     * would destroy) — delete them in the database yourself once sure they are not needed
     */
    public function revertImportAction()
    {
        if ($redirect = $this->requireCustomVocab()) {
            return $redirect;
        }

        $conn = $this->conn();
        $logId = (int) $this->params()->fromRoute('id');
        $log = $conn->fetchAssociative(
            'SELECT * FROM vocab_sync_import_log WHERE id = :id',
            ['id' => $logId]
        );
        if (!$log) {
            $this->messenger()->addError('That import batch no longer exists.');
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'imports']);
        }

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            // // after_type is the type that batch wrote · older batches without this
            // // column are taken to have written vocabsync:<new_vocab_id>, as they did
            $newType = ($log['after_type'] !== null && $log['after_type'] !== '')
                ? $log['after_type']
                : ('vocabsync:' . $log['new_vocab_id']);
            @set_time_limit(0);

            // // Undone with one set of statements · the condition v.type = :guard is what keeps
            // // it from overwriting values changed to something else after this batch
            $totalLogged = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM vocab_sync_import_value_log WHERE import_log_id = :id',
                ['id' => $logId]
            );

            $reverted = (int) $conn->executeStatement(
                'UPDATE value v
                 JOIN vocab_sync_import_value_log l ON l.value_id = v.id
                 SET v.type = l.old_type, v.value = l.old_value
                 WHERE l.import_log_id = :id AND v.type = :guard',
                ['id' => $logId, 'guard' => $newType]
            );
            $skipped = $totalLogged - $reverted;

            $conn->executeStatement(
                'UPDATE vocab_sync_import_log SET reverted = 1 WHERE id = :id',
                ['id' => $logId]
            );

            $this->logEvent([
                'action' => 'undo',
                'vocabId' => (int) $log['new_vocab_id'],
                'summary' => sprintf(
                    'Cancelled a value batch: %d values put back as they were%s.',
                    $reverted,
                    $skipped ? sprintf(', %d skipped as changed since', $skipped) : ''
                ),
                'refTable' => 'vocab_sync_import_log',
                'refId' => $logId,
                'undoOfEventId' => $this->eventIdFor('vocab_sync_import_log', $logId),
            ]);

            $msg = sprintf('Reverted %d values to their original state.', $reverted);
            if ($skipped) {
                $msg .= sprintf(
                    ' %d were skipped because they had since been changed to a different term.',
                    $skipped
                );
            }
            $this->messenger()->addSuccess($msg);
            $this->messenger()->addWarning(sprintf(
                'The vocabulary (id %d) and its terms were kept, since you may have added or '
                . 'renamed terms after the import. Remove them yourself once you are sure they '
                . 'are no longer needed.',
                $log['new_vocab_id']
            ));

            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'imports']);
        }

        $previewCount = $conn->fetchOne(
            'SELECT COUNT(*) FROM vocab_sync_import_value_log WHERE import_log_id = :id',
            ['id' => $logId]
        );

        return new ViewModel(['log' => $log, 'previewCount' => $previewCount]);
    }

    /**
     * Turn every vocabsync:<id> value into a plain literal for good (writes the current
     * label into value.value) — only to be done right before really removing the module,
     * because once it is removed vocab_sync_term goes with it; without this first,
     * the values left (term ids) become bare numbers for good, with no way
     * back — and this cannot be undone either (no log keeps a snapshot
     * as import does), so it must be confirmed by typing before it runs.
     */
    public function freezeAction()
    {
        $conn = $this->conn();
        $previewCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM value WHERE type REGEXP '^vocabsync:[0-9]+$'"
        );

        if ($this->getRequest()->isPost()) {
            if (!$this->csrfOk()) {
                return $this->csrfRefused();
            }
            $confirm = trim((string) $this->params()->fromPost('confirm_text'));
            if ($confirm !== 'FREEZE') {
                $this->messenger()->addError('Nothing was changed. Type FREEZE in capitals to confirm.');
                return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'freeze']);
            }

            // // One JOIN, one statement: match the type suffix (vocab id) with
            // // value (term id) and write the current label over value.value
            $affected = $conn->executeStatement(
                "UPDATE value v
                 JOIN vocab_sync_term t
                    ON v.type = CONCAT('vocabsync:', t.vocab_id)
                   AND v.value = t.id
                 SET v.value = t.label, v.type = 'literal'
                 WHERE v.type REGEXP '^vocabsync:[0-9]+$'"
            );

            $this->logEvent([
                'action' => 'freeze',
                'summary' => sprintf(
                    'Froze %d values into plain text. This cannot be undone.',
                    $affected
                ),
            ]);
            $this->messenger()->addSuccess(sprintf(
                'Froze %d values into plain text. Vocab Sync can now be uninstalled safely.',
                $affected
            ));
            return $this->redirect()->toRoute('admin/vocab-sync', ['action' => 'index']);
        }

        return new ViewModel(['previewCount' => $previewCount]);
    }

    /**
     * JSON endpoint for the add-term button on the item (and item set / media) edit page.
     *
     * POST only · op:
     *   check       read only — the word is new / already there / retired
     *   add         add the word (checked again on confirm; the check's result is not trusted)
     *   reactivate  bring a retired word back (checked to be still retired on confirm)
     *
     * The header X-Requested-With: XMLHttpRequest is required — a form on another site cannot
     * set it without passing a CORS preflight, so this stops cross-site requests (CSRF)
     * riding on our login cookie.
     *
     * Rights: only roles in VocabSyncDataType::TERM_EDITOR_ROLES
     * (the button is hidden from others too, but it is always checked again here; the page is not trusted)
     */
    public function termApiAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonResponse(['status' => 'error', 'message' => 'POST only.'], 405);
        }
        $xrw = $request->getHeaders()->get('X-Requested-With');
        if (!$xrw || strtolower(trim($xrw->getFieldValue())) !== 'xmlhttprequest') {
            return $this->jsonResponse(['status' => 'error', 'message' => 'Bad request.'], 400);
        }

        if (!$this->canEditTerms()) {
            return $this->jsonResponse([
                'status' => 'error',
                'message' => self::TERM_EDIT_DENIED,
            ], 403);
        }

        $conn = $this->conn();
        $vocabId = (int) $this->params()->fromRoute('id');
        $vocabLabel = $conn->fetchOne(
            'SELECT label FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $vocabId]
        );
        if ($vocabLabel === false || $vocabLabel === null) {
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'This vocabulary no longer exists. Reload the page.',
            ], 404);
        }

        $op = (string) $this->params()->fromPost('op');
        $label = trim((string) $this->params()->fromPost('label'));

        // // Say in the history which resource's edit page it was added from (if known) — words
        // // only; the shape of detail, which other modules may read, is not touched
        $resourceId = (int) $this->params()->fromPost('resource_id');
        $from = $resourceId > 0
            ? sprintf(' from the edit form of resource #%d', $resourceId)
            : ' from a resource edit form';

        if ($op === 'check') {
            if ($label === '') {
                return $this->jsonResponse(['status' => 'error', 'message' => 'Type a term first.'], 400);
            }
            $row = VocabSyncDataType::findTermByLabel($conn, $vocabId, $label);
            if (!$row) {
                return $this->jsonResponse(['status' => 'new', 'label' => $label, 'vocab' => $vocabLabel]);
            }
            return $this->jsonResponse([
                'status' => ((int) $row['is_active'] === 1) ? 'active' : 'retired',
                'id' => (int) $row['id'],
                'label' => $row['label'],
                'vocab' => $vocabLabel,
            ]);
        }

        if ($op === 'add') {
            if ($label === '') {
                return $this->jsonResponse(['status' => 'error', 'message' => 'Type a term first.'], 400);
            }
            if ($this->charLength($label) > 5000) {
                return $this->jsonResponse([
                    'status' => 'error',
                    'message' => 'That is too long for a term. Check that nothing was pasted by mistake.',
                ], 400);
            }
            $result = $this->addTerm(
                $vocabId,
                $label,
                sprintf('Added the term "%s"%s.', $label, $from)
            );
            if ($result['status'] === 'exists') {
                // // somebody added the same word first, or one equal but for case
                return $this->jsonResponse([
                    'status' => $result['active'] ? 'active' : 'retired',
                    'id' => $result['id'],
                    'label' => $result['label'],
                    'vocab' => $vocabLabel,
                ]);
            }
            return $this->jsonResponse($result + ['vocab' => $vocabLabel]);
        }

        if ($op === 'reactivate') {
            $termId = (int) $this->params()->fromPost('term_id');
            $result = $this->reactivateTerm($vocabId, $termId, $from);
            if ($result['status'] === 'gone') {
                return $this->jsonResponse([
                    'status' => 'error',
                    'message' => 'That term no longer exists. Reload the page.',
                ], 409);
            }
            return $this->jsonResponse($result + ['vocab' => $vocabLabel]);
        }

        return $this->jsonResponse(['status' => 'error', 'message' => 'Unknown operation.'], 400);
    }

    /** /** The logged-in user (Omeka\Entity\User) or null. */
    protected function currentIdentity()
    {
        try {
            return $this->getEvent()->getApplication()->getServiceManager()
                ->get('Omeka\AuthenticationService')->getIdentity();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 1.36.2: only Editor / Supervisor / Global admin may edit the terms
     *
     * Used for the Manage terms page (every POST), the delete-term confirmation, the Add and assign button
     * on the Recheck page and term-api — every way of adding, changing or deleting a word ·
     * other roles still see the term list and choose terms on the item edit page as usual.
     */
    protected function canEditTerms()
    {
        return VocabSyncDataType::canEditTerms($this->currentIdentity());
    }

    const TERM_EDIT_DENIED = 'Changing terms needs an Editor, Supervisor or Global Administrator '
        . 'account. You can still view the terms and choose them when editing a resource.';

    protected function jsonResponse(array $data, $code = 200)
    {
        $response = $this->getResponse();
        $response->setStatusCode($code);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->getHeaders()->addHeaderLine('Cache-Control', 'no-store');
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
