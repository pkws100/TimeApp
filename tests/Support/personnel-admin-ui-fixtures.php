<?php

declare(strict_types=1);

use App\Domain\Personnel\Compliance\EmploymentContextService;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Personnel\Compliance\PersonnelFileRuntime;
use App\Domain\Personnel\Compliance\PersonnelSyntheticHttpBoundary;
use App\Domain\Personnel\Storage\ClamAvPersonnelScanner;
use App\Domain\Personnel\Storage\PersonnelFileValidator;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Domain\Personnel\Storage\PersonnelStreamCipher;
use App\Infrastructure\Database\DatabaseConnection;
use Tests\Support\PersonnelFileTestFixture;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli' || $argc !== 5) {
    exit(2);
}
[$script, $operation, $root, $port, $qpdf] = $argv;
if (!preg_match('~\A/var/tmp/timeapp-personnel-http-([a-f0-9]{12})\z~', $root, $name)
    || !in_array($operation, ['prepare', 'seed'], true)
    || !ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
    throw new InvalidArgumentException('Ungültige isolierte Testkonfiguration.');
}
PersonnelPrivatePath::requireRoot($root, [base_path(), sys_get_temp_dir()]);
PersonnelPrivatePath::requireTrustedExecutable($qpdf);
$fixtureId = $name[1];
$database = 'timeapp_personnel_http_' . $fixtureId;
$username = 'timeapp_ph_' . $fixtureId;
$socket = $root . '/mariadb.sock';
$dbConfig = [
    'driver' => 'mysql', 'host' => 'localhost', 'port' => 3306,
    'database' => $database, 'socket' => $socket,
    'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
];
$server = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $server->query('SELECT @@skip_networking, @@socket, @@datadir')->fetch(PDO::FETCH_NUM);
if ((int) $identity[0] !== 1 || $identity[1] !== $socket || rtrim($identity[2], '/') !== $root . '/database') {
    throw new RuntimeException('Die Fixture-Datenbank muss der neu erzeugte Socket-Prozess sein.');
}
$write = static function (string $path, string $contents): void {
    $file = PersonnelPrivatePath::createExclusive($path);
    try {
        PersonnelStreamCipher::writeAll($file, $contents);
    } finally {
        fclose($file);
    }
};
$json = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$php = static fn (array $data): string => '<?php return ' . var_export($data, true) . ';' . "\n";

if ($operation === 'prepare') {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $password = bin2hex(random_bytes(24));
    $account = "'{$username}'@'localhost'";
    $server->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $server->quote($password));
    $target = '`' . str_replace('_', '\\_', $database) . '`.*';
    $server->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ' . $target . ' TO ' . $account);
    $write($root . '/database.php', $php(array_replace($dbConfig, ['username' => $username, 'password' => $password])));
    $write($root . '/phinx.php', $php([
        'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
        'environments' => [
            'default_migration_table' => 'phinxlog', 'default_environment' => 'test',
            'test' => [
                'adapter' => 'mysql', 'name' => $database, 'unix_socket' => $socket,
                'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ],
        ],
        'version_order' => 'creation',
    ]));
    $write($root . '/file-keys.json', $json(['active' => 'fixture', 'keys' => ['fixture' => base64_encode(random_bytes(32))]]));
    exit;
}

$db = new DatabaseConnection($dbConfig);
$actorPassword = bin2hex(random_bytes(12));
$seedUser = static function (string $number, string $last, bool $archived = false) use ($db, $actorPassword): array {
    $email = strtolower($number) . '@personnel.example.test';
    $db->execute(
        'INSERT INTO users (employee_number, first_name, last_name, email, password_hash, employment_status, is_deleted)
         VALUES (:number, "Synthetic", :last_name, :email, :password_hash, :status, :deleted)',
        ['number' => $number, 'last_name' => $last, 'email' => $email, 'password_hash' => password_hash($actorPassword, PASSWORD_DEFAULT),
            'status' => $archived ? 'terminated' : 'active', 'deleted' => (int) $archived]
    );

    return ['id' => $db->lastInsertId(), 'email' => $email, 'password' => $actorPassword, 'number' => $number, 'name' => 'Synthetic ' . $last];
};
$admin = $seedUser('PC-001', 'Administrator');
$manager = $seedUser('PC-002', 'Manager');
$viewer = $seedUser('PC-003', 'Viewer');
$outsider = $seedUser('PC-004', 'Outsider');
$employee = $seedUser('PC-101', str_repeat('Mitarbeiteraktenname', 5));
$foreign = $seedUser('PC-102', 'Foreign');
$archived = $seedUser('PC-103', 'Archived', true);

$grantRole = static function (array $user, string $slug, array $rights) use ($db): int {
    if ($slug === 'administrator') {
        $role = (int) $db->fetchColumn('SELECT id FROM roles WHERE slug = :slug', ['slug' => $slug]);
    } else {
        $db->execute('INSERT INTO roles (slug, name) VALUES (:slug, :name)', ['slug' => $slug, 'name' => $slug]);
        $role = $db->lastInsertId();
        foreach (['dashboard.view', ...$rights] as $right) {
            $db->execute(
                'INSERT INTO role_permissions (role_id, permission_id) SELECT :role_id, id FROM permissions WHERE code = :code',
                ['role_id' => $role, 'code' => $right]
            );
        }
    }
    $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $user['id'], 'role_id' => $role]);

    return $role;
};
$admin['roleId'] = $grantRole($admin, 'administrator', []);
$manager['roleId'] = $grantRole($manager, 'fixture-personnel-manager', [PersonnelAccessService::VIEW, PersonnelAccessService::MANAGE]);
$viewer['roleId'] = $grantRole($viewer, 'fixture-personnel-viewer', [PersonnelAccessService::VIEW]);
$outsider['roleId'] = $grantRole($outsider, 'fixture-general-viewer', ['files.view', 'personnel.view']);
foreach ([$manager, $viewer] as $grantee) {
    foreach ([$employee, $archived] as $subject) {
        $db->execute(
            'INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
             VALUES (:employee, :grantee, :actor, 1, "assignment")',
            ['employee' => $subject['id'], 'grantee' => $grantee['id'], 'actor' => $admin['id']]
        );
    }
}

PersonnelFileTestFixture::login($db, $admin['id']);
$access = new PersonnelAccessService($db, PersonnelFileTestFixture::auth($db));
$employment = new EmploymentContextService($db, $access);
$initial = $employment->append($employee['id'], 'synthetic-job', 0, null, [
    'effective_from' => '2026-01-01', 'employment_type' => 'marginal',
], 'initial_record', 'http-employee-context-1');
$employment->changeFrom($employee['id'], 'synthetic-job', $initial['revision'], $initial['context_id'], [
    'effective_from' => '2027-01-01', 'employment_type' => 'regular',
], 'http-employee-context-2');

$db->execute('INSERT INTO personnel_document_types (code, label, category) VALUES ("synthetic_identity", "Synthetic document type", "fixture")');
$type = $db->lastInsertId();
$db->execute(
    'INSERT INTO personnel_document_requirements (rule_code, document_type_id, source_id, citation, valid_from, valid_until, legal_force,
         record_kind, record_frequency, employment_scope, evidence_rule, signature_rule, retention_rule)
     SELECT "SYNTHETIC-1", :document_type, id, "Synthetic fixture reference", "2026-01-01", "2099-12-31", "optional",
         "fixture", "once", "Synthetic scope", "Synthetic procedure", "Unresolved", "Synthetic only"
     FROM personnel_compliance_sources ORDER BY id LIMIT 1',
    ['document_type' => $type]
);
$requirement = $db->lastInsertId();
$documents = new PersonnelDocumentService($db, $access);
$document = $documents->create($employee['id'], [
    'document_type_id' => $type, 'document_requirement_id' => $requirement,
    'period_from' => '2026-06-01', 'period_until' => '2026-06-30',
], 'ui-own-fixture-document')['document_id'];
$foreignDocument = $documents->create($foreign['id'], ['document_type_id' => $type], 'ui-foreign-fixture-document')['document_id'];
for ($day = 1; $day <= 24; $day++) {
    $documents->create($employee['id'], [
        'document_type_id' => $type,
        'period_from' => sprintf('2026-03-%02d', $day),
    ], 'ui-document-list-fixture-' . $day);
}

$storage = new PersonnelPrivateStorage($root . '/private-store', [base_path(), $root . '/incoming']);
$cipher = new PersonnelStreamCipher(PersonnelKeyring::fromFile($root . '/file-keys.json'));
$validator = new PersonnelFileValidator($qpdf);
$scanner = new ClamAvPersonnelScanner(PersonnelSyntheticHttpBoundary::scannerDirectory($fixtureId) . '/clamd.sock');
$validator->assertReady();
$scanner->assertReady();
$seedVersion = static function (int $documentId, string $bytes) use ($db, $storage, $cipher, $validator, $scanner, $admin): int {
    $input = fopen('php://memory', 'w+b');
    PersonnelStreamCipher::writeAll($input, $bytes);
    rewind($input);
    try {
        return $storage->withLock(false, static function () use ($db, $storage, $cipher, $validator, $scanner, $admin, $documentId, $input, $bytes): int {
            $staged = $storage->stageUpload($input, strlen($bytes));
            try {
                $mime = $validator->validate($staged->path(), 'image/png', 'png');
                $source = $staged->open();
                $key = $storage->newStorageKey();
                $output = $storage->beginObject($key);
                try {
                    $scanner->scan($source);
                    rewind($source);
                    $keyId = $cipher->encrypt($source, $output, PersonnelFileRuntime::objectContext($key));
                } finally {
                    fclose($output);
                    fclose($source);
                }
                $storage->publishObject($key);
                $db->execute(
                    'INSERT INTO employee_document_versions (document_id, version_number, storage_key, sha256, mime_type, size_bytes,
                        storage_key_id, storage_format, format_verified_at, malware_scanned_at, created_by_user_id)
                     VALUES (:document, 1, :storage_key, :hash, :mime, :size, :key_id, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :actor)',
                    ['document' => $documentId, 'storage_key' => $key, 'hash' => $staged->sha256(), 'mime' => $mime,
                        'size' => $staged->size(), 'key_id' => $keyId, 'actor' => $admin['id']]
                );
                $versionId = $db->lastInsertId();
                $db->execute('UPDATE employee_documents SET lock_version = lock_version + 1 WHERE id = :id', ['id' => $documentId]);

                return $versionId;
            } finally {
                $staged->discard();
            }
        });
    } finally {
        fclose($input);
    }
};
$pngBytes = PersonnelFileTestFixture::png();
$version = $seedVersion($document, $pngBytes);
$foreignVersion = $seedVersion($foreignDocument, PersonnelFileTestFixture::png(100));
$files = [];
$file = static function (string $filename, string $bytes) use ($root, $write, &$files): void {
    $files[$filename] = $root . '/' . $filename;
    $write($files[$filename], $bytes);
};
$file('synthetic.png', $pngBytes);
$file('synthetic-180.png', PersonnelFileTestFixture::png(180));
$file('synthetic-220.png', PersonnelFileTestFixture::png(220));
$file('synthetic-malware.png', PersonnelFileTestFixture::scannerRejectedPng());
$file('invalid.png', 'synthetic invalid file');

$image = imagecreatetruecolor(4, 4);
imagefill($image, 0, 0, imagecolorallocate($image, 70, 100, 130));
ob_start();
imagejpeg($image);
$file('synthetic.jpg', (string) ob_get_clean());

$pdf = "%PDF-1.4\n";
$xref = "xref\n0 5\n0000000000 65535 f \n";
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 120 120] /Resources << >> /Contents 4 0 R >>',
    "<< /Length 0 >>\nstream\nendstream",
];
foreach ($objects as $index => $object) {
    $xref .= sprintf('%010d 00000 n ', strlen($pdf)) . "\n";
    $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
}
$file('synthetic.pdf', $pdf . $xref . "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . strlen($pdf) . "\n%%EOF\n");

$uploadDigests = array_map(static fn (string $path): string => hash_file('sha256', $path), array_values($files));
$write($root . '/fixture.json', $json([
    'fixture_id' => $fixtureId, 'listen_port' => (int) $port, 'qpdf' => $qpdf,
    'upload_sha256' => array_values(array_unique($uploadDigests)),
]));

$write($root . '/ui.json', $json(compact('admin', 'manager', 'viewer', 'outsider', 'employee', 'foreign', 'archived', 'document',
    'version', 'foreignDocument', 'foreignVersion', 'type', 'requirement', 'database', 'files') + [
    'scratchRoot' => $root,
    'scannerSocket' => PersonnelSyntheticHttpBoundary::scannerDirectory($fixtureId) . '/clamd.sock',
]));
