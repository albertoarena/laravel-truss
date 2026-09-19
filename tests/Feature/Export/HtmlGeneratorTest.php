<?php

declare(strict_types=1);

use AlbertoArena\Truss\Export\HtmlGenerator;
use AlbertoArena\Truss\Export\SchemaExporter;
use AlbertoArena\Truss\Tests\Support\SchemaBuilder;

function htmlFixture(): array
{
    return (new SchemaExporter)->tablesFor(
        SchemaBuilder::make()
            ->table('users', fn ($t) => $t->id()->string('email'))
            ->table('posts', fn ($t) => $t->id()->foreignId('user_id', 'users'))
            ->build()['tables'],
    );
}

function generatedDocument(): string
{
    return app(HtmlGenerator::class)->generate(htmlFixture());
}

it('produces a complete HTML document', function () {
    expect(generatedDocument())->toStartWith('<!DOCTYPE html>')
        ->and(generatedDocument())->toContain('id="truss-app"')
        ->and(generatedDocument())->toContain('</html>');
});

it('embeds the payload in the shape the frontend seam reads', function () {
    preg_match(
        '/<script type="application\/json" data-truss-payload>(.*?)<\/script>/s',
        generatedDocument(),
        $matches,
    );

    $payload = json_decode($matches[1] ?? '', true);

    expect($payload)->toBeArray()
        ->and(array_column($payload['tables'], 'name'))->toBe(['posts', 'users'])
        // Excluded because they would make a committed file drift under --check
        // on a day when no column moved: generated_at changes on every rebuild,
        // and a diff is a comparison against a baseline the reader cannot see.
        ->and($payload)->not->toHaveKey('generated_at')
        ->and($payload)->not->toHaveKey('diff');
});

it('carries no endpoint, because there is no server to call', function () {
    expect(generatedDocument())->not->toContain('data-schema-endpoint')
        ->and(generatedDocument())->not->toContain('data-export-endpoint');
});

it('fetches nothing: no absolute URL in any fetching position', function () {
    $document = generatedDocument();

    // Asserted on fetching positions rather than on the raw string. The document
    // legitimately contains around sixty http(s) occurrences, every one an XML
    // namespace, a licence URL or an error-message link inside Mermaid's own
    // source, and none of them is ever requested.
    expect($document)->not->toMatch('/(?:src|href)=["\']https?:/i')
        ->and($document)->not->toMatch('/url\(\s*["\']?https?:/i')
        ->and($document)->not->toContain('url("ibm-plex-mono');
});

it('inlines the stylesheet, mermaid and the module graph', function () {
    $document = generatedDocument();

    expect($document)->toContain('url("data:font/woff2;base64,')
        ->and($document)->toContain('data:text/javascript;base64,')
        ->and(strlen($document))->toBeGreaterThan(3_000_000);
});

it('is deterministic: two exports of the same schema are byte-identical', function () {
    expect(generatedDocument())->toBe(generatedDocument());
});

it('exposes no row data', function () {
    expect(generatedDocument())->not->toContain('alberto@example.com');
});

it('ignores a host application override of the dashboard view', function () {
    // hasViews() registers a publish group, so an application that ran
    // vendor:publish owns a copy of index.blade.php that wins over the
    // package's. That copy is a snapshot of whatever version it was published
    // from, and it renders the dashboard for a server: route() URLs, no inlined
    // assets, no embedded payload. An export built from it is a file that
    // silently phones home to an origin the reader cannot reach, which is the
    // one thing this format promises never to do.
    $overridden = sys_get_temp_dir().'/truss-view-override-'.bin2hex(random_bytes(4));
    mkdir($overridden);
    file_put_contents($overridden.'/index.blade.php', '<p>HOST COPY</p>');

    app('view')->prependNamespace('truss', $overridden);

    $document = app(HtmlGenerator::class)->generate(htmlFixture());

    unlink($overridden.'/index.blade.php');
    rmdir($overridden);

    expect($document)->not->toContain('HOST COPY')
        ->and($document)->toContain('data-truss-payload');
});
