<?php

declare(strict_types=1);

namespace App\Services\Growth;

use Illuminate\Support\Facades\DB;

final class LaunchChecklist
{
    public const MANUAL = ['domain_reachable', 'catalog_reviewed', 'images_reviewed', 'mobile_reviewed'];

    public function read(object $site): array
    {
        $header = $this->record($site->header_data ?? null);
        $footer = $this->record($site->footer_data ?? null);
        $phone = preg_replace('/\D/', '', (string) ($header['phone'] ?? ''));
        $email = $footer['email'] ?? $header['email'] ?? null;
        $latest = DB::table('service_requests')->where('license_id', $site->id)->where('created_at', '>=', now()->subDays(7))->orderByDesc('created_at')->first(['id', 'created_at', 'mail_status']);
        $acks = DB::table('site_launch_checks')->where('license_id', $site->id)->get()->keyBy('check_key');
        $check = fn (string $key, string $title, bool $ready, string $detail, string $href, bool $manual = false) => ['key' => $key, 'title' => $title, 'ready' => $ready, 'detail' => $detail, 'href' => $href, 'manual' => $manual, 'checked_at' => $acks->get($key)?->checked_at];
        $checks = [
            $check('domain_configured', 'Домен задан', (bool) filter_var($site->domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME), $site->domain ?: 'Задайте домен в личном кабинете платформы.', '/site-settings'),
            $check('phone', 'Рабочий телефон', strlen($phone) >= 10 && $phone !== '79990000000', 'Проверьте телефон в шапке сайта; стартовый номер не считается рабочим.', '/'),
            $check('email', 'Контактный email', (bool) filter_var($email, FILTER_VALIDATE_EMAIL), 'Укажите актуальный email в контактах сайта.', '/contacts'),
            $check('metrika', 'Счётчик Метрики', (bool) preg_match('/^[0-9]{1,20}$/D', (string) ($header['yandexMetrica'] ?? '')), 'Проверяется сохранённый ID; поступление визитов проверьте в Метрике.', '/site-settings'),
            $check('form_storage', 'Заявка сохранена', $latest !== null, $latest ? 'Последняя заявка за 7 дней: '.$latest->created_at.'.' : 'Отправьте тестовую заявку с сайта и проверьте её во «Входящих».', '/crm/incoming?site='.$site->id),
            $check('form_delivery', 'Письмо принято транспортом', $latest?->mail_status === 'sent', $latest ? 'Статус последнего письма: '.$latest->mail_status.'. Наличие во «Входящих» проверьте отдельно.' : 'После тестовой заявки проверьте статус доставки и почтовый ящик.', '/crm/incoming?site='.$site->id),
        ];
        foreach ([['domain_reachable', 'Сайт открывается по HTTPS', 'Откройте домен вне редактора и проверьте HTTPS.', '/'], ['catalog_reviewed', 'Каталог проверен', 'Проверьте видимые категории и доступность карточек посетителю.', '/catalog'], ['images_reviewed', 'Изображения проверены', 'Проверьте логотип и фотографии; замените заглушки.', '/'], ['mobile_reviewed', 'Мобильная версия проверена', 'Проверьте страницы, навигацию и отправку формы на телефоне.', '/']] as [$key, $title, $detail, $href]) {
            $checks[] = $check($key, $title, $acks->has($key), $detail, $href, true);
        }

        return ['checks' => $checks, 'ready' => count(array_filter($checks, fn ($c) => $c['ready'])), 'total' => count($checks)];
    }

    private function record(mixed $value): array
    {
        return is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?: []) : []);
    }
}
