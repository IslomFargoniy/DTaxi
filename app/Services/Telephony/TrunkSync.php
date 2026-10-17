<?php

namespace App\Services\Telephony;

use App\Models\Pjsip\PsAor;
use App\Models\Pjsip\PsAuth;
use App\Models\Pjsip\PsEndpoint;
use App\Models\Pjsip\PsEndpointIdIp;
use App\Models\Pjsip\PsRegistration;
use App\Models\SipTrunk;
use Illuminate\Support\Facades\DB;

/**
 * SIP trunkni Asterisk Realtime'ga yozish (andoza: panel.1call.uz PjsipTrunkSyncService).
 * Login berilgan bo'lsa — ro'yxatdan o'tish (registration), aks holda IP bo'yicha.
 */
class TrunkSync
{
    public function sync(SipTrunk $trunk): void
    {
        if (! $trunk->is_active) {
            $this->remove($trunk);

            return;
        }

        $id = $trunk->endpoint();
        // DNS SRV muammolarini chetlab o'tish uchun IP
        $ip = filter_var($trunk->host, FILTER_VALIDATE_IP) ? $trunk->host : gethostbyname($trunk->host);
        $serverUri = "sip:{$ip}:{$trunk->port}";

        DB::transaction(function () use ($trunk, $id, $ip, $serverUri) {
            $registered = (bool) $trunk->username;

            if ($registered) {
                PsAuth::query()->updateOrCreate(['id' => "auth-{$id}"], [
                    'auth_type' => 'userpass',
                    'username' => $trunk->username,
                    'password' => (string) $trunk->password,
                ]);
                PsRegistration::query()->updateOrCreate(['id' => "reg-{$id}"], [
                    'server_uri' => $serverUri,
                    'client_uri' => "sip:{$trunk->username}@{$trunk->host}:{$trunk->port}",
                    'outbound_auth' => "auth-{$id}",
                    'transport' => $trunk->transport,
                    'contact_user' => $trunk->username,
                    'retry_interval' => 30,
                    'expiration' => 300,
                    'auth_rejection_permanent' => 'no',
                ]);
            } else {
                PsAuth::query()->whereKey("auth-{$id}")->delete();
                PsRegistration::query()->whereKey("reg-{$id}")->delete();
            }

            PsAor::query()->updateOrCreate(['id' => "aor-{$id}"], ['contact' => $serverUri, 'qualify_frequency' => 60]);
            // Loopback bilan tanib bo'lmaydi: nginx WebRTC (/ws) ulanishlarini ham 127.0.0.1 dan proksi qiladi —
            // aks holda operator/haydovchi ro'yxatdan o'tishi shu trunkka tushib qoladi
            if (filter_var($ip, FILTER_VALIDATE_IP) && ! str_starts_with($ip, '127.')) {
                PsEndpointIdIp::query()->updateOrCreate(['id' => "ip-{$id}"], ['endpoint' => $id, 'match' => $ip]);
            } else {
                PsEndpointIdIp::query()->whereKey("ip-{$id}")->delete();
            }
            PsEndpoint::query()->updateOrCreate(['id' => $id], [
                'transport' => $trunk->transport,
                'aors' => "aor-{$id}",
                'context' => 'from-trunk',
                'disallow' => 'all',
                'allow' => 'alaw,ulaw',
                'direct_media' => 'no',
                'dtmf_mode' => 'auto',
                'outbound_auth' => $registered ? "auth-{$id}" : null,
                'from_user' => $trunk->from_user ?: $trunk->username,
                'from_domain' => $trunk->host,
                'rewrite_contact' => 'yes',
                'force_rport' => 'yes',
                'rtp_symmetric' => 'yes',
                'rtp_timeout' => 60,
            ]);
        });
    }

    public function remove(SipTrunk $trunk): void
    {
        $id = $trunk->endpoint();
        PsEndpoint::query()->whereKey($id)->delete();
        PsAor::query()->whereKey("aor-{$id}")->delete();
        PsAuth::query()->whereKey("auth-{$id}")->delete();
        PsRegistration::query()->whereKey("reg-{$id}")->delete();
        PsEndpointIdIp::query()->whereKey("ip-{$id}")->delete();
    }
}
