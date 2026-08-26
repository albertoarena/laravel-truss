---
name: truss-schema
description: Ground a database schema change in this application's real structure using Laravel Truss. Use when adding or altering tables, columns, indexes, or foreign keys, when a migration needs to match what is already there, or when you need to know what a migration actually changed. Structure only, never row data.
---

# Grounding a schema change with Truss

Truss reads the live database structure: tables, columns, types, indexes, and
foreign keys. It never returns row data. Read the structure before you change
it, and confirm the change after you make it.

## 1. Read the structure you are about to change

Migration files are a history, not a state. They can be edited, squashed, or
run out of order, and they do not show you what the database actually holds
now. Read the database instead:

    php artisan truss:export --format=llm --focus=orders --depth=1

`--focus` narrows to one table and its foreign-key neighbourhood, and `--depth`
controls how many hops of that neighbourhood come with it. Prefer this to a
whole-schema dump: on a large schema the full structure crowds out the task,
and the tables you need are almost always the focused table and its immediate
relations.

Use the whole schema only when the change genuinely spans it:

    php artisan truss:export --format=llm --compact

`--compact` drops defaults and non-unique indexes. Add `--connection=` when the
application has more than one database and the change is not on the default.

Tables and columns may carry business meaning, declared in config or read from
database comments. It arrives with the export, and it is often the difference
between a plausible column name and the right one.

The `llm` format is for your own reading. When the export is for something
else, `--format=dbml|json|csv|markdown|mermaid` writes it in a form that tool
understands. Send it to a file with `--output=`, and `--check` writes nothing
and exits non-zero when that file is out of date, which is how a CI job catches
a schema that has drifted from its committed export.

## 2. Check the ground before you build on it

    php artisan truss:doctor

This reports problems visible from structure alone: a table with no primary
key, a foreign key with no index behind it, a type mismatch across a foreign
key, money stored as a float. Read it before writing the migration, not after.

Two things to do with the output. If a finding sits on a table you are about to
change, say so, and offer to fix it in the same migration. Do not fold it in
unasked: one column was asked for, a migration is hard to walk back, and the
person who asked is the one who gets to widen the change. If a finding sits
elsewhere, leave it alone and say so.

## 3. Write the migration

Follow whatever conventions this application already uses. Truss tells you what
the database looks like; it does not tell you how this codebase writes
migrations, and the surrounding code is the better guide for that.

## 4. Confirm what actually happened

After running the migration:

    php artisan truss:diff

This compares the current structure against the previous snapshot and reports
added, removed, and changed tables, columns, indexes, and foreign keys.

Read it as the answer to "did that do what I meant", and pay attention when it
shows more than you expected. A column quietly changing type, an index
disappearing, or a foreign key that never got created are all things a green
migration will not tell you about.

## The boundary

Truss exposes structure only. It has no access to row contents and will not
return them, so do not reach for it to inspect records, count rows, or look up
a value. Use the application's own models and queries for that.
