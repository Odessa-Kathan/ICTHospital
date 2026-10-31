<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientAgeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
        $this->actingAs(User::create([
            'firstname' => 'Age', 'lastname' => 'Test', 'login' => 'agetest',
            'email' => 'age@example.test', 'desc' => 'test', 'group' => 'Admin',
            'password' => bcrypt('test-only'),
        ]));
    }

    public static function validAges(): array
    {
        return [[0, '2026-01-01'], [34, '1992-01-01'], [150, '1876-01-01']];
    }

    #[DataProvider('validAges')]
    public function test_age_only_registration_is_calculated_and_marked_estimated(int $age, string $date): void
    {
        $this->post('/patients', ['name' => 'Age patient', 'age_only' => '1', 'age' => (string) $age])
            ->assertSessionHasNoErrors()->assertRedirect();
        $patient = Patient::firstOrFail();
        $this->assertSame($date, $patient->birthdate);
        $this->assertSame((string) $age, $patient->age);
        $this->assertTrue($patient->birthdate_estimated);
        $this->get('/patients/'.$patient->id)->assertOk()->assertSee('Estimated from age');
        $this->get('/patients')->assertOk()->assertSee('(estimated)');
        $this->get('/patients/'.$patient->id.'/wristband')->assertOk()->assertSee('(estimated)');
    }

    public static function invalidAges(): array
    {
        return [[-1], [151], ['34.5'], ['thirty'], [['34']]];
    }

    #[DataProvider('invalidAges')]
    public function test_invalid_age_cannot_create_a_patient($age): void
    {
        $this->post('/patients', ['name' => 'Invalid', 'age_only' => '1', 'age' => $age])
            ->assertSessionHasErrors('age');
        $this->assertSame(0, Patient::count());
    }

    public function test_age_mode_ignores_even_an_invalid_inactive_birthdate(): void
    {
        $this->post('/patients', ['name' => 'Age wins', 'age_only' => '1', 'age' => 30, 'birthdate' => ['invalid']])
            ->assertSessionHasNoErrors();
        $this->assertSame('1996-01-01', Patient::firstOrFail()->birthdate);
    }

    public function test_exact_mode_ignores_inactive_age_and_does_not_mark_january_first_estimated(): void
    {
        $this->post('/patients', ['name' => 'Exact', 'age_only' => '0', 'age' => ['invalid'], 'birthdate' => '1992-01-01'])
            ->assertSessionHasNoErrors();
        $patient = Patient::firstOrFail();
        $this->assertSame('34', $patient->age);
        $this->assertFalse($patient->birthdate_estimated);
        $this->get('/patients/'.$patient->id)->assertDontSee('Estimated from age');
    }

    public function test_exact_age_uses_completed_years_and_rejects_future_dates(): void
    {
        $this->post('/patients', ['name' => 'Birthday tomorrow', 'age_only' => 0, 'birthdate' => '1992-11-02'])
            ->assertSessionHasNoErrors();
        $this->assertSame('33', Patient::firstOrFail()->age);
        $this->post('/patients', ['name' => 'Future', 'age_only' => 0, 'birthdate' => '2026-11-02'])
            ->assertSessionHasErrors('birthdate');
        $this->post('/patients', ['name' => 'Invalid calendar', 'age_only' => 0, 'birthdate' => '2026-02-30'])
            ->assertSessionHasErrors('birthdate');
        $this->assertSame(1, Patient::count());
    }

    public function test_unknown_age_does_not_become_zero_and_legacy_name_only_still_works(): void
    {
        foreach ([['age_only' => 1, 'age' => ''], ['age_only' => 0, 'birthdate' => ''], []] as $fields) {
            $this->post('/patients', ['name' => 'Unknown'] + $fields)->assertSessionHasNoErrors();
        }
        foreach (Patient::all() as $patient) {
            $this->assertNull($patient->birthdate);
            $this->assertSame('', $patient->age);
            $this->assertFalse($patient->birthdate_estimated);
        }
    }

    public function test_legacy_request_does_not_infer_age_mode_from_age_alone(): void
    {
        $this->post('/patients', ['name' => 'Legacy', 'age' => 30])->assertSessionHasNoErrors();
        $this->assertNull(Patient::firstOrFail()->birthdate);
    }

    public function test_estimated_patient_can_be_corrected_or_switched_to_exact_or_unknown(): void
    {
        $this->post('/patients', ['name' => 'Change modes', 'age_only' => 1, 'age' => 30]);
        $patient = Patient::firstOrFail();
        $url = '/patients/'.$patient->id;
        $this->put($url, ['name' => 'Change modes', 'age_only' => 1, 'age' => 40])->assertSessionHasNoErrors();
        $this->assertSame('1986-01-01', $patient->fresh()->birthdate);
        $this->put($url, ['name' => 'Change modes', 'age_only' => 0, 'birthdate' => '1986-06-15'])->assertSessionHasNoErrors();
        $this->assertFalse($patient->fresh()->birthdate_estimated);
        $this->put($url, ['name' => 'Change modes', 'age_only' => 1, 'age' => 40])->assertSessionHasNoErrors();
        $this->assertTrue($patient->fresh()->birthdate_estimated);
        $this->put($url, ['name' => 'Change modes', 'age_only' => 1, 'age' => ''])->assertSessionHasNoErrors();
        $this->assertNull($patient->fresh()->birthdate);
        $this->assertFalse($patient->fresh()->birthdate_estimated);
    }

    public function test_estimated_dob_is_preserved_on_an_unchanged_edit_next_year(): void
    {
        $this->post('/patients', ['name' => 'Rollover', 'age_only' => 1, 'age' => 30]);
        $patient = Patient::firstOrFail();
        $this->travelTo(Carbon::parse('2027-11-01 10:00:00'));
        $this->get('/patients/'.$patient->id.'/edit')->assertOk()->assertSee('value="31"', false);
        $this->put('/patients/'.$patient->id, ['name' => 'Edited', 'age_only' => 1, 'age' => 31])->assertSessionHasNoErrors();
        $this->assertSame('1996-01-01', $patient->fresh()->birthdate);
        $this->assertSame('31', $patient->fresh()->age);
    }

    public function test_signed_form_preserves_estimate_when_submitted_after_new_year(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59:00'));
        $this->post('/patients', ['name' => 'Midnight', 'age_only' => 1, 'age' => 30]);
        $patient = Patient::firstOrFail();
        $html = $this->get('/patients/'.$patient->id.'/edit')->getContent();
        preg_match('/name="birthdate_baseline" value="([^"]+)"/', $html, $match);
        $token = html_entity_decode($match[1], ENT_QUOTES);
        $this->travelTo(Carbon::parse('2027-01-01 00:01:00'));
        $this->put('/patients/'.$patient->id, ['name' => 'Midnight', 'age_only' => 1, 'age' => 30, 'birthdate_baseline' => $token])
            ->assertSessionHasNoErrors();
        $this->assertSame('1996-01-01', $patient->fresh()->birthdate);
        $this->assertSame('31', $patient->fresh()->age);
    }

    public function test_tampered_edit_baseline_cannot_change_demographics(): void
    {
        $this->post('/patients', ['name' => 'Original', 'age_only' => 1, 'age' => 30]);
        $patient = Patient::firstOrFail();
        $this->put('/patients/'.$patient->id, ['name' => 'Changed', 'age_only' => 1, 'age' => 40, 'birthdate_baseline' => 'tampered'])
            ->assertSessionHasErrors('age');
        $this->assertSame('Original', $patient->fresh()->name);
        $this->assertSame('1996-01-01', $patient->fresh()->birthdate);
    }

    public function test_legacy_updates_preserve_estimation_unless_birthdate_changes(): void
    {
        $this->post('/patients', ['name' => 'Legacy edit', 'age_only' => 1, 'age' => 30]);
        $patient = Patient::firstOrFail();
        $url = '/patients/'.$patient->id;
        foreach ([[], ['birthdate' => '1996-01-01']] as $fields) {
            $this->put($url, ['name' => 'Legacy edit'] + $fields)->assertSessionHasNoErrors();
            $this->assertTrue($patient->fresh()->birthdate_estimated);
            $this->assertSame('1996-01-01', $patient->fresh()->birthdate);
        }
        $this->put($url, ['name' => 'Legacy edit', 'birthdate' => '1995-05-10'])->assertSessionHasNoErrors();
        $this->assertFalse($patient->fresh()->birthdate_estimated);
    }

    public function test_create_defaults_to_age_mode_and_exact_edit_is_not_checked(): void
    {
        $this->get('/patients/create')->assertOk()->assertSee('value="1" checked', false);
        $this->post('/patients', ['name' => 'Exact', 'birthdate' => '1992-01-01']);
        $html = $this->get('/patients/'.Patient::firstOrFail()->id.'/edit')->getContent();
        $this->assertMatchesRegularExpression('/id="age-only"[^>]*value="1"\s*>/', $html);
    }

    public function test_invalid_mode_is_rejected_and_client_cannot_set_estimation_flag(): void
    {
        $this->post('/patients', ['name' => 'Invalid', 'age_only' => 'yes', 'age' => 30])->assertSessionHasErrors('age_only');
        $this->post('/patients', ['name' => 'Flag injection', 'birthdate' => '1992-01-01', 'birthdate_estimated' => 1])
            ->assertSessionHasNoErrors();
        $this->assertFalse(Patient::firstOrFail()->birthdate_estimated);
    }

    public function test_legacy_invalid_birthdate_does_not_break_patient_screens(): void
    {
        $this->post('/patients', ['name' => 'Legacy malformed']);
        $patient = Patient::firstOrFail();
        $patient->update(['birthdate' => 'unknown', 'age' => '45']);
        $this->assertSame('45', $patient->fresh()->current_age);
        $this->get('/patients')->assertOk();
        $this->get('/patients/'.$patient->id)->assertOk();
        $this->get('/patients/'.$patient->id.'/edit')->assertOk();
    }

    public function test_stale_create_preview_is_not_silently_saved_after_new_year(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59:00'));
        $html = $this->get('/patients/create')->getContent();
        preg_match('/name="birthdate_reference_year" value="([^"]+)"/', $html, $match);
        $token = html_entity_decode($match[1], ENT_QUOTES);
        $this->travelTo(Carbon::parse('2027-01-01 00:01:00'));
        $this->post('/patients', ['name' => 'New Year', 'age_only' => 1, 'age' => 30, 'birthdate_reference_year' => $token])
            ->assertSessionHasErrors('age');
        $this->assertSame(0, Patient::count());
    }

    public function test_validation_error_after_new_year_preserves_the_signed_edit_preview(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59:00'));
        $this->post('/patients', ['name' => 'Preview rollover', 'age_only' => 1, 'age' => 30]);
        $patient = Patient::firstOrFail();
        $url = '/patients/'.$patient->id;
        $html = $this->get($url.'/edit')->getContent();
        preg_match('/name="birthdate_baseline" value="([^"]+)"/', $html, $match);
        $token = html_entity_decode($match[1], ENT_QUOTES);
        $this->travelTo(Carbon::parse('2027-01-01 00:01:00'));
        $this->from($url.'/edit')->put($url, [
            'name' => 'Preview rollover', 'age_only' => 1, 'age' => 30,
            'birthdate_baseline' => $token, 'email' => 'invalid',
        ])->assertSessionHasErrors('email');
        $this->get($url.'/edit')->assertOk()->assertSee('var originalAge = "30";', false);
        $this->put($url, ['name' => 'Preview rollover', 'age_only' => 1, 'age' => 30, 'birthdate_baseline' => $token])
            ->assertSessionHasNoErrors();
        $this->assertSame('1996-01-01', $patient->fresh()->birthdate);
    }
}
