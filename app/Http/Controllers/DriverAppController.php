<?php

namespace App\Http\Controllers;

use App\Support\DriverAppRelease;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Haydovchi ilovasini tarqatish (qaror #37): yuklab olish sahifasi, APK, versiya va yangi versiyani yuklash.
 */
class DriverAppController extends Controller
{
    public function __construct(private DriverAppRelease $release) {}

    /** Haydovchilar uchun ochiq sahifa: QR kod shu yerga olib keladi */
    public function page(Settings $settings): Response
    {
        return Inertia::render('driver-app/download', [
            'company' => $settings->get('company.name'),
            'release' => $this->release->current(),
        ]);
    }

    public function download(string $abi): StreamedResponse
    {
        abort_unless(in_array($abi, DriverAppRelease::ABIS, true) && Storage::exists($this->release->path($abi)), 404);

        return Storage::download($this->release->path($abi), "driver-{$abi}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    /** Ilova ichida avto-yangilanish (API) */
    public function version(Request $request): JsonResponse
    {
        $current = $this->release->current();
        $abi = $this->release->abiFor($request->query('abi'));

        return response()->json([
            'version_code' => $current['version_code'],
            'min_version_code' => $current['min_version_code'],
            'version_name' => $current['version_name'],
            'url' => $current['files'][$abi] ? route('driver-app.download', $abi, absolute: false) : null,
        ]);
    }

    public function admin(): Response
    {
        return Inertia::render('admin/driver-app', [
            'release' => $this->release->current(),
            'downloadPage' => route('driver-app.page'),
        ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $current = $this->release->current();
        $data = $request->validate([
            'version_code' => ['required', 'integer', 'min:'.($current['version_code'] + 1)],
            'version_name' => ['required', 'string', 'max:20'],
            'min_version_code' => ['required', 'integer', 'min:0', 'lte:version_code'],
            'apk' => ['required', 'array'],
            'apk.*' => ['file', 'max:204800'],
            ...collect(DriverAppRelease::ABIS)->mapWithKeys(fn (string $abi) => ["apk.{$abi}" => ['nullable', 'file']])->all(),
        ]);

        $apks = array_filter(
            collect(DriverAppRelease::ABIS)->mapWithKeys(fn (string $abi) => [$abi => $request->file("apk.{$abi}")])->all(),
        );
        validator(['apk' => $apks], ['apk' => [Rule::requiredIf(fn () => $apks === [])]])->validate();

        $this->release->publish((int) $data['version_code'], $data['version_name'], (int) $data['min_version_code'], $apks);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved')]);

        return back();
    }
}
