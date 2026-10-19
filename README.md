# Vocab Sync

**Vocab Sync** is an Omeka S module for controlled vocabularies where **each term has a stable
id**. Rename a term once and **every item, item set and media that uses it shows the new name
at once** — no find and replace, no batch edit. It can **import an existing Custom Vocab**
(Terms mode), and everything it does is logged and can be undone.

![The terms of one vocabulary, with how many values use each](https://raw.githubusercontent.com/phumraphee/VocabSync/main/docs/images/terms.png)

- Omeka S 4.x · Custom Vocab is needed only to import from it
- Licence: GPL-3.0-or-later (see `LICENSE`)
- Issues and questions: https://github.com/phumraphee/VocabSync/issues

## Why

Custom Vocab in Terms mode stores the **text** of a term in each value (`value.value = "Cat"`).
When "Cat" is renamed "Domestic cat" in the list, the values already saved still say "Cat", and
there is no way to know which old words were the same term — only to guess by position.

Vocab Sync stores the **term's id** instead (`value.value = "42"`) and reads the current label
every time a value is shown, exported or searched. It is the same idea as Custom Vocab's Items
mode, except that a term is a row in a small table of this module, **not an Omeka item**, so it
does not add to the item count.

## Screenshots

**The edit form** — a searchable dropdown; a word that is not in the list can be added from
there (Editor and above, after a confirmation):

![Choosing a term on the item edit form](https://raw.githubusercontent.com/phumraphee/VocabSync/main/docs/images/edit.png)

**Rename once, see it everywhere** — the activity log shows each change with before and after:

![The activity log with a renamed term](https://raw.githubusercontent.com/phumraphee/VocabSync/main/docs/images/activity.png)

All screenshots are from a demo site with made-up data.

## Install and first use

1. Unzip into `modules/VocabSync` (the folder name must stay exactly that) and install it in
   *Admin → Modules*.
2. *Admin → Vocab Sync* → make a vocabulary and add a few terms (or import a Custom Vocab).
3. In a resource template, set a property's data type to your vocabulary (listed under *Vocab Sync*).
4. Edit an item: choose a term from the dropdown, or type a new one and press **+ Add …**.
5. Rename the term in Vocab Sync and open the item again: it shows the new name, with nothing
   edited on the item.

## What it does

- **Manage terms** — add, rename, retire and bring back, delete (only when unused, after a
  confirmation), order alphabetically or by hand, search, filter, and see which resources use a
  term. Download the list as CSV.
- **Retire rather than delete** — a retired term stays on the values that use it but cannot be
  chosen again.
- **Words are compared exactly** — upper and lower case do not count, accents and tone marks do
  ("Cafe" and "Café" are two terms), although MySQL's default collation would treat them as equal.
- **The search filter bar** shows the term's name instead of its number.
- **Activity** — one log of everything done in the module, by day, with before and after; each
  entry can be undone.
- **Templates** — shows which templates still point at a Custom Vocab and switches them over (and
  back), keeping a snapshot.

## Import from Custom Vocab

*Import* reads Custom Vocab lists in Terms mode only, then:

1. makes a new vocabulary and a term for every word;
2. moves every value that used that Custom Vocab and whose text **exactly** matches a term to the
   new data type, keeping a snapshot of each value;
3. leaves values that match no term untouched and lists them (*Recheck*), to be matched later
   automatically or by hand;
4. switches the data type in resource templates too, when that box is ticked (the default).

The original Custom Vocab is never changed or deleted. **Revert** puts the values back exactly
from the snapshot; values edited again since the import are skipped, not overwritten.

## Removing the module

Deactivating removes nothing. Before **uninstalling**, run **Freeze before removing module**: it
writes each term's current label into its values and makes them plain text, so nothing is left
as a bare number. Uninstall refuses to run while any Vocab Sync value remains. Freeze cannot be
undone and asks you to type `FREEZE` first.

## Things to know

- Values written through the API may send the word or the id in `@value`; the module stores the id.
- Export modules that read the database directly (instead of through Omeka's representations)
  would export ids, not words. Test a small export first.
- Adding, renaming, retiring, deleting and ordering terms needs Editor, Supervisor or Global
  Administrator; other roles can still choose terms on the edit form.

## Licence

Copyright (C) 2026 Phumraphee Sae-tang

This program is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. See `LICENSE`.
