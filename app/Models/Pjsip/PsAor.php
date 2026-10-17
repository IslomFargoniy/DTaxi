<?php

namespace App\Models\Pjsip;

use Illuminate\Database\Eloquent\Model;

/**
 * Asterisk PJSIP Realtime jadvali (ps_aors) — Asterisk ODBC orqali o'qiydi (deploy/asterisk/sorcery.conf).
 *
 * @property string $id
 */
class PsAor extends Model
{
    protected $table = 'ps_aors';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
