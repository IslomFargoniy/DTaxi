<?php

namespace App\Models\Pjsip;

use Illuminate\Database\Eloquent\Model;

/**
 * Asterisk PJSIP Realtime jadvali (ps_contacts) — Asterisk ODBC orqali o'qiydi (deploy/asterisk/sorcery.conf).
 *
 * @property string $id
 */
class PsContact extends Model
{
    protected $table = 'ps_contacts';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
