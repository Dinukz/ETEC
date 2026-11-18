<?php

namespace Tests\Feature;

use App\Http\Middleware\RunDailyHousekeeping;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⚠️ A NEW REGISTRATION STILL UNPAID AFTER 14 DAYS IS REMOVED (2026-11-11).
 *
 * Owner: "studentla register una hati day14 kin half payment kare nathi unoth
 * eyage account eka auto delete wenn hadann one full hari free card hari half
 * payment krla nm thiyenne eka delete krnn one na ... new student lata kalinuth
 * meka haduwa api eth wada krn na."
 *
 * students:purge-unpaid already had the rule, but it lived only on the
 * scheduler and Hostinger runs no cron — so it never ran. RunDailyHousekeeping
 * starts it once a day from ordinary traffic, after the page is sent. New
 * students only (created from 11 Nov): a first run over everyone would have
 * removed 876 September/October registrations at once.
 */
class UnpaidRegistrationAutoDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** A student registered at $when (Sri Lanka time) with one payment of $type. */
    private function registeredAt(string $when, ?string $type = null, string $status = 'pending', string $role = 'student'): User
    {
        $this->travelTo(Carbon::parse($when, 'Asia/Colombo'));

        $user = User::create([
            'name' => 'Registered '.Str::random(4), 'email' => Str::lower(Str::random(10)).'@gmail.com',
            'password' => Hash::make('secret-password'), 'status' => 'active', 'password_must_change' => true,
        ]);
        $user->syncRoles([$role]);

        Student::create([
            'user_id' => $user->id, 'student_code' => 'SRN'.Str::random(6),
            'consultant_id' => User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->id,
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => Course::first()->id,
            'consultant_id' => User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->id,
            'requested_tier' => 1, 'amount' => $type ? 28000 : 0, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => $type, 'status' => $status,
        ]);

        return $user;
    }

    private function exists(User $user): bool
    {
        return User::withTrashed()->whereKey($user->id)->exists();
    }

    public function test_a_new_registration_unpaid_after_14_days_is_removed(): void
    {
        $user = $this->registeredAt('2026-11-12 10:00');

        $this->travelTo(Carbon::parse('2026-11-27 10:00', 'Asia/Colombo'));
        Artisan::call('students:purge-unpaid');

        $this->assertFalse($this->exists($user), 'gone for good — the email is free to register again');
    }

    public function test_inside_the_14_days_it_stays(): void
    {
        $user = $this->registeredAt('2026-11-12 10:00');

        $this->travelTo(Carbon::parse('2026-11-25 10:00', 'Asia/Colombo'));
        Artisan::call('students:purge-unpaid');

        $this->assertTrue($this->exists($user));
    }

    public static function paid(): array
    {
        return [
            'half confirmed, waiting for the accountant' => ['half', 'pending'],
            'full confirmed, waiting for the accountant' => ['full', 'pending'],
            'free card' => ['free_card', 'pending'],
            'half verified' => ['half', 'verified'],
            'slip rejected (the consultant can re-attach it)' => ['full', 'rejected'],
        ];
    }

    /** "full hari free card hari half payment krla nm thiyenne eka delete krnn one na" */
    #[DataProvider('paid')]
    public function test_a_confirmed_payment_is_never_removed(string $type, string $status): void
    {
        $user = $this->registeredAt('2026-11-12 10:00', $type, $status);

        $this->travelTo(Carbon::parse('2026-12-30 10:00', 'Asia/Colombo'));
        Artisan::call('students:purge-unpaid');

        $this->assertTrue($this->exists($user));
    }

    /** ⚠️ An unpaid course beside a paid one: the person — and the money — stay. */
    public function test_an_unpaid_course_never_takes_a_paid_one_with_it(): void
    {
        $user = $this->registeredAt('2026-11-12 10:00');

        $second = Course::first()->replicate();
        $second->slug = 'second-course-'.Str::lower(Str::random(5));
        $second->save();

        Payment::create([
            'payment_code' => 'PYM'.Str::random(6), 'user_id' => $user->id, 'course_id' => $second->id,
            'consultant_id' => User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant->id,
            'requested_tier' => 1, 'amount' => 18000, 'currency' => 'LKR', 'method' => 'bank_transfer',
            'payment_type' => 'half', 'status' => 'verified',
        ]);

        $this->travelTo(Carbon::parse('2026-12-30 10:00', 'Asia/Colombo'));
        Artisan::call('students:purge-unpaid');

        $this->assertTrue($this->exists($user));
        $this->assertSame(2, Payment::where('user_id', $user->id)->count(), 'nothing of theirs was removed');
    }

    public function test_a_consultant_is_never_removed(): void
    {
        $user = $this->registeredAt('2026-11-12 10:00', role: 'consultant');

        $this->travelTo(Carbon::parse('2026-12-30 10:00', 'Asia/Colombo'));
        Artisan::call('students:purge-unpaid');

        $this->assertTrue($this->exists($user));
    }

    /** ⚠️ New students only — registrations from before 11 Nov are left for the owner to decide. */
    public function test_an_account_from_before_11_november_is_never_removed(): void
    {
        $old = $this->registeredAt('2026-11-10 23:30');
        $new = $this->registeredAt('2026-11-11 00:30');

        $this->travelTo(Carbon::parse('2026-12-30 10:00', 'Asia/Colombo'));
        Artisan::call('students:purge-unpaid');

        $this->assertTrue($this->exists($old), 'registered before 11 Nov (Sri Lanka time)');
        $this->assertFalse($this->exists($new));
    }

    // ----- runs by itself, with no cron ----------------------------------------------

    public function test_it_runs_by_itself_from_ordinary_traffic_once_a_day(): void
    {
        RunDailyHousekeeping::$runInTests = true;

        $first = $this->registeredAt('2026-11-12 10:00');
        $second = $this->registeredAt('2026-11-12 11:00');

        $this->travelTo(Carbon::parse('2026-11-27 08:00', 'Asia/Colombo'));
        // Not overdue yet at the moment the day's run happens.
        Payment::where('user_id', $second->id)->update(['created_at' => now()->subDays(3)]);

        $this->get(route('home'))->assertOk();
        $this->assertFalse($this->exists($first), 'the first visit of the day removed the overdue one');

        Payment::where('user_id', $second->id)->update(['created_at' => now()->subDays(20)]);
        $this->get(route('home'))->assertOk();
        $this->assertTrue($this->exists($second), 'only once a day');

        $this->travelTo(Carbon::parse('2026-11-28 08:00', 'Asia/Colombo'));
        $this->get(route('home'))->assertOk();
        $this->assertFalse($this->exists($second), 'and again the next day');
    }

    public function test_it_never_runs_during_other_tests(): void
    {
        $user = $this->registeredAt('2026-11-12 10:00');

        $this->travelTo(Carbon::parse('2026-11-27 08:00', 'Asia/Colombo'));
        $this->get(route('home'))->assertOk();

        $this->assertTrue($this->exists($user), 'switched off unless a test asks for it');
    }
}
