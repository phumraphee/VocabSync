<?php
/**
 * A data type that keeps the term's id in a literal value (value.value = the id),
 * not the term's text — every time it is shown it reads vocab_sync_term
 * for the current label, so it can never be stuck on an old word, however often the label changes.
 *
 * One instance of this class belongs to one vocab (through $vocabId, from the
 * data type name, e.g. "vocabsync:5"), made by VocabSyncDataTypeFactory
 * (see the service.registered_names listener in Module.php, which declares these names
 * to Omeka for the resource template page).
 *
 * The contract of this class (important — the two sides must never drift apart):
 *
 *   - in the database `value.value` is always the term's id
 *   - everything people or the API see (@value, render, toString, fulltext,
 *     and the value of an option in the form) is always the word
 *   - hydrate() is the one place that turns the word back into the id when saving
 *
 * A mistake already made once: when the form's options used ids but @value was the word,
 * the item edit form could not find a matching option and showed an empty field,
 * although the data was all still in the database.
 */

namespace VocabSync\DataType;

use Doctrine\DBAL\Connection;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Entity\Value;
use Omeka\DataType\Literal;

class VocabSyncDataType extends Literal
{
    /**
     * Roles that may add terms, or bring back retired ones, from a resource edit page.
     *
     * The words are shared by every item, so adding one is closer to a setting
     * than to data entry · Reviewer/Author/Researcher still choose terms as usual,
     * they only do not see the add button (and term-api refuses a direct call).
     *
     * 1.36.2: the same set as the Manage terms page, the delete-term confirmation and the
     * Add and assign button on the Recheck page (IndexController::canEditTerms()).
     * Other pages of the module (Import, Revert, Freeze, delete vocab, templates) stay open to every role.
     */
    const TERM_EDITOR_ROLES = ['global_admin', 'site_admin', 'editor'];

    /** @param object|null $user Omeka\Entity\User */
    public static function canEditTerms($user)
    {
        return $user
            && method_exists($user, 'getRole')
            && in_array($user->getRole(), self::TERM_EDITOR_ROLES, true);
    }

    /**
     * The SQL condition for "the same word" — used everywhere a term's label is compared.
     *
     * Never a bare `label = :x`: the column is utf8mb4_unicode_ci, which
     * **ignores accents and tone marks** — checked on MySQL 8.0.46 and MariaDB 10.11:
     * 'Cafe' = 'Café' is true, and so are Thai words that differ only by a tone mark.
     * So choosing one word could save the other (fixed in 1.36.1).
     *
     * Rule: upper and lower case do not count (as before), everything else does.
     *   The first part `a = b` can use the label's index (it picks the rows the collation calls equal);
     *   the second compares byte by byte after LOWER(), dropping those that differ only by accents.
     * Omeka's connection is always utf8mb4 (ConnectionFactory::CHARSET),
     * so COLLATE utf8mb4_bin works with the parameters.
     *
     * @param string $a a column, e.g. 't.label'
     * @param string $b a column, e.g. 'v.value', or a parameter, e.g. ':label'
     * @param string|null $bAgain if $b is a parameter, a second name for the second use
     *                  (bound to the same value) — PDO without emulated prepares refuses
     *                  the same name twice in one query (met when testing on a real database)
     */
    public static function sameLabelSql($a, $b, $bAgain = null)
    {
        return sprintf(
            '(%1$s = %2$s AND LOWER(%1$s) COLLATE utf8mb4_bin = LOWER(%3$s) COLLATE utf8mb4_bin)',
            $a,
            $b,
            $bAgain !== null ? $bAgain : $b
        );
    }

    /**
     * Find a term by its word, inside one vocab — the one place the whole module uses.
     *
     * Exact byte match first, then one that differs only in case (in case both "Public"
     * and "PUBLIC" were imported) · on a tie the lowest id wins, so the result is stable.
     *
     * @return array|false ['id', 'label', 'is_active'] or false
     */
    public static function findTermByLabel($connection, $vocabId, $label)
    {
        return $connection->fetchAssociative(
            'SELECT id, label, is_active FROM vocab_sync_term
             WHERE vocab_id = :vid AND ' . self::sameLabelSql('label', ':l1', ':l1b') . '
             ORDER BY CASE WHEN label COLLATE utf8mb4_bin = :l2 THEN 0 ELSE 1 END, id ASC
             LIMIT 1',
            ['vid' => (int) $vocabId, 'l1' => (string) $label, 'l1b' => (string) $label, 'l2' => (string) $label]
        );
    }

    protected $connection;
    protected $vocabId;

    public function __construct(Connection $connection, int $vocabId)
    {
        $this->connection = $connection;
        $this->vocabId = $vocabId;
    }

    public function getName()
    {
        return 'vocabsync:' . $this->vocabId;
    }

    public function getLabel()
    {
        $label = $this->connection->fetchOne(
            'SELECT label FROM vocab_sync_vocab WHERE id = :id',
            ['id' => $this->vocabId]
        );
        return $label ?: sprintf('Vocab Sync #%d', $this->vocabId);
    }

    public function getOptgroupLabel()
    {
        return 'Vocab Sync'; // @translate
    }

    /**
     * Load the script that makes the dropdown searchable by typing.
     *
     * Omeka calls this once per page that has a form, so it is the right place
     * for assets · Chosen itself already comes with Omeka admin; this script
     * only connects it to our selects and to fields made later.
     */
    public function prepareForm(PhpRenderer $view)
    {
        $view->headScript()->appendFile(
            $view->assetUrl('js/vocab-sync-datatype.js', 'VocabSync'),
            'text/javascript',
            ['defer' => 'defer']
        );
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/vocab-sync-datatype.css', 'VocabSync')
        );
    }

    public function form(PhpRenderer $view)
    {
        // // The order in the dropdown, as set for each vocab:
        // // 'label' = alphabetical (default) · 'position' = the order terms were added
        $order = null;
        try {
            $order = $this->connection->fetchOne(
                'SELECT term_order FROM vocab_sync_vocab WHERE id = :id',
                ['id' => $this->vocabId]
            );
        } catch (\Throwable $e) {
            // // this column not upgraded yet; use the default
        }
        // // id = the order terms were really added (cannot change)
        // // position = an order a person set · kept apart so arranging the list
        // // never destroys which term came first
        $orderMap = [
            'label' => 'label ASC',
            'label_desc' => 'label DESC',
            'added' => 'id ASC',
            'added_desc' => 'id DESC',
            'custom' => 'position ASC, id ASC',
        ];
        $byUsage = ($order === 'used' || $order === 'used_asc');
        $orderBy = isset($orderMap[$order]) ? $orderMap[$order] : $orderMap['label'];

        $terms = $this->connection->fetchAllAssociative(
            "SELECT id, label FROM vocab_sync_term
             WHERE vocab_id = :vid AND is_active = 1
             ORDER BY " . ($byUsage ? 'label ASC' : $orderBy),
            ['vid' => $this->vocabId]
        );

        // // Retired terms must be in the select too, otherwise an item that uses one
        // // opens with an empty select → sends an empty @value → isValid() fails →
        // // Omeka's ValueHydrator silently skips that value = the value is lost on Save
        // // (checked in the Omeka 4.2.1 source: Api/Adapter/ValueHydrator.php)
        // // · the script removes retired terms from every field that does not have one selected,
        // //   so a retired term cannot be chosen anew, which is what retiring means
        $retired = $this->connection->fetchAllAssociative(
            'SELECT id, label FROM vocab_sync_term
             WHERE vocab_id = :vid AND is_active = 0
             ORDER BY label ASC',
            ['vid' => $this->vocabId]
        );

        if ($byUsage) {
            // // Usage is counted with one grouped query, not a subquery per term
            // // (380 terms = 380 queries, too slow for the page opened most often)
            $counts = [];
            try {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT value AS term_id, COUNT(*) AS n FROM value
                     WHERE type = :type GROUP BY value',
                    ['type' => 'vocabsync:' . $this->vocabId]
                );
                foreach ($rows as $row) {
                    $counts[(int) $row['term_id']] = (int) $row['n'];
                }
            } catch (\Throwable $e) {
                $counts = [];
            }

            $desc = ($order === 'used');
            // // The alphabetical order already comes from the query above, so it breaks
            // // ties, and the order is stable instead of shuffling between page loads
            usort($terms, function ($a, $b) use ($counts, $desc) {
                $na = isset($counts[(int) $a['id']]) ? $counts[(int) $a['id']] : 0;
                $nb = isset($counts[(int) $b['id']]) ? $counts[(int) $b['id']] : 0;
                if ($na === $nb) {
                    return strcasecmp($a['label'], $b['label']);
                }
                return $desc ? ($nb <=> $na) : ($na <=> $nb);
            });
        }

        // // An option's value is the word, not the id, to match the @value
        // // getJsonLd() returns — Omeka fills the form from @value; if the two sides
        // // used different units the form could not select the option and showed it empty
        // // · data-term-id lets the script know the id of the selected word (see the hidden field below)
        $esc = function ($text) {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $options = '<option value=""></option>';
        foreach ($terms as $term) {
            $options .= sprintf(
                '<option value="%s" data-term-id="%d">%s</option>',
                $esc($term['label']), (int) $term['id'], $esc($term['label'])
            );
        }
        foreach ($retired as $term) {
            $options .= sprintf(
                '<option value="%s" data-term-id="%d" data-retired="1">%s (retired)</option>',
                $esc($term['label']), (int) $term['id'], $esc($term['label'])
            );
        }

        // // Link to the terms page, and where the add button sends its request
        $manageUrl = '';
        $apiUrl = '';
        try {
            $manageUrl = $view->url('admin/vocab-sync', ['action' => 'edit-vocab', 'id' => $this->vocabId]);
            $apiUrl = $view->url('admin/vocab-sync', ['action' => 'term-api', 'id' => $this->vocabId]);
        } catch (\Throwable $e) {
            // // no route (should not happen) — hide the link; the dropdown works as usual
        }
        $canAdd = false;
        try {
            $canAdd = self::canEditTerms($view->identity());
        } catch (\Throwable $e) {
            $canAdd = false;
        }

        // // Hidden field data-value-key="vocabsync:term_id"
        // // Omeka fills it from the value's JSON (getJsonLd() returns this key) and sends it back
        // // on save with @value (checked both ways in resource-form.js 4.2.1)
        // // · used as a fallback when the term was renamed while this page stayed open
        // //   see resolveTermId()
        $html = sprintf(
            '<select class="to-require vocabsync-select" data-value-key="@value"'
            . ' data-placeholder="%s" data-vocab-id="%d" data-vocab-label="%s"'
            . ' data-api-url="%s" data-can-add="%s">%s</select>',
            $esc('Select or type to search'),
            $this->vocabId,
            $esc($this->getLabel()),
            $esc($apiUrl),
            $canAdd ? '1' : '0',
            $options
        );
        $html .= '<input type="hidden" class="vocabsync-term-id" data-value-key="vocabsync:term_id">';
        if ($manageUrl !== '') {
            $html .= sprintf(
                '<div class="vocabsync-tools"><a class="vocabsync-manage" href="%s" target="_blank"'
                . ' rel="noopener" title="%s">Manage terms</a></div>',
                $esc($manageUrl),
                $esc('Opens in a new tab, so this form is kept')
            );
        }
        return $html;
    }

    public function isValid(array $valueObject)
    {
        return $this->resolveTermId(
            $valueObject['@value'] ?? '',
            $valueObject['vocabsync:term_id'] ?? null
        ) !== null;
    }

    /**
     * Accepts the word (from the form, and from an API that sends the word) or the id
     * (for older scripts or imports that send the id), and always returns the id.
     *
     * Order:
     *  1. Always try it as a word first, because a term whose name is a number ("1961")
     *     really exists; checking the number first would pick the term with id 1961 instead
     *  2. The id from the hidden field (vocabsync:term_id) — used only when the word sent
     *     really was that term's name at some point (vocab_sync_term_log)
     *     That is an edit page left open while somebody renamed the term in another tab ·
     *     without proof in the log the id is not used, because it may be an API that fetched the JSON,
     *     changed @value and sent it back with the stale id
     *  3. @value is the id of a term in this vocab
     */
    protected function resolveTermId($raw, $hintId = null)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $byLabel = self::findTermByLabel($this->connection, $this->vocabId, $raw);
        if ($byLabel) {
            return (int) $byLabel['id'];
        }

        $hint = is_scalar($hintId) ? trim((string) $hintId) : '';
        if ($hint !== '' && ctype_digit($hint)) {
            try {
                $wasItsName = $this->connection->fetchOne(
                    'SELECT t.id FROM vocab_sync_term t
                     JOIN vocab_sync_term_log l ON l.term_id = t.id
                     WHERE t.id = :id AND t.vocab_id = :vid
                       AND (' . self::sameLabelSql('l.before_label', ':l1', ':l1b') . '
                            OR ' . self::sameLabelSql('l.after_label', ':l2', ':l2b') . ')
                     LIMIT 1',
                    ['id' => $hint, 'vid' => $this->vocabId,
                     'l1' => $raw, 'l1b' => $raw, 'l2' => $raw, 'l2b' => $raw]
                );
            } catch (\Throwable $e) {
                $wasItsName = false; // no log table (should not happen) — do not guess
            }
            if ($wasItsName !== false && $wasItsName !== null) {
                return (int) $wasItsName;
            }
        }

        if (ctype_digit($raw)) {
            $byId = $this->connection->fetchOne(
                'SELECT id FROM vocab_sync_term WHERE id = :id AND vocab_id = :vid',
                ['id' => $raw, 'vid' => $this->vocabId]
            );
            if ($byId !== false && $byId !== null) {
                return (int) $byId;
            }
        }

        return null;
    }

    /**
     * The one place that turns the word into the id before it goes into the database.
     *
     * lang/uri/valueResource are all set to null, as Literal does, because old values
     * left in those fields would otherwise come along.
     */
    public function hydrate(array $valueObject, Value $value, AbstractEntityAdapter $adapter)
    {
        $termId = $this->resolveTermId(
            $valueObject['@value'] ?? '',
            $valueObject['vocabsync:term_id'] ?? null
        );
        $value->setValue((string) $termId);
        $value->setLang(null);
        $value->setUri(null);
        $value->setValueResource(null);
    }

    /**
     * Convert existing values to this data type (Batch edit → Convert data type).
     *
     * Written here on purpose: the inherited Literal::convert() returns true without touching the value;
     * the text "Public" would be stored raw instead of an id and show as [deleted term Public],
     * and worse, a numeric text like "1961" would silently become the id 1961 of another
     * term (checked in the Omeka 4.2.1 source: DataType/Literal.php and
     * Api/Adapter/ValueHydrator.php, convert_data_types).
     *
     * Rule: look up by the word in this vocab only; a number is never read as an id ·
     * not found = return false → Omeka does not convert that value and writes a notice to the log;
     * the value stays as it was · values with a URI are not converted (as Literal does: no data lost).
     *
     * On purpose: the source value's language (@language) is dropped, because Vocab Sync keeps
     * no language per value (hydrate() clears it too) · a retired term can be matched,
     * because this moves existing data rather than choosing a new word.
     */
    public function convert(Value $valueObject, string $dataTypeTarget): bool
    {
        $sourceType = (string) $valueObject->getType();
        if ($sourceType === $this->getName()) {
            return true; // already this type
        }

        if (strpos($sourceType, 'vocabsync:') === 0) {
            // // Moving from another vocab: the old value is that vocab's id; read it as a word first
            $text = $this->connection->fetchOne(
                'SELECT label FROM vocab_sync_term WHERE id = :id',
                ['id' => $valueObject->getValue()]
            );
            if ($text === false || $text === null) {
                return false;
            }
        } else {
            if ($this->literalIsValid($valueObject->getUri())) {
                return false;
            }
            $text = $valueObject->getValue();
            if (!$this->literalIsValid($text)) {
                return false;
            }
        }

        $term = self::findTermByLabel($this->connection, $this->vocabId, trim((string) $text));
        if (!$term) {
            return false;
        }
        $termId = $term['id'];

        $valueObject->setValue((string) (int) $termId);
        $valueObject->setLang(null);
        $valueObject->setUri(null);
        $valueObject->setValueResource(null);
        return true;
    }

    public function render(PhpRenderer $view, ValueRepresentation $value)
    {
        return htmlspecialchars($this->currentLabel($value->value()), ENT_QUOTES, 'UTF-8');
    }

    public function getFulltextText(PhpRenderer $view, ValueRepresentation $value)
    {
        return $this->currentLabel($value->value());
    }

    protected function currentLabel($termId)
    {
        $label = $this->connection->fetchOne(
            'SELECT label FROM vocab_sync_term WHERE id = :id',
            ['id' => $termId]
        );
        return $label !== false ? $label : sprintf('[deleted term %s]', $termId);
    }

    /**
     * Signature checked against the Omeka 4.2.1 source (DataType/DataTypeInterface.php)
     * · ValueRepresentation::jsonSerialize() merges what this returns into the value's JSON.
     *
     * @value = the current word (the API and the form see the word, not the id)
     * vocabsync:term_id = the id — the edit form puts it in a hidden field and sends it back
     * on save (see form() and resolveTermId()) · writing through the API, @value may be the word
     * or the id; hydrate() converts it.
     */
    public function getJsonLd(ValueRepresentation $value)
    {
        return [
            '@value' => $this->currentLabel($value->value()),
            'vocabsync:term_id' => (int) $value->value(),
        ];
    }

    public function toString(ValueRepresentation $value)
    {
        return (string) $this->currentLabel($value->value());
    }
}
