<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Consultant;
use App\Models\Course;
use App\Models\User;
use App\Services\ManualCommissionAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every commission added by hand is emailed to the admins (2026-10-29). Owner:
 * "point manual add kraddi email ekak yann one adminta eke include wenn one Name
 * / SCN / service group / Amount / Course (direct bonus only) / Date / Who
 * receives it / and point add krana date and time" — to dimuthura187@gmail.com
 * and dushandinzte@gmail.com. See App\Services\ManualCommissionAlert.
 */
class ManualCommissionAlertTest extends TestCase
{
    use RefreshDatabase;

    private Consultant $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
        \App\Services\CourseSchemaRepair::ensure();

        CommissionSetting::where('is_active', true)->update(['commission_per_student' => 2500, 'direct_commission' => 4000]);
        Cache::forget('commission_settings.active');

        // Capture what is really sent, through the same Mail::html() path.
        config(['mail.default' => 'array']);
        Mail::purge('array');

        $this->account = User::where('email', 'consultant@etec.lk')->firstOrFail()->consultant
            ->hiddenAccounts()->orderBy('id')->firstOrFail();

        $this->travelTo(Carbon::parse('2026-10-29 15:42:00', 'Asia/Colombo'));
    }

    private function superAdmin(): User
    {
        return User::where('email', 'superadmin@etec.lk')->firstOrFail();
    }

    private function add(array $form)
    {
        return $this->actingAs($this->superAdmin())->post(route('groups.manual-commission'), $form + [
            'consultant_id' => $this->account->id, 'type' => 'slot', 'tier' => 1, 'scope' => 'account',
            'earned_on' => '2026-10-29',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, \Symfony\Component\Mime\Email> */
    private function sent()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage());
    }

    private function to(\Symfony\Component\Mime\Email $email): string
    {
        return $email->getTo()[0]->getAddress();
    }

    public function test_a_hand_entry_emails_both_admins_everything_the_owner_listed(): void
    {
        $this->add([])->assertSessionHas('success', fn ($m) => str_contains($m, 'The admins have been emailed.'));

        $sent = $this->sent();
        $this->assertSame(['dimuthura187@gmail.com', 'dushandinzte@gmail.com'], $sent->map(fn ($e) => $this->to($e))->sort()->values()->all());

        $email = $sent->first();
        $this->assertSame('Commission added by hand — LKR 2,500 · '.$this->account->scn_code, $email->getSubject());

        $html = html_entity_decode($email->getHtmlBody());
        foreach ([
            'Name:', $this->account->fresh()->display_name,
            'SCN:', $this->account->scn_code,
            'Service Group:', 'Service Group 1',
            'Amount:', 'Service Group — LKR 2,500',
            'Course (direct bonus only):',
            'Date:', 'Thu, 29 Oct 2026',
            'Who receives it:', 'This SCN account only',
            'Added on:', 'Thu, 29 Oct 2026, 3:42 PM (Sri Lanka time)',
            'Added by:', 'superadmin@etec.lk',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_a_direct_bonus_names_the_course(): void
    {
        $elliott = Course::create([
            'title' => 'MASTER IN ELLIOTT WAVE', 'slug' => 'master-in-elliott-wave', 'price' => 39000,
            'direct_commission' => 5000, 'currency' => 'LKR', 'level' => 'advanced', 'status' => 'published',
        ]);

        $this->add(['type' => 'direct', 'course_id' => $elliott->id])->assertSessionHas('success');

        $html = html_entity_decode($this->sent()->first()->getHtmlBody());
        $this->assertStringContainsString('Direct bonus — LKR 5,000', $html);
        $this->assertStringContainsString('MASTER IN ELLIOTT WAVE (LKR 39,000)', $html);
    }

    public function test_an_upline_entry_lists_every_account_it_paid(): void
    {
        $this->add(['scope' => 'upline'])->assertSessionHas('success');

        $credited = Commission::where('type', 'bonus')->whereNull('payment_id')->with('consultant')->get();
        $this->assertGreaterThan(1, $credited->count(), 'guard: the climb reached more than one account');

        $html = html_entity_decode($this->sent()->first()->getHtmlBody());
        $this->assertStringContainsString('This account and its whole upline', $html);
        $this->assertStringContainsString('in total across '.$credited->count().' accounts', $html);

        foreach ($credited as $row) {
            $this->assertStringContainsString($row->consultant->scn_code ?? $row->consultant->consultant_code, $html);
        }
    }

    public function test_the_addresses_are_a_setting(): void
    {
        $this->actingAs($this->superAdmin())->get(route('settings.index'))
            ->assertOk()->assertSee('dimuthura187@gmail.com, dushandinzte@gmail.com');

        $this->actingAs($this->superAdmin())->patch(route('settings.switches'), [
            'student_line_attach' => 1, 'video_chunk_mb' => 4, 'manual_commission_alert_emails' => 'owner@example.com',
        ])->assertSessionHasNoErrors();

        $this->add([]);

        $this->assertSame(['owner@example.com'], $this->sent()->map(fn ($e) => $this->to($e))->all());
    }

    public function test_an_empty_list_sends_nothing(): void
    {
        $this->actingAs($this->superAdmin())->patch(route('settings.switches'), [
            'student_line_attach' => 1, 'video_chunk_mb' => 4, 'manual_commission_alert_emails' => '',
        ]);

        $this->add([])->assertSessionHas('success', fn ($m) => ! str_contains($m, 'emailed'));

        $this->assertCount(0, $this->sent());
        $this->assertSame([], ManualCommissionAlert::recipients());
    }

    public function test_a_wrong_address_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->superAdmin())->patch(route('settings.switches'), [
            'student_line_attach' => 0, 'video_chunk_mb' => 4, 'manual_commission_alert_emails' => 'dimuthura187@gmail.com, not-an-email',
        ])->assertSessionHasErrors('manual_commission_alert_emails');

        $this->assertSame(['dimuthura187@gmail.com', 'dushandinzte@gmail.com'], ManualCommissionAlert::recipients());
        $this->assertTrue(\App\Services\StudentLine::attachEnabled(), 'the other switches were not saved either');
    }

    /** The other switches still save when the form has no email field (an old page). */
    public function test_a_form_without_the_field_leaves_it_alone(): void
    {
        $this->actingAs($this->superAdmin())->patch(route('settings.switches'), ['student_line_attach' => 1, 'video_chunk_mb' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame(['dimuthura187@gmail.com', 'dushandinzte@gmail.com'], ManualCommissionAlert::recipients());
    }

    public function test_a_refused_entry_sends_no_email(): void
    {
        $student = User::factory()->create();

        $this->add(['student_user_id' => $student->id])->assertSessionHas('success');
        $sentBefore = $this->sent()->count();

        // The same student again is refused as a duplicate — nothing added, nothing sent.
        $this->add(['student_user_id' => $student->id])->assertSessionHas('error');

        $this->assertSame($sentBefore, $this->sent()->count());
    }
}
