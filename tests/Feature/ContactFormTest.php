<?php

namespace Tests\Feature;

use App\Mail\ContactInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_sends_inquiry_to_contact_address(): void
    {
        Mail::fake();

        $response = $this->postJson(route('contact.submit'), [
            'name' => 'Juan Pérez',
            'email' => 'juan@example.cl',
            'community' => 'Comunidad Los Aromos',
        ]);

        $response->assertOk()->assertJson(['status' => 'ok']);

        Mail::assertQueued(ContactInquiry::class, function (ContactInquiry $mail): bool {
            return $mail->hasTo(config('mail.contact.address'))
                && $mail->name === 'Juan Pérez'
                && $mail->email === 'juan@example.cl'
                && $mail->community === 'Comunidad Los Aromos';
        });
    }

    public function test_contact_form_requires_valid_fields(): void
    {
        Mail::fake();

        $response = $this->postJson(route('contact.submit'), [
            'name' => '',
            'email' => 'no-valido',
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);

        Mail::assertNothingSent();
    }

    public function test_community_is_optional(): void
    {
        Mail::fake();

        $this->postJson(route('contact.submit'), [
            'name' => 'María López',
            'email' => 'maria@example.cl',
        ])->assertOk();

        Mail::assertQueued(ContactInquiry::class, fn (ContactInquiry $mail) => $mail->community === '');
    }
}
