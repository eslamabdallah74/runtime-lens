<?php

use RuntimeLens\Support\SourceLines;

beforeEach(function (): void {
    $this->file = $this->storageDirectory.'/Snippet.php';
    file_put_contents($this->file, "<?php\n    \$total = \$a + \$b;   \n".str_repeat('x', 300)."\n");
});

it('returns the trimmed line as the snippet', function (): void {
    expect((new SourceLines(microtime(true) + 60))->snippetAt($this->file, 2))->toBe('$total = $a + $b;');
});

it('cuts long lines to 200 characters', function (): void {
    expect(mb_strlen((new SourceLines(microtime(true) + 60))->snippetAt($this->file, 3)))->toBe(200);
});

it('returns no snippet for a file changed after the running code was loaded', function (): void {
    touch($this->file, time() + 120);

    expect((new SourceLines(microtime(true)))->snippetAt($this->file, 2))->toBeNull();
});

it('returns no snippet for missing files and lines', function (): void {
    $lines = new SourceLines(microtime(true) + 60);

    expect($lines->snippetAt($this->storageDirectory.'/missing.php', 1))->toBeNull()
        ->and($lines->snippetAt($this->file, 99))->toBeNull();
});
