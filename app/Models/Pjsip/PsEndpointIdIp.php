<?php

namespace App\Models\Pjsip;

use Illuminate\Database\Eloquent\Model;

/**
 * Asterisk PJSIP Realtime jadvali (ps_endpoint_id_ips) — Asterisk ODBC orqali o'qiydi (deploy/asterisk/sorcery.conf).
 *
 * @property string $id
 */
class PsEndpointIdIp extends Model
{
    protected $table = 'ps_endpoint_id_ips';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
