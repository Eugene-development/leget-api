<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    /**
     * Отдаёт HTML-счёт на оплату (для просмотра и печати в браузере).
     *
     * URL: GET /invoices/{id}/download
     * Требует: JWT-аутентификация (заголовок Authorization: Bearer ...)
     */
    public function download(Request $request, int $id): Response
    {
        $user    = $request->user();
        $invoice = Invoice::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $company = config('billing.company');
        // Токен для кнопки «Сохранить как PDF» внутри HTML-страницы
        $token = $request->bearerToken() ?? '';
        // Реальный адрес API из текущего запроса (гарантированно совпадает с портом сервера)
        $html = $this->buildHtml($invoice, $company, $token, $request->getSchemeAndHttpHost());

        return response($html, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="invoice-' . $invoice->number . '.html"',
        ]);
    }

    /**
     * Генерирует и скачивает PDF-счёт.
     *
     * URL: GET /invoices/{id}/pdf
     * Требует: JWT-аутентификация (заголовок Authorization: Bearer ...)
     */
    public function pdf(Request $request, int $id): Response
    {
        $user    = $request->user();
        $invoice = Invoice::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $company = config('billing.company');
        $html    = $this->buildHtml($invoice, $company, '', $request->getSchemeAndHttpHost());

        $pdf = Pdf::loadHTML($html, 'UTF-8')
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'dejavu sans')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isHtml5ParserEnabled', true);

        $filename = 'invoice-' . $invoice->number . '.pdf';

        return $pdf->download($filename);
    }

    private function buildHtml(Invoice $invoice, array $c, string $token = '', string $apiBase = ''): string
    {
        $number      = e($invoice->number);
        $date        = $invoice->created_at->format('d.m.Y');
        $amount      = number_format((float) $invoice->amount, 2, ',', ' ');
        $amountWords = $this->rubleWords((float) $invoice->amount);
        $companyName = e($invoice->company_name);
        $inn         = $invoice->inn ? ', ИНН ' . e($invoice->inn) : '';
        $invoiceId   = $invoice->id;
        // Базовый URL API для кнопки «Сохранить как PDF»

        $sellerName    = e($c['name']);
        $sellerInn     = e($c['inn']);
        $sellerOgrnip  = e($c['ogrnip']);
        $sellerAddress = e($c['legal_address']);
        $bankName      = e($c['bank_name']);
        $bik           = e($c['bik']);
        $account       = e($c['account']);
        $corrAccount   = e($c['corr_account']);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Счёт №{$number} от {$date}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'DejaVu Sans', sans-serif; }
  body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 11pt;
    color: #111;
    background: #fff;
    padding: 20mm 15mm;
  }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
  .logo { font-size: 22pt; font-weight: 900; letter-spacing: -1px; color: #111; }
  .invoice-meta { text-align: right; font-size: 10pt; color: #555; }
  .invoice-meta strong { font-size: 14pt; color: #111; display: block; margin-bottom: 2px; }
  .divider { border: none; border-top: 2px solid #111; margin: 14px 0; }
  .divider-light { border: none; border-top: 1px solid #ddd; margin: 10px 0; }
  .bank-block { display: flex; gap: 20px; margin-bottom: 14px; }
  .bank-block table { font-size: 10pt; border-collapse: collapse; }
  .bank-block td { padding: 2px 8px 2px 0; vertical-align: top; }
  .bank-block td:first-child { color: #555; white-space: nowrap; }
  .parties { display: flex; gap: 20px; margin-bottom: 14px; }
  .party { flex: 1; }
  .party h3 { font-size: 9pt; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
  .party p { font-size: 10pt; line-height: 1.5; }
  .items-table { width: 100%; border-collapse: collapse; margin: 18px 0; font-size: 10pt; }
  .items-table th { background: #f5f5f5; border: 1px solid #ccc; padding: 6px 10px; text-align: left; font-weight: 700; }
  .items-table td { border: 1px solid #ccc; padding: 6px 10px; vertical-align: top; }
  .items-table td.num { text-align: center; width: 36px; }
  .items-table td.price, .items-table td.total { text-align: right; white-space: nowrap; }
  .totals { float: right; margin: 0 0 20px 0; text-align: right; }
  .totals table { font-size: 10.5pt; }
  .totals td { padding: 3px 0 3px 30px; }
  .totals .total-row td { font-size: 12pt; font-weight: 700; border-top: 2px solid #111; padding-top: 6px; }
  .words { clear: both; font-size: 10pt; color: #333; margin-bottom: 18px; }
  .sign-block { margin-top: 30px; display: flex; gap: 60px; }
  .sign-block .field { flex: 1; }
  .sign-block .label { font-size: 9pt; color: #555; margin-bottom: 24px; }
  .sign-line { border-top: 1px solid #999; padding-top: 4px; font-size: 9pt; color: #555; }
  .footer-note { margin-top: 30px; font-size: 9pt; color: #888; border-top: 1px solid #eee; padding-top: 10px; }
  @media print {
    body { padding: 10mm; }
    .no-print { display: none !important; }
  }
</style>
</head>
<body>

<div class="header">
  <div class="logo">LEGET</div>
  <div class="invoice-meta">
    <strong>Счёт №&nbsp;{$number}</strong>
    от {$date} г.
  </div>
</div>

<hr class="divider">

<div class="bank-block">
  <table>
    <tr><td>Получатель:</td><td><strong>{$sellerName}</strong></td></tr>
    <tr><td>ИНН:</td><td>{$sellerInn}</td></tr>
    <tr><td>ОГРНИП:</td><td>{$sellerOgrnip}</td></tr>
    <tr><td>Юр. адрес:</td><td>{$sellerAddress}</td></tr>
  </table>
  <table>
    <tr><td>Банк:</td><td><strong>{$bankName}</strong></td></tr>
    <tr><td>БИК:</td><td>{$bik}</td></tr>
    <tr><td>Р/с:</td><td>{$account}</td></tr>
    <tr><td>К/с:</td><td>{$corrAccount}</td></tr>
  </table>
</div>

<hr class="divider">

<div class="parties">
  <div class="party">
    <h3>Продавец</h3>
    <p>{$sellerName}<br>ИНН {$sellerInn}</p>
  </div>
  <div class="party">
    <h3>Покупатель</h3>
    <p>{$companyName}{$inn}</p>
  </div>
</div>

<hr class="divider-light">

<table class="items-table">
  <thead>
    <tr>
      <th class="num">№</th>
      <th>Наименование</th>
      <th style="width:80px;text-align:right">Кол-во</th>
      <th style="width:80px;text-align:right">Ед.</th>
      <th style="width:110px;text-align:right">Цена, ₽</th>
      <th style="width:110px;text-align:right">Сумма, ₽</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td class="num">1</td>
      <td>Пополнение баланса личного кабинета LEGET (leget.ru)</td>
      <td class="price">1</td>
      <td class="price">усл.</td>
      <td class="price">{$amount}</td>
      <td class="total">{$amount}</td>
    </tr>
  </tbody>
</table>

<div class="totals">
  <table>
    <tr><td>Итого:</td><td><strong>{$amount} ₽</strong></td></tr>
    <tr><td>НДС:</td><td>Без НДС</td></tr>
    <tr class="total-row"><td>К оплате:</td><td><strong>{$amount} ₽</strong></td></tr>
  </table>
</div>

<div class="words">
  <strong>Итого к оплате:</strong> {$amountWords}
</div>

<hr class="divider-light">

<div class="sign-block">
  <div class="field">
    <div class="label">Руководитель / ИП</div>
    <div class="sign-line">{$sellerName}</div>
  </div>
  <div class="field">
    <div class="label">Бухгалтер</div>
    <div class="sign-line">{$sellerName}</div>
  </div>
</div>

<div class="footer-note">
  Оплата данного счёта означает согласие с условиями оказания услуг.<br>
  Счёт действителен в течение 30 дней с даты выставления.
</div>

<div class="no-print" style="margin-top:30px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap">
  <button onclick="window.print()" style="
    display: flex; align-items: center; gap: 8px;
    padding: 12px 28px;
    background: #111;
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
  " onmouseover="this.style.background='#333'" onmouseout="this.style.background='#111'">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
    Распечатать
  </button>
  <button id="save-btn" onclick="saveAsPdf()" style="
    display: flex; align-items: center; gap: 8px;
    padding: 12px 28px;
    background: #fff;
    color: #111;
    border: 2px solid #111;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
  " onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='#fff'">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
    Сохранить как PDF
  </button>
</div>

<script>
  var _invoiceId = {$invoiceId};
  var _apiBase   = '{$apiBase}';
  var _token     = '{$token}';

  function saveAsPdf() {
    var btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.textContent = 'Загрузка...';

    fetch(_apiBase + '/invoices/' + _invoiceId + '/pdf', {
      headers: { 'Authorization': 'Bearer ' + _token }
    })
    .then(function(res) {
      if (!res.ok) throw new Error('Ошибка сервера: ' + res.status);
      return res.blob();
    })
    .then(function(blob) {
      var url = URL.createObjectURL(blob);
      var a   = document.createElement('a');
      a.href     = url;
      a.download = 'invoice-{$number}.pdf';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
    })
    .catch(function(err) {
      alert('Не удалось скачать PDF: ' + err.message);
    })
    .finally(function() {
      btn.disabled = false;
      btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Сохранить как PDF';
    });
  }
</script>

</body>
</html>
HTML;
    }

    /**
     * Конвертирует числовое значение в словесную запись суммы (рубли + копейки).
     */
    private function rubleWords(float $amount): string
    {
        $rubles  = (int) $amount;
        $kopecks = (int) round(($amount - $rubles) * 100);

        $words = $this->numberToWords($rubles);

        $rubleSuffix = $this->plural($rubles, ['рубль', 'рубля', 'рублей']);
        $kopeckStr   = str_pad((string) $kopecks, 2, '0', STR_PAD_LEFT) . ' ' .
                       $this->plural($kopecks, ['копейка', 'копейки', 'копеек']);

        return ucfirst($words) . ' ' . $rubleSuffix . ' ' . $kopeckStr;
    }

    private function numberToWords(int $n): string
    {
        if ($n === 0) {
            return 'ноль';
        }

        $ones  = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $onesF = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens  = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

        $result = '';

        if ($n >= 1_000_000) {
            $millions = (int) ($n / 1_000_000);
            $result  .= $this->numberToWords($millions) . ' ' . $this->plural($millions, ['миллион', 'миллиона', 'миллионов']) . ' ';
            $n       %= 1_000_000;
        }

        if ($n >= 1_000) {
            $thousands = (int) ($n / 1_000);
            // Тысячи — женский род
            $tStr = $this->chunk($thousands, $onesF, $teens, $tens, $hundreds);
            $result .= $tStr . ' ' . $this->plural($thousands, ['тысяча', 'тысячи', 'тысяч']) . ' ';
            $n      %= 1_000;
        }

        if ($n > 0) {
            $result .= $this->chunk($n, $ones, $teens, $tens, $hundreds);
        }

        return trim($result);
    }

    private function chunk(int $n, array $ones, array $teens, array $tens, array $hundreds): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = $hundreds[(int) ($n / 100)];
            $n       %= 100;
        }
        if ($n >= 10 && $n <= 19) {
            $parts[] = $teens[$n - 10];
            $n       = 0;
        } elseif ($n >= 20) {
            $parts[] = $tens[(int) ($n / 10)];
            $n       %= 10;
        }
        if ($n > 0) {
            $parts[] = $ones[$n];
        }
        return implode(' ', array_filter($parts));
    }

    private function plural(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) {
            return $forms[2];
        }
        $n %= 10;
        if ($n === 1) return $forms[0];
        if ($n >= 2 && $n <= 4) return $forms[1];
        return $forms[2];
    }
}
