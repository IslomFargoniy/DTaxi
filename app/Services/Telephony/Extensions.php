<?php

namespace App\Services\Telephony;

use App\Enums\Role;
use App\Models\Driver;
use App\Models\Pjsip\PsAor;
use App\Models\Pjsip\PsAuth;
use App\Models\Pjsip\PsEndpoint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operator va haydovchilarning WebRTC ichki raqamlari (reja §5.5): foydalanuvchi yaratilganda/o'zgarganda
 * Asterisk Realtime jadvallariga yoziladi — Asterisk qayta ishga tushirilmaydi.
 * Andoza: panel.1call.uz (UserObserver, "-web" endpoint).
 */
class Extensions
{
    private const CODECS = 'alaw,ulaw,opus';

    /**
     * Digest realm — md5_cred shu realm bilan hisoblanadi, shuning uchun ps_auths.realm va pjsip.conf
     * (global default_realm) ga aniq yoziladi; o'zgarsa barcha ichki raqamlar telephony:sync bilan qayta yozilishi kerak.
     */
    public const REALM = 'asterisk';

    public function syncFor(User $user): void
    {
        $extension = $this->extensionFor($user);

        if ($extension === null) {
            $this->remove($user);

            return;
        }

        if ($user->sip_extension !== $extension || ! $user->sip_password) {
            if ($user->sip_extension && $user->sip_extension !== $extension) {
                $this->delete($user->sip_extension);
            }
            $user->forceFill([
                'sip_extension' => $extension,
                'sip_password' => $user->sip_password ?: Str::random(24),
            ])->saveQuietly();
        }

        $context = $user->is_active ? 'from-internal' : 'inactive';
        $password = (string) $user->sip_password;

        DB::transaction(function () use ($extension, $context, $password, $user) {
            PsAor::query()->updateOrCreate(['id' => $extension], [
                'max_contacts' => 3,
                'remove_existing' => 'yes',
                // OPTIONS ping (JsSIP javob beradi): ARI endpoint holati "online" bo'ladi, yopilgan brauzer ~25 s da "offline"
                'qualify_frequency' => 25,
            ]);
            // Bazada ochiq parol saqlanmaydi: Asterisk faqat digest tekshiradi (md5), ochiq parol
            // users.sip_password da shifrlangan holda — brauzer/ilova credentials() orqali oladi
            PsAuth::query()->updateOrCreate(['id' => $extension], [
                'auth_type' => 'md5',
                'username' => $extension,
                'realm' => self::REALM,
                'md5_cred' => md5($extension.':'.self::REALM.':'.$password),
                'password' => null,
            ]);
            PsEndpoint::query()->updateOrCreate(['id' => $extension], [
                'transport' => 'transport-ws',
                'aors' => $extension,
                'auth' => $extension,
                'context' => $context,
                'disallow' => 'all',
                'allow' => self::CODECS,
                'callerid' => "\"{$user->name}\" <{$extension}>",
                'direct_media' => 'no',
                'rtp_symmetric' => 'yes',
                'force_rport' => 'yes',
                'rewrite_contact' => 'yes',
                'dtmf_mode' => 'rfc4733',
                'webrtc' => 'yes',
                'use_avpf' => 'yes',
                'media_encryption' => 'dtls',
                'ice_support' => 'yes',
                'dtls_cert_file' => config('services.asterisk.dtls_cert'),
                'dtls_setup' => 'actpass',
                'dtls_verify' => 'fingerprint',
                'rtcp_mux' => 'yes',
                'bundle' => 'yes',
                'rtp_keepalive' => 5,
            ]);
        });
    }

    public function remove(User $user): void
    {
        if ($user->sip_extension) {
            $this->delete($user->sip_extension);
            $user->forceFill(['sip_extension' => null])->saveQuietly();
        }
    }

    /**
     * Operator brauzeri / haydovchi ilovasi uchun ulanish ma'lumotlari (JsSIP).
     *
     * @param  bool  $secure  sahifa https bo'lsa wss://, aks holda ws:// (brauzer sertifikatni qabul qilmagan http sahifadan wss ulanmaydi)
     * @return array{uri: string, password: string, ws: string, extension: string}|null
     */
    public function credentials(User $user, string $host, bool $secure = true): ?array
    {
        if (! $user->sip_extension || ! $user->sip_password) {
            return null;
        }

        return [
            'extension' => $user->sip_extension,
            'uri' => "sip:{$user->sip_extension}@{$host}",
            'password' => (string) $user->sip_password,
            'ws' => ($secure ? 'wss' : 'ws')."://{$host}/ws",
        ];
    }

    /**
     * Ichki raqam: operator — op{id}, haydovchi — dr{driverId}; boshqalarga kerak emas.
     */
    private function extensionFor(User $user): ?string
    {
        // Superadmin ham qo'ng'iroqqa javob bera oladi (kichik firmada egasi o'zi operator)
        if ($user->hasRole(Role::Operator) || $user->hasRole(Role::Admin) || $user->hasRole(Role::Superadmin)) {
            return 'op'.$user->id;
        }

        if ($user->hasRole(Role::Driver)) {
            $driverId = Driver::query()->withoutGlobalScopes()->where('user_id', $user->id)->value('id');

            return $driverId ? 'dr'.$driverId : null;
        }

        return null;
    }

    /**
     * Egasi yo'q ichki raqamlar (o'chirilgan foydalanuvchilar) — telephony:sync tozalaydi.
     */
    public function pruneOrphans(): int
    {
        $known = User::query()->whereNotNull('sip_extension')->pluck('sip_extension')->all();
        $orphans = PsEndpoint::query()->where(fn ($q) => $q->where('id', 'like', 'op%')->orWhere('id', 'like', 'dr%'))
            ->whereNotIn('id', $known)->pluck('id');
        $orphans->each(fn (string $id) => $this->delete($id));

        return $orphans->count();
    }

    private function delete(string $extension): void
    {
        PsEndpoint::query()->whereKey($extension)->delete();
        PsAuth::query()->whereKey($extension)->delete();
        PsAor::query()->whereKey($extension)->delete();
    }
}
