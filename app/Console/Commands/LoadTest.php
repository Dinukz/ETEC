<?php

namespace App\Console\Commands;

use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommissionRuleAudit;
use App\Services\HiddenAccountService;
use App\Services\PaymentService;
use App\Services\ServiceGroupFlush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Feed the commission engine a realistic pile of data and see whether it holds.
 *
 * Asked for 2026-08-29: "e wage studentla and consultant la 1000k log wela data
 * feed kraddi system crash wen nathuw run wenod blann one?"
 *
 * Registering a student is the single most expensive thing this application
 * does: one verification writes a seat, a slot, a direct bonus, and then walks
 * the whole account tree and the sponsor chain writing a 2,500 at every level.
 * The cost per student therefore GROWS with the depth of the network, which is
 * exactly the shape that looks fine in testing and falls over on a live site.
 *
 * So this reports per-phase wall time, peak memory and QUERY COUNT rather than
 * a pass/fail: a run that finishes in 40 seconds having issued 400,000 queries
 * is a warning, not a success.
 *
 * ⚠️ IT CREATES REAL ROWS. It refuses to touch a production database, and it
 * says out loud that the data it writes is permanent unless you roll it back.
 */
class LoadTest extends Command
{
    protected $signature = 'etec:load-test
        {--consultants=50 : How many consultants to create, each with their 3 accounts}
        {--students=1000 : How many students to register and verify}
        {--depth=3 : How many consultants deep to build the sponsor chain}
        {--run : Also perform a System Run at the end and time it}
        {--audit : Also run the commission rule audit at the end}
        {--rollback : Wrap everything in a transaction and roll it back afterwards}
        {--force : Required to run against a production environment}';

    protected $description = 'Feed the commission engine N consultants and M students, and report where it slows down or breaks.';

    private array $phases = [];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to write load-test data to a production database. Re-run with --force if that is really what you want.');

            return self::FAILURE;
        }

        $consultants = max(1, (int) $this->option('consultants'));
        $students = max(1, (int) $this->option('students'));

        if (! Course::where('status', 'published')->exists()) {
            $this->error('No published course exists, so no payment can be created. Seed one first.');

            return self::FAILURE;
        }

        if (! $this->option('rollback')) {
            $this->warn('This writes REAL rows and does not clean up after itself. Use --rollback to throw the data away at the end.');
        }

        $this->line('');
        $this->info(sprintf('Feeding %s consultant(s) and %s student(s)...', number_format($consultants), number_format($students)));
        $this->line('');

        $work = function () use ($consultants, $students) {
            $accounts = $this->phase('Create consultants', fn () => $this->createConsultants($consultants));

            $this->phase('Register + verify students', fn () => $this->registerStudents($students, $accounts));

            if ($this->option('run')) {
                $this->phase('System Run', fn () => $this->systemRun());
            }

            if ($this->option('audit')) {
                $this->phase('Rule audit', fn () => $this->audit());
            }
        };

        try {
            if ($this->option('rollback')) {
                DB::beginTransaction();
                $work();
                DB::rollBack();
                $this->line('');
                $this->info('Rolled back — the database is unchanged.');
            } else {
                $work();
            }
        } catch (\Throwable $e) {
            if ($this->option('rollback') && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->line('');
            $this->error('IT BROKE: '.$e->getMessage());
            $this->line('  '.$e->getFile().':'.$e->getLine());

            $this->report();

            return self::FAILURE;
        }

        $this->report();

        return self::SUCCESS;
    }

    /** Time one phase, and count every query it issues. */
    private function phase(string $name, \Closure $work): mixed
    {
        $queries = 0;
        $listener = function () use (&$queries) {
            $queries++;
        };

        DB::listen($listener);

        $started = microtime(true);
        $result = $work();
        $elapsed = microtime(true) - $started;

        $this->phases[] = [
            'name' => $name,
            'seconds' => round($elapsed, 2),
            'queries' => $queries,
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];

        $this->line(sprintf('  %-28s %6.2fs  %8s queries', $name, $elapsed, number_format($queries)));

        return $result;
    }

    /**
     * Build a sponsor CHAIN, not a flat list — the per-student cost grows with
     * depth, so a flat network would hide the thing this is meant to measure.
     *
     * @return array<int, Consultant> every placement account created
     */
    private function createConsultants(int $count): array
    {
        $depth = max(1, (int) $this->option('depth'));
        $accounts = [];
        $chain = [];

        $bar = $this->output->createProgressBar($count);

        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create();
            $user->syncRoles(['consultant']);

            // Every $depth consultants, start the chain again from the top.
            $parent = ($i % $depth === 0) ? null : ($chain[count($chain) - 1] ?? null);

            $consultant = Consultant::create([
                'user_id' => $user->id,
                'parent_consultant_id' => $parent?->id,
                'consultant_code' => 'CON'.Str::random(10),
                'referral_code' => strtoupper(Str::random(10)),
                'status' => 'active',
            ]);

            app(HiddenAccountService::class)->ensureFor($consultant);

            $chain[] = $consultant;

            if ($i % $depth === 0) {
                $chain = [$consultant];
            }

            foreach ($consultant->fresh()->hiddenAccounts as $account) {
                $accounts[] = $account;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');

        return $accounts;
    }

    private function registerStudents(int $count, array $accounts): void
    {
        $courseId = Course::where('status', 'published')->value('id');
        $accountant = User::role('accountant')->first() ?? User::role('super_admin')->firstOrFail();
        $service = app(PaymentService::class);
        $rate = (float) CommissionSetting::current()->course_price ?: 39000;

        $bar = $this->output->createProgressBar($count);

        for ($i = 0; $i < $count; $i++) {
            $account = $accounts[array_rand($accounts)];

            $user = User::factory()->create();
            Student::create([
                'user_id' => $user->id,
                'student_code' => 'SRN'.Str::random(8),
                'status' => 'active',
                'enrolled_at' => now()->subDays(random_int(1, 30)),
            ]);

            $payment = Payment::create([
                'payment_code' => 'PYM'.Str::random(8),
                'user_id' => $user->id,
                'course_id' => $courseId,
                'consultant_id' => $account->id,
                'requested_tier' => random_int(1, 2),
                'amount' => $rate,
                'currency' => 'LKR',
                'method' => 'bank_transfer',
                'payment_type' => 'full',
                'status' => 'pending',
                'paid_at' => now()->subDays(random_int(1, 30)),
            ]);

            $service->verify($payment, $accountant);

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
    }

    private function systemRun(): void
    {
        $result = app(ServiceGroupFlush::class)->runAll(now());

        $this->line(sprintf(
            '    settled %d account(s), released LKR %s, to company LKR %s%s',
            $result['flushed'],
            number_format((float) $result['payout']),
            number_format((float) $result['forfeit']),
            $result['failures'] ? ', '.count($result['failures']).' FAILURE(S)' : '',
        ));

        foreach (array_slice($result['failures'], 0, 5) as $failure) {
            $this->error('    '.$failure);
        }
    }

    private function audit(): void
    {
        $audit = app(CommissionRuleAudit::class)->run();

        if ($audit['violations'] === [] && $audit['stage_issues'] === []) {
            $this->line(sprintf('    %s payment(s) checked, no rule broken', number_format($audit['checked'])));

            return;
        }

        $this->error(sprintf(
            '    %d rule violation(s) and %d wrong stage(s) across %s payment(s)',
            count($audit['violations']),
            count($audit['stage_issues']),
            number_format($audit['checked']),
        ));

        foreach ($audit['summary'] as $rule => $count) {
            $this->line('      '.str_pad((string) $count, 6, ' ', STR_PAD_LEFT).'  '.(CommissionRuleAudit::RULES[$rule] ?? $rule));
        }
    }

    private function report(): void
    {
        if ($this->phases === []) {
            return;
        }

        $this->line('');
        $this->table(
            ['Phase', 'Seconds', 'Queries', 'Peak MB'],
            array_map(fn ($p) => [
                $p['name'],
                number_format($p['seconds'], 2),
                number_format($p['queries']),
                $p['peak_mb'],
            ], $this->phases),
        );

        $total = array_sum(array_column($this->phases, 'seconds'));
        $queries = array_sum(array_column($this->phases, 'queries'));

        $this->line(sprintf(
            '  Total %.2fs, %s queries, peak %s MB.',
            $total,
            number_format($queries),
            end($this->phases)['peak_mb'],
        ));
        $this->line('');
        $this->comment('  Queries per student is the number to watch — it grows with network depth, not with student count.');
    }
}
