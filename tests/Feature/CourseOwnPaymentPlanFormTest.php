<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manage → Courses: a course's own half payment and direct service income
 * (2026-10-22). Admin / super admin only — the direct is money paid to
 * consultants, so a lecturer can neither see nor change it. Blank = the
 * standard from Settings. See App\Support\CourseMoney.
 */
class CourseOwnPaymentPlanFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // The live standard.
        \App\Models\CommissionSetting::where('is_active', true)->update(['direct_commission' => 4000]);
        \Illuminate\Support\Facades\Cache::forget('commission_settings.active');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    private function lecturer(): User
    {
        return User::where('email', 'lecturer@etec.lk')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'MASTER IN ELLIOTT WAVE',
            'price' => 39000,
            'half_payment_amount' => 20000,
            'direct_commission' => 5000,
            'level' => 'advanced',
            'status' => 'published',
        ], $overrides);
    }

    private function elliott(): Course
    {
        return Course::where('title', 'MASTER IN ELLIOTT WAVE')->firstOrFail();
    }

    public function test_an_admin_creates_the_course_with_its_own_amounts_in_one_save(): void
    {
        $this->actingAs($this->admin())
            ->post(route('manage.courses.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $course = $this->elliott();

        $this->assertSame(39000.0, (float) $course->price);
        $this->assertSame(20000.0, (float) $course->half_payment_amount);
        $this->assertSame(5000.0, (float) $course->direct_commission);
        $this->assertSame('published', $course->status);
    }

    public function test_the_form_shows_the_fields_to_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('manage.courses.create'))
            ->assertOk()
            ->assertSee('Half payment — first instalment (LKR)')
            ->assertSee('Direct service income (LKR)')
            ->assertSee('Standard: 18,000')
            ->assertSee('Standard: 4,000');
    }

    public function test_an_admin_edits_and_clears_the_amounts(): void
    {
        $this->actingAs($this->admin())->post(route('manage.courses.store'), $this->payload());
        $course = $this->elliott();

        $this->actingAs($this->admin())
            ->get(route('manage.courses.edit', $course))
            ->assertOk()
            ->assertSee('value="20000.00"', false)
            ->assertSee('value="5000.00"', false);

        $this->actingAs($this->admin())
            ->patch(route('manage.courses.update', $course), $this->payload(['direct_commission' => 6000]))
            ->assertSessionHasNoErrors();
        $this->assertSame(6000.0, (float) $course->fresh()->direct_commission);

        // Blank = back to the standard.
        $this->actingAs($this->admin())
            ->patch(route('manage.courses.update', $course), $this->payload(['half_payment_amount' => '', 'direct_commission' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($course->fresh()->half_payment_amount);
        $this->assertNull($course->fresh()->direct_commission);
    }

    /** A course page opened before this upload has no such fields — saving it must not wipe them. */
    public function test_a_form_without_the_fields_leaves_them_alone(): void
    {
        $this->actingAs($this->admin())->post(route('manage.courses.store'), $this->payload());
        $course = $this->elliott();

        $old = $this->payload(['subtitle' => 'Saved from an old tab']);
        unset($old['half_payment_amount'], $old['direct_commission']);

        $this->actingAs($this->admin())
            ->patch(route('manage.courses.update', $course), $old)
            ->assertSessionHasNoErrors();

        $this->assertSame('Saved from an old tab', $course->fresh()->subtitle, 'guard: the save happened');
        $this->assertSame(20000.0, (float) $course->fresh()->half_payment_amount);
        $this->assertSame(5000.0, (float) $course->fresh()->direct_commission);
    }

    public function test_a_lecturer_can_neither_see_nor_change_them(): void
    {
        $this->actingAs($this->admin())->post(route('manage.courses.store'), $this->payload());
        $course = $this->elliott();
        $course->update(['lecturer_id' => $this->lecturer()->id]);

        $this->actingAs($this->lecturer())
            ->get(route('manage.courses.edit', $course))
            ->assertOk()
            ->assertDontSee('Direct service income')
            ->assertDontSee('first instalment');

        $this->actingAs($this->lecturer())
            ->patch(route('manage.courses.update', $course), $this->payload([
                'half_payment_amount' => 1000,
                'direct_commission' => 30000,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(20000.0, (float) $course->fresh()->half_payment_amount, 'unchanged');
        $this->assertSame(5000.0, (float) $course->fresh()->direct_commission, 'unchanged');

        // And a lecturer's NEW course gets the standard, whatever is posted.
        $this->actingAs($this->lecturer())
            ->post(route('manage.courses.store'), $this->payload(['title' => 'Lecturer Course', 'direct_commission' => 30000]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Course::where('title', 'Lecturer Course')->firstOrFail()->direct_commission);
    }

    public function test_impossible_amounts_are_refused(): void
    {
        $admin = $this->admin();

        // The first instalment must leave a balance to pay.
        $this->actingAs($admin)->post(route('manage.courses.store'), $this->payload(['half_payment_amount' => 39000]))
            ->assertSessionHasErrors('half_payment_amount');

        // ... including against a discount price, which is what is billed.
        $this->actingAs($admin)->post(route('manage.courses.store'), $this->payload(['discount_price' => 19000]))
            ->assertSessionHasErrors('half_payment_amount');

        // A 0 would read as "the bonus is missing" everywhere — blank means the standard.
        $this->actingAs($admin)->post(route('manage.courses.store'), $this->payload(['direct_commission' => 0]))
            ->assertSessionHasErrors('direct_commission');

        $this->actingAs($admin)->post(route('manage.courses.store'), $this->payload(['direct_commission' => 40000]))
            ->assertSessionHasErrors('direct_commission');

        $this->actingAs($admin)->post(route('manage.courses.store'), $this->payload(['half_payment_amount' => 'abc']))
            ->assertSessionHasErrors('half_payment_amount');

        $this->assertSame(0, Course::where('title', 'MASTER IN ELLIOTT WAVE')->count(), 'nothing saved');
    }

    /** Leaving both blank keeps the course on the standard, exactly as today. */
    public function test_blank_amounts_use_the_standard(): void
    {
        $this->actingAs($this->admin())
            ->post(route('manage.courses.store'), $this->payload(['half_payment_amount' => '', 'direct_commission' => '']))
            ->assertSessionHasNoErrors();

        $course = $this->elliott();

        $this->assertNull($course->half_payment_amount);
        $this->assertNull($course->direct_commission);
        $this->assertSame(18000.0, \App\Support\CourseMoney::halfPayment($course));
    }
}
