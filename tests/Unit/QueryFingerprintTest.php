<?php

use RuntimeLens\Support\QueryFingerprint;

it('ignores whitespace differences', function (): void {
    expect(QueryFingerprint::of("select *\n  from users where id = ?"))->toBe(QueryFingerprint::of('select * from users where id = ?'));
});

it('collapses placeholder lists of any length', function (): void {
    expect(QueryFingerprint::of('select * from users where id in (?, ?, ?)'))
        ->toBe(QueryFingerprint::of('select * from users where id in (?)'))
        ->toBe(QueryFingerprint::of('select * from users where id in (?,?)'));
});

it('keeps different queries apart', function (): void {
    expect(QueryFingerprint::of('select * from users'))->not->toBe(QueryFingerprint::of('select * from posts'));
});

it('handles a list of 65,000 placeholders', function (): void {
    $sql = 'select 1 where 1 in ('.implode(', ', array_fill(0, 65000, '?')).')';

    expect(QueryFingerprint::of($sql))->toBe(QueryFingerprint::of('select 1 where 1 in (?)'));
});
