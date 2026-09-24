<?php

declare(strict_types=1);

namespace App\Services\Crm;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A deliberately small document language. No raw HTML, scripts, URLs or evaluated expressions. */
final class CrmDocumentService
{
    public const FIELDS = [
        'client.name' => 'Клиент: имя / организация', 'client.contact_name' => 'Контактное лицо',
        'client.phone' => 'Телефон клиента', 'client.email' => 'Email клиента', 'client.address' => 'Адрес клиента',
        'client.inn' => 'ИНН клиента', 'client.bank' => 'Банк клиента', 'client.account' => 'Счёт клиента',
        'deal.title' => 'Предмет сделки', 'deal.amount' => 'Сумма договора', 'deal.due_at' => 'Срок исполнения',
        'contract.number' => 'Номер договора', 'contract.date' => 'Дата договора', 'contract.signed_at' => 'Дата подписания',
        'contract.accepted_at' => 'Дата приёмки', 'company.name' => 'Наименование исполнителя',
        'company.inn' => 'ИНН исполнителя', 'company.kpp' => 'КПП исполнителя', 'company.ogrn' => 'ОГРН исполнителя',
        'company.address' => 'Адрес исполнителя', 'company.bank' => 'Банк исполнителя', 'company.bik' => 'БИК банка',
        'company.account' => 'Расчётный счёт', 'company.correspondent' => 'Корреспондентский счёт',
        'company.representative' => 'Представитель исполнителя', 'document.date' => 'Дата документа',
        'specification' => 'Таблица спецификации',
    ];

    private const TAGS = ['doc' => 'div', 'paragraph' => 'p', 'heading' => 'h2', 'bulletList' => 'ul', 'orderedList' => 'ol', 'listItem' => 'li', 'blockquote' => 'blockquote', 'table' => 'table', 'tableRow' => 'tr', 'tableHeader' => 'th', 'tableCell' => 'td'];

    public function validate(array $content): void
    {
        if (($content['type'] ?? '') !== 'doc' || strlen(json_encode($content)) > 250000) {
            $this->invalid('Шаблон должен быть документом не более 250 КБ.');
        }
        $count = 0;
        $walk = function (array $node, int $depth) use (&$walk, &$count) {
            if (++$count > 5000 || $depth > 20) {
                $this->invalid('Слишком сложный шаблон.');
            }
            $type = $node['type'] ?? '';
            if (! is_string($type) || (isset($node['marks']) && ! is_array($node['marks'])) || (isset($node['attrs']) && ! is_array($node['attrs']))) {
                $this->invalid('Некорректная структура элемента.');
            }
            if (! isset(self::TAGS[$type]) && ! in_array($type, ['text', 'hardBreak', 'horizontalRule', 'pageBreak', 'crmField'])) {
                $this->invalid('Неподдерживаемый элемент шаблона.');
            }
            if ($type === 'text' && (! isset($node['text']) || ! is_string($node['text']))) {
                $this->invalid('Некорректный текст.');
            }
            if ($type === 'crmField' && (! is_string($node['attrs']['field'] ?? null) || ! isset(self::FIELDS[$node['attrs']['field']]))) {
                $this->invalid('Неизвестное поле подстановки.');
            }
            foreach ($node['marks'] ?? [] as $mark) {
                if (! in_array($mark['type'] ?? '', ['bold', 'italic', 'strike', 'code'])) {
                    $this->invalid('Неподдерживаемое форматирование.');
                }
            }
            if (isset($node['content']) && ! is_array($node['content'])) {
                $this->invalid('Некорректное содержимое.');
            }
            foreach ($node['content'] ?? [] as $child) {
                if (! is_array($child)) {
                    $this->invalid('Некорректный элемент.');
                }
                $walk($child, $depth + 1);
            }
        };
        $walk($content, 0);
    }

    public function fields(array $content): array
    {
        $result = [];
        $walk = function (array $node) use (&$walk, &$result) {
            if (($node['type'] ?? '') === 'crmField') {
                $result[] = $node['attrs']['field'];
            }
            foreach ($node['content'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($content);

        return array_values(array_unique($result));
    }

    public function values(object $deal, object $client, array $company): array
    {
        $values = ['document.date' => now()->timezone('Europe/Moscow')->format('d.m.Y')];
        foreach (['name', 'contact_name', 'phone', 'email', 'address'] as $key) {
            $values['client.'.$key] = $client->$key ?? '';
        }
        foreach (json_decode($client->requisites ?: '{}', true) as $key => $value) {
            $values['client.'.$key] = $value;
        }
        foreach (['title', 'amount', 'due_at'] as $key) {
            $values['deal.'.$key] = $deal->$key ?? '';
        }
        foreach (['number' => 'contract_number', 'date' => 'contract_date', 'signed_at' => 'signed_at', 'accepted_at' => 'accepted_at'] as $key => $column) {
            $values['contract.'.$key] = $deal->$column ?? '';
        }
        foreach ($company as $key => $value) {
            $values['company.'.$key] = $value;
        }
        $values['specification'] = json_decode($deal->specification ?: '[]', true);

        return $values;
    }

    public function html(array $content, array $values): string
    {
        $this->validate($content);
        $missing = array_filter($this->fields($content), fn ($key) => ! isset($values[$key]) || $values[$key] === '' || $values[$key] === [] || $values[$key] === null);
        if ($missing) {
            throw ValidationException::withMessages(['fields' => array_map(fn ($key) => 'Заполните: '.self::FIELDS[$key], array_values($missing))]);
        }

        return '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><style>@page{margin:24mm 18mm}body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;line-height:1.5;color:#111}p{margin:0 0 10pt}h2{font-size:14pt}table{width:100%;border-collapse:collapse;margin:10pt 0}td,th{border:1px solid #777;padding:5pt;overflow-wrap:break-word}tr{page-break-inside:avoid}thead{display:table-header-group}.page-break{page-break-before:always}blockquote{margin:12pt} </style></head><body>'.$this->render($content, $values).'</body></html>';
    }

    private function render(array $node, array $values): string
    {
        $type = $node['type'];
        if ($type === 'text') {
            $text = e($node['text']);
            foreach ($node['marks'] ?? [] as $m) {
                $tag = ['bold' => 'strong', 'italic' => 'em', 'strike' => 's', 'code' => 'code'][$m['type']];
                $text = '<'.$tag.'>'.$text.'</'.$tag.'>';
            }

            return $text;
        }
        if ($type === 'crmField') {
            $value = $values[$node['attrs']['field']];
            if ($node['attrs']['field'] !== 'specification') {
                return e((string) $value);
            }
            $html = '<table><thead><tr><th>Наименование</th><th>Кол-во</th><th>Цена, ₽</th><th>Сумма, ₽</th></tr></thead><tbody>';
            foreach ($value as $line) {
                $html .= '<tr><td>'.e($line['name']).'</td><td>'.e($line['quantity']).'</td><td>'.e($line['price']).'</td><td>'.e(bcmul((string) $line['price'], (string) $line['quantity'], 2)).'</td></tr>';
            }

            return $html.'</tbody></table>';
        }
        if ($type === 'pageBreak') {
            return '<div class="page-break"></div>';
        }
        if ($type === 'hardBreak') {
            return '<br>';
        }
        if ($type === 'horizontalRule') {
            return '<hr>';
        }
        $tag = self::TAGS[$type];

        return '<'.$tag.'>'.implode('', array_map(fn ($child) => $this->render($child, $values), $node['content'] ?? [])).'</'.$tag.'>';
    }

    public function store(string $site, string $deal, string $bytes): array
    {
        $disk = config('crm.document_disk', 'yandex');
        $path = 'crm/'.$site.'/'.$deal.'/'.Str::ulid().'.enc';
        if (! Storage::disk($disk)->put($path, Crypt::encryptString($bytes), ['visibility' => 'private'])) {
            throw new \RuntimeException('Document storage unavailable');
        }

        return ['disk' => $disk, 'path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public function pdf(string $html): string
    {
        return Pdf::loadHTML($html, 'UTF-8')->setPaper('a4')->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', false)->setOption('isPhpEnabled', false)->setOption('isJavascriptEnabled', false)->output();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }
}
