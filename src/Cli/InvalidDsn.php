<?php

declare(strict_types=1);

namespace AlbertoArena\Truss\Cli;

use InvalidArgumentException;

/**
 * A connection string the CLI cannot use.
 *
 * It exists as its own type so the console layer can catch exactly this and
 * print one sentence with a non-zero exit, rather than letting a stack trace
 * stand in for a usage error. **Its message never contains the connection
 * string.** A DSN carries a password, and an error message travels: into a
 * terminal somebody screenshots, into CI output, into an issue report.
 */
final class InvalidDsn extends InvalidArgumentException {}
