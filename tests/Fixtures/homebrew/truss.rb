class Truss < Formula
  desc "Structure only: ER diagram (ERD) viewer and schema doctor for live databases"
  homepage "https://trussphp.com"
  url "https://github.com/albertoarena/laravel-truss/releases/download/v1.15.0/truss.phar"
  sha256 "5dfbfd8d53690eb462dac8521e4fae9ddc43a30cbb7073a95405d02390f34fec"
  license "MIT"

  # PHP is a test dependency only, so installing Truss installs no
  # interpreter: the binary runs on the PHP the user already has.
  depends_on "php" => :test

  def install
    libexec.install "truss.phar"

    # A presence check and a message, never a version pin. Without PHP the
    # PHAR's shebang fails in env before any of Truss runs, so this is the
    # only place that case can be explained. The PHP 8.2 floor is enforced
    # inside the PHAR, which reports the version it found and the one it
    # needs. Pinning a version here would duplicate that check and drift
    # from it. Exit 2, because 1 means the schema check found something.
    (bin/"truss").write <<~SH
      #!/bin/sh
      if ! command -v php >/dev/null 2>&1; then
        echo "Truss needs PHP. Run: brew install php" >&2
        exit 2
      fi
      exec php "#{libexec}/truss.phar" "$@"
    SH
  end

  test do
    assert_match "Truss v#{version}", shell_output("#{bin}/truss --version")

    db = testpath/"structure.db"
    system "php", "-r", <<~PHP
      $pdo = new PDO('sqlite:#{db}');
      $pdo->exec('CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
      $pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER REFERENCES authors (id))');
    PHP

    export = shell_output("#{bin}/truss export --dsn=sqlite:#{db} --format=json")
    assert_match '"references_table": "authors"', export

    # An unindexed foreign key is a finding, and a finding exits 1.
    assert_match "TRUSS-IDX-001", shell_output("#{bin}/truss doctor --dsn=sqlite:#{db}", 1)

    (testpath/"no-php").mkpath
    with_env(PATH: (testpath/"no-php").to_s) do
      assert_match "Truss needs PHP. Run: brew install php", shell_output("#{bin}/truss --version 2>&1", 2)
    end
  end
end
