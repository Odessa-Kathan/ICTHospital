<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_login_uses_clinic_name_instead_of_legacy_brand(): void
    {
        $this->get('/')->assertOk()->assertSee('Shreeji Clinic')->assertDontSee('ICTHospital')->assertDontSee('ICT Clinic');
    }

    public function test_existing_session_cannot_restore_old_brand_on_patient_pages(): void
    {
        $this->actingAs(User::create([
            'firstname' => 'Brand', 'lastname' => 'Test', 'login' => 'brandtest',
            'email' => 'brand@example.test', 'group' => 'Admin', 'desc' => 'test', 'password' => bcrypt('test-only'),
        ]));
        $this->withSession(['inName' => 'ICT Clinic'])->get('/patients/create')
            ->assertOk()->assertSee('Shreeji Clinic')->assertDontSee('ICT Clinic')->assertDontSee('alt="ICTHospital"', false);
        $this->post('/patients', ['name' => 'Brand patient']);
        $id = Patient::firstOrFail()->id;
        foreach (['card', 'wristband'] as $page) {
            $this->get('/patients/'.$id.'/'.$page)->assertOk()->assertSee('Shreeji Clinic')->assertDontSee('ICT Clinic');
        }
    }

    public function test_brand_partial_escapes_configured_name_and_verification_is_consistent(): void
    {
        config(['clinic.name' => '<script>unsafe</script>']);
        $html = view('layouts.clinic-brand')->render();
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>unsafe</script>', $html);
        config(['clinic.name' => 'Shreeji Clinic']);
        foreach (['verificationcode', 'app.users.verify'] as $view) {
            $html = view($view, ['institute' => (object) ['name' => 'ICT Clinic'], 'id' => 1, 'error' => '', 'errors' => new \Illuminate\Support\ViewErrorBag()])->render();
            $this->assertStringContainsString('Shreeji Clinic', $html);
            $this->assertStringNotContainsString('ICT Clinic', $html);
        }
    }

    public function test_display_rename_does_not_change_a_configured_sms_sender_identity(): void
    {
        config(['clinic.sms_sender_mask' => 'ICTHospital', 'clinic.name' => 'Shreeji Clinic']);
        $controller = new class extends \App\Http\Controllers\MessageController {
            public function mask(): string { return $this->senderMask(); }
        };
        $this->assertSame('ICTHospital', $controller->mask());
    }
}
