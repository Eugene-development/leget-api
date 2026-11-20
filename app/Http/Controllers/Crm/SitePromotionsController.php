<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmStore;
use App\Services\SitePromotions;
use Illuminate\Http\Request;

final class SitePromotionsController extends Controller
{
    public function __construct(private CrmAccess $access, private CrmStore $store, private SitePromotions $promotions) {}

    public function index(Request $request, string $site): array
    {
        $this->access->site($request->user(), $site);
        $license = License::findOrFail($site);

        return ['items' => $this->promotions->inventory($license), 'canCreate' => $this->promotions->supports($license, '/actions', 'ActionsCards')];
    }

    public function history(Request $request, string $site, string $id): array
    {
        $this->access->site($request->user(), $site);

        return ['items' => $this->promotions->history(License::findOrFail($site), $id)];
    }

    public function save(Request $request, string $site, ?string $id = null): array
    {
        $this->access->site($request->user(), $site);
        $input = $request->validate([
            'title' => [$id ? 'prohibited' : 'required', 'string', 'max:160'],
            'version' => [$id ? 'required' : 'prohibited', 'regex:/^[a-f0-9]{64}$/'],
            'enabled' => ['required', 'boolean'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:s.v\Z'],
        ], [
            'title.required' => 'Введите название акции.',
            'title.max' => 'Название акции должно содержать до 160 символов.',
            'ends_at.date_format' => 'Укажите действительную дату и время окончания.',
        ]);

        return $this->store->mutate($request, $site, fn () => $this->promotions->save(License::findOrFail($site), $request->user()->id, $input, $id));
    }
}
