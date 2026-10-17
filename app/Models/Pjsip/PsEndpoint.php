<?php

namespace App\Models\Pjsip;

use Illuminate\Database\Eloquent\Model;

/**
 * Asterisk PJSIP Realtime jadvali (ps_endpoints) — Asterisk ODBC orqali o'qiydi (deploy/asterisk/sorcery.conf).
 *
 * @property string $id
 */
class PsEndpoint extends Model
{
    protected $table = 'ps_endpoints';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
