<?php

use App\Models\Branch;
use App\Models\User;
use App\Support\UzbekCyrillic;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../Orders/helpers.php';

test('panel switches between Latin and Cyrillic Uzbek, Russian and English', function () {
    $user = User::factory()->create();

    foreach (['oz' => 'Ҳайдовчилар чати', 'en' => 'Drivers chat', 'ru' => 'Чат с водителями', 'uz' => 'Haydovchilar chati'] as $locale => $text) {
        $this->actingAs($user)->put(route('locale.update'), ['locale' => $locale])->assertRedirect();
        expect($user->fresh()->locale)->toBe($locale);

        // Ingliz tilida kalitning o'zi matn — tarjima kerak emas
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $locale === 'en'
            ? $page->where('locale', 'en')->where('translations', fn ($t) => ! isset($t['Drivers chat']) && $t['offer.accepted'] === 'Accepted')
            : $page->where('locale', $locale)->where('translations.Drivers chat', $text));
    }
});

test('Cyrillic translations cover every Latin key and keep placeholders', function () {
    $uz = json_decode((string) file_get_contents(lang_path('uz.json')), true);
    $oz = json_decode((string) file_get_contents(lang_path('oz.json')), true);

    expect(array_keys($oz))->toBe(array_keys($uz))
        ->and($oz['Order #:id created.'])->toContain(':id')
        ->and(UzbekCyrillic::convert("Qo'ng'iroq Click orqali, ma'lumot"))->toBe('Қўнғироқ Click орқали, маълумот')
        ->and(UzbekCyrillic::convert("Payme'da to'lov, SIP'ni ulash"))->toBe("Payme'da тўлов, SIP'ni улаш");
});

test('driver app changes its language and gets messages and map names in it', function () {
    $driver = driverNear(Branch::factory()->create(), 0);
    Sanctum::actingAs($driver->user, ['driver']);

    $this->putJson('/api/driver/v1/locale', ['locale' => 'en'])->assertOk()
        ->assertJsonPath('locale', 'en')
        ->assertJsonPath('map_style', route('map.style', ['lang' => 'en']));

    $this->putJson('/api/driver/v1/locale', ['locale' => 'xx'])->assertUnprocessable();
    expect($driver->user->fresh()->locale)->toBe('en');
});
