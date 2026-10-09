<?php

declare(strict_types=1);

use AlbertoArena\Truss\Cli\Application;
use AlbertoArena\Truss\Cli\Bootstrapper;
use AlbertoArena\Truss\Cli\Dsn;
use AlbertoArena\Truss\Export\SchemaExporter;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * The test that stops the binary and the package disagreeing.
 *
 * Two entry points now read one database and claim to produce the same answer.
 * Nothing structural makes that true: they resolve their own containers, build
 * their own output, and are tested in their own files. v1.10 is the standing
 * example of why it matters, a release that recalibrated what the doctor
 * reports, where a binary built from the wrong commit would have described a
 * different database than the package sitting beside it on the same release
 * page. A divergence here is a red build instead of a support thread.
 *
 * This runs inside the package's own test harness, because the artisan half
 * needs an application. The CLI half then boots its own container over the top,
 * which replaces two global statics, so they are captured and restored in a
 * finally: without that, every test after this one would resolve out of a
 * container that belongs to a process that has finished.
 */

/**
 * The same schema through both surfaces, as bytes.
 *
 * @return array{artisan: string, cli: string}
 */
function parityExport(string $format, string $database): array
{
    $application = Container::getInstance();

    $artisanFile = tempnam(sys_get_temp_dir(), 'truss-parity-artisan-');
    $cliFile = tempnam(sys_get_temp_dir(), 'truss-parity-cli-');

    // The application reads the fixture file as its default connection, so
    // both surfaces are pointed at one database rather than at two copies of
    // one schema. managedConnections() follows database.default when
    // truss.connections is empty, which it is by default.
    config(['database.connections.parity' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'database.default' => 'parity']);

    expect(Artisan::call('truss:export', ['--format' => $format, '--output' => $artisanFile]))->toBe(0);

    try {
        Bootstrapper::boot(Dsn::parse('sqlite:'.$database));

        $tester = new CommandTester(Application::create()->find('export'));

        expect($tester->execute(['--dsn' => 'sqlite:'.$database, '--format' => $format, '--output' => $cliFile]))->toBe(0);
    } finally {
        Container::setInstance($application);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
    }

    return [
        'artisan' => (string) file_get_contents($artisanFile),
        'cli' => (string) file_get_contents($cliFile),
    ];
}

beforeEach(function (): void {
    // tempnam() creates the file it names, so appending an extension would
    // leave the connector pointed at a path that does not exist (it refuses to
    // create one) and leak the original. Hence the unlink and the touch.
    $base = (string) tempnam(sys_get_temp_dir(), 'truss-parity-');
    $this->database = $base.'.sqlite';
    @unlink($base);
    touch($this->database);

    // Built through the same schema builder the rest of the suite uses, on the
    // fixture file rather than in memory, because the binary opens its own
    // connection and an in-memory database would be a different one.
    config(['database.connections.parity' => ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '']]);

    Schema::connection('parity')->create('users', function ($table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    Schema::connection('parity')->create('orders', function ($table): void {
        $table->id();
        $table->foreignId('user_id')->constrained('users');
        $table->integer('total')->default(0);
        $table->string('status')->default('pending');
        $table->timestamps();
    });
});

afterEach(function (): void {
    @unlink($this->database);
});

it('produces the same bytes through artisan and through the binary', function (string $format): void {
    $output = parityExport($format, $this->database);

    expect($output['cli'])->toBe($output['artisan'])
        ->and($output['cli'])->not->toBeEmpty();
})->with(SchemaExporter::textFormats());

it('covers every format the exporter supports, so a new one cannot arrive untested', function (): void {
    // The datasets here are the exporter's own lists rather than copies of
    // them, and this pins the split: six text formats compared byte for byte
    // above, one document format compared below. A format added without a
    // parity story makes this fail, which is the moment to decide rather than
    // the moment to discover.
    expect(SchemaExporter::formats())->toHaveCount(7)
        ->and(SchemaExporter::textFormats())->toHaveCount(6)
        ->and(SchemaExporter::formats())->toContain('dbml', 'json', 'csv', 'markdown', 'mermaid', 'llm', 'html');
});

/**
 * The embedded payload, lifted out of an HTML export, and the document around
 * it with the payload blanked.
 *
 * @return array{document: string, payload: array<string, mixed>}
 */
function parityHtmlParts(string $html): array
{
    $pattern = '#<script type="application/json" data-truss-payload>(.*?)</script>#s';

    expect($html)->toMatch($pattern);

    preg_match($pattern, $html, $matches);

    return [
        'document' => (string) preg_replace($pattern, '<script data-truss-payload>PAYLOAD</script>', $html),
        'payload' => (array) json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR),
    ];
}

/**
 * The payload with the two values that carry a connection's *name* removed.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function parityWithoutConnectionIdentity(array $payload): array
{
    foreach ($payload['doctor']['findings'] ?? [] as $i => $finding) {
        unset($payload['doctor']['findings'][$i]['connection'], $payload['doctor']['findings'][$i]['fingerprint']);
    }

    return $payload;
}

it('exports the same HTML document through both surfaces', function (): void {
    // Everything except the embedded payload: the markup, the stylesheet, the
    // inlined fonts, the bundled module graph and the vendored Mermaid, which
    // is the overwhelming majority of a 3.6 MB file and the part most likely to
    // drift between a Laravel view factory and the CLI's hand-assembled one.
    $output = parityExport('html', $this->database);

    expect(parityHtmlParts($output['cli'])['document'])->toBe(parityHtmlParts($output['artisan'])['document']);
});

it('differs on HTML only in the connection name that doctor findings carry', function (): void {
    // **A real divergence, and not one the binary can fix.** The payload
    // embeds the doctor findings, and a finding carries its connection name
    // plus a fingerprint built from it (sha256 of code, connection, table,
    // column) so that a suppression can apply to one connection and not
    // another. An application calls its connection whatever it likes and the
    // binary calls its own `truss`, so those two values cannot agree, and
    // every other byte of the payload must.
    //
    // It also means an HTML export's bytes depend on a connection's *name*
    // rather than on the structure, so the same schema exported from two
    // environments differs under `--check`. That is the problem
    // `generated_at` and `diff` were already kept out of this payload to
    // avoid, and this is a third case that was missed. Whether to drop these
    // two from the exported document is a decision about a shipped format, so
    // this test pins the gap exactly rather than papering over it: if the
    // divergence ever grows beyond these two values, it fails.
    $output = parityExport('html', $this->database);

    $cli = parityHtmlParts($output['cli'])['payload'];
    $artisan = parityHtmlParts($output['artisan'])['payload'];

    expect(parityWithoutConnectionIdentity($cli))->toBe(parityWithoutConnectionIdentity($artisan))
        ->and($cli['doctor']['findings'][0]['connection'])->toBe('truss')
        ->and($artisan['doctor']['findings'][0]['connection'])->toBe('parity')
        ->and($cli['doctor']['findings'][0]['fingerprint'])->not->toBe($artisan['doctor']['findings'][0]['fingerprint']);
});

/**
 * The same doctor run through both surfaces.
 *
 * @return array{artisan: string, cli: string}
 */
function parityDoctor(string $format, string $database): array
{
    $application = Container::getInstance();

    config(['database.connections.parity' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''], 'database.default' => 'parity']);

    $artisan = new BufferedOutput;
    Artisan::call('truss:doctor', ['--format' => $format, '--fail-on' => 'never'], $artisan);

    try {
        Bootstrapper::boot(Dsn::parse('sqlite:'.$database));

        $tester = new CommandTester(Application::create()->find('doctor'));
        $tester->execute(['--dsn' => 'sqlite:'.$database, '--format' => $format, '--fail-on' => 'never']);
    } finally {
        Container::setInstance($application);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
    }

    return ['artisan' => $artisan->fetch(), 'cli' => $tester->getDisplay()];
}

it('prints the same doctor report through both surfaces', function (): void {
    // Byte for byte, and it can be: ConsoleFormatter builds the whole report
    // into its own buffer, emits no colour, and never names the connection, so
    // neither command contributes anything of its own.
    $report = parityDoctor('console', $this->database);

    expect(trim($report['cli']))->toBe(trim($report['artisan']))
        ->and($report['cli'])->toContain('TRUSS-IDX-001');
});

it('finds the same things in the same order, which is the half an order-blind test would miss', function (): void {
    // The plan asks for the findings AND their ordering, because a report whose
    // rows are right but shuffled is still a different artifact to anything
    // diffing it, and ordering bugs are exactly what a set comparison hides.
    $report = parityDoctor('json', $this->database);

    $identity = static fn (string $json): array => array_map(
        static fn (array $f): array => [$f['code'], $f['table'], $f['column'], $f['severity']],
        (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR)['findings'],
    );

    expect($identity($report['cli']))->toBe($identity($report['artisan']))
        ->and($identity($report['cli']))->not->toBeEmpty();
});

it('agrees on the severity summary, which is what a CI gate reads', function (): void {
    $report = parityDoctor('json', $this->database);

    $summary = static fn (string $json): array => (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR)['summary'];

    expect($summary($report['cli']))->toBe($summary($report['artisan']));
});

it('differs in the doctor JSON only by the connection name, and here that is correct', function (): void {
    // The same two values that make an HTML export environment-dependent are
    // present here too, and this is where they belong. A doctor report is about
    // a named live connection, so naming it is the point, and the fingerprint
    // is the suppression identity that has to differ per connection. An export
    // is a committed artifact and must not carry either. The distinction is the
    // whole argument of plans/html-export-determinism.md.
    $report = parityDoctor('json', $this->database);

    $findings = static fn (string $json): array => (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR)['findings'];

    $cli = $findings($report['cli']);
    $artisan = $findings($report['artisan']);

    expect($cli[0]['connection'])->toBe('truss')
        ->and($artisan[0]['connection'])->toBe('parity')
        ->and($cli[0]['fingerprint'])->not->toBe($artisan[0]['fingerprint']);

    // Everything else about the finding is identical.
    foreach ($cli as $i => $finding) {
        unset($finding['connection'], $finding['fingerprint']);
        $expected = $artisan[$i];
        unset($expected['connection'], $expected['fingerprint']);

        expect($finding)->toBe($expected);
    }
});
