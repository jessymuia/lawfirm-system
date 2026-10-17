<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * CARRY-5: a database shaped like the legacy production system, to practise the cutover on before a production copy
 * is available, and to test the rehearsal itself. It is this application's schema as it stood before the 2026 rebuild
 * (every migration dated before 2026), filled with records the way the old application wrote them: magic-number
 * invoice statuses, the generic status/archive flags, double-precision money, files under storage/app/cases/docs.
 *
 * With $problems it also plants what real legacy data tends to hold, so the tooling can be seen catching it: an invoice
 * status nobody documented, a disabled client, an archived matter, a line whose total does not add up, a duplicated
 * case number, and a document row whose file is missing.
 */
class LegacyFixture
{
    /** @return list<string> the legacy migration files, oldest first */
    public static function legacyMigrations(): array
    {
        return array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], fn ($f) => basename($f) < '2026'));
    }

    /**
     * @return array<string, int> rows written per table
     */
    public function build(string $connection, int $matters = 50, bool $problems = false, ?string $filesRoot = null): array
    {
        $repository = app('migration.repository');
        $repository->setSource($connection);
        $repository->repositoryExists() || $repository->createRepository();
        $migrator = app('migrator');
        // usingConnection also makes it the default for the migrations' own DB:: calls
        $migrator->usingConnection($connection, fn () => $migrator->run(self::legacyMigrations()));

        $db = DB::connection($connection);
        $now = '2025-06-30 12:00:00';
        $base = ['status' => 1, 'archive' => 0, 'created_at' => $now, 'updated_at' => $now];
        $filesRoot ??= storage_path('app');
        $counts = [];
        $insert = function (string $table, array $rows) use ($db, &$counts) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table($table)->insert($chunk);
            }
            $counts[$table] = ($counts[$table] ?? 0) + count($rows);
        };

        $hash = Hash::make('Legacy-pass-1');
        $users = max(3, (int) ceil($matters / 10));
        $insert('users', array_map(fn ($i) => ['name' => "Advocate {$i}", 'email' => "advocate{$i}@legacy.test", 'password' => $hash,
            'phone_number' => '07'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'department' => 'Litigation', 'hire_date' => '2020-01-01',
            'created_at' => $now, 'updated_at' => $now], range(1, $users)));
        foreach (['admin', 'advocate'] as $i => $role) {
            $db->table('roles')->insert(['name' => $role, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $db->table('model_has_roles')->insert(['role_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 1]);
        $counts['roles'] = 2;

        $insert('court_types', [['name' => 'High Court'] + $base, ['name' => 'Magistrate'] + $base]);
        $insert('courts', [['name' => 'Milimani High Court', 'type' => 1] + $base, ['name' => 'Kibera Law Courts', 'type' => 2] + $base]);
        $insert('hearing_types', [['name' => 'Mention'] + $base, ['name' => 'Hearing'] + $base, ['name' => 'Ruling', 'archive' => 1] + $base]);
        $insert('expense_categories', [['name' => 'Filing fees'] + $base, ['name' => 'Travel'] + $base]);

        $clients = max(1, (int) ceil($matters * 0.7));
        $insert('clients', array_map(fn ($i) => ['name' => "Client {$i}", 'phone_number' => '2547'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'address' => "{$i} Moi Avenue, Nairobi", 'advocate_id' => ($i % $users) + 1] + $base, range(1, $clients)));

        $cases = $attorneys = $hearings = $tasks = $invoices = $items = $expenses = $documents = $accesses = $audits = [];
        $docDir = 'cases/docs';
        File::ensureDirectoryExists("{$filesRoot}/{$docDir}");
        for ($m = 1; $m <= $matters; $m++) {
            $cases[] = ['case_number' => sprintf('HCCC/%d/%04d', 2020 + $m % 5, $m), 'description' => "Matter {$m}", 'client_id' => ($m % $clients) + 1,
                'assigned_to' => ($m % $users) + 1, 'start_date' => '2024-01-15', 'end_date' => null, 'case_type' => ['Civil', 'Criminal', 'Family'][$m % 3],
                'police_station' => 'Central', 'court_id' => ($m % 2) + 1, 'opposing_party' => "Opponent {$m}"] + $base;
            $attorneys[] = ['case_id' => $m, 'advocate_id' => ($m % $users) + 1, 'role' => 'lead'] + $base;
            $hearings[] = ['case_id' => $m, 'court_id' => ($m % 2) + 1, 'hearing_date' => '2025-07-'.str_pad((string) (1 + $m % 28), 2, '0', STR_PAD_LEFT),
                'hearing_type' => ($m % 3) + 1, 'notes' => 'Notes', 'outcome' => 'Adjourned'] + $base;
            $tasks[] = ['title' => "Task {$m}", 'description' => 'Prepare bundle', 'assigned_to' => ($m % $users) + 1, 'due_date' => '2025-07-10',
                'priority' => ['high', 'medium', 'low'][$m % 3], 'task_status' => ['pending', 'in_progress', 'completed', 'overdue'][$m % 4]] + $base;
            // invoices: mostly active (1), some drafts (3), deleted by admin (4) / by user (5)
            $invoices[] = ['case_id' => $m, 'client_id' => ($m % $clients) + 1, 'invoice_date' => '2025-05-01', 'invoice_due_date' => '2025-05-31',
                'status' => [1, 1, 1, 3, 4, 5][$m % 6]] + $base;
            foreach ([[2, 15000.0], [1, 3500.5]] as [$qty, $rate]) {
                $tax = round($qty * $rate * 0.16, 2);
                $items[] = ['invoice_id' => $m, 'description' => 'Professional fees', 'quantity' => $qty, 'rate' => $rate, 'tax' => $tax, 'total_amount' => round($qty * $rate + $tax, 2)] + $base;
            }
            $expenses[] = ['case_id' => $m, 'date' => '2025-05-02', 'amount' => 1250.75, 'category' => ($m % 2) + 1, 'description' => 'Filing',
                'vendor' => 'Judiciary', 'payment_method' => 'mpesa', 'invoice_number' => null, 'user_id' => ($m % $users) + 1] + $base;
            $file = "{$docDir}/matter-{$m}.pdf";
            File::put("{$filesRoot}/{$file}", "%PDF-1.4\nlegacy document for matter {$m}\n");
            $documents[] = ['case_id' => $m, 'title' => "Pleading {$m}", 'filename' => "matter-{$m}.pdf", 'filepath' => $file, 'full_path' => $file,
                'mimetype' => 'application/pdf', 'filesize' => 40, 'extension' => 'pdf'] + $base;
            $accesses[] = ['document_id' => $m, 'accessed_by' => ($m % $users) + 1, 'accessed_date' => '2025-06-01', 'action' => 'view',
                'ip_address' => '10.0.0.1', 'outcome' => 'success', 'device_info' => 'Chrome'] + $base;
            $audits[] = ['user_type' => 'App\\Models\\User', 'user_id' => 1, 'event' => 'created', 'auditable_type' => 'App\\Models\\Cases', 'auditable_id' => $m,
                'old_values' => '[]', 'new_values' => json_encode(['case_number' => $cases[$m - 1]['case_number']]), 'url' => '/api/cases', 'ip_address' => '10.0.0.1',
                'user_agent' => 'Chrome', 'tags' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($problems) {
            $cases[3]['case_number'] = $cases[2]['case_number'];          // a case number used twice
            $invoices[0]['status'] = 2;                                      // an invoice status nobody documented
            $cases[1]['archive'] = 1;                                         // an archived matter
            $items[0]['total_amount'] += 0.37;                                // a line that does not add up
            $documents[2]['filepath'] = $documents[2]['full_path'] = "{$docDir}/missing.pdf";   // its file is gone
        }
        $insert('cases', $cases);
        $insert('attorneys', $attorneys);
        $insert('hearings', $hearings);
        $insert('tasks', $tasks);
        $insert('invoices', $invoices);
        $insert('invoice_items', $items);
        $insert('expenses', $expenses);
        $insert('documents', $documents);
        $insert('document_accesses', $accesses);
        $insert('audits', $audits);
        if ($problems) {
            $db->table('clients')->where('id', 2)->update(['status' => 0]);   // a client the old system had disabled
            $db->table('invoices')->where('id', 5)->update(['deleted_at' => $now, 'deleted_by' => 1]);   // soft-deleted rows travel too
        }

        return $counts;
    }
}
