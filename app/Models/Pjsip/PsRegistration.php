<?php

namespace App\Models\Pjsip;

use Illuminate\Database\Eloquent\Model;

/**
 * Asterisk PJSIP Realtime jadvali (ps_registrations) — Asterisk ODBC orqali o'qiydi (deploy/asterisk/sorcery.conf).
 *
 * @property string $id
 */
class PsRegistration extends Model
{
    protected $table = 'ps_registrations';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
