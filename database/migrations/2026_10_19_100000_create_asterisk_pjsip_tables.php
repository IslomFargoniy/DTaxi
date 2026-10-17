<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asterisk PJSIP Realtime Tables
 *
 * These tables are required by Asterisk's res_pjsip_realtime module.
 * Uses Laravel Schema Builder for cross-database compatibility (SQLite, MySQL, PostgreSQL).
 * CHECK constraints are added only on PostgreSQL for strict validation.
 */
return new class extends Migration
{
    // ──────── Boolean-like values used by Asterisk ────────
    private const YESNO = ['0', '1', 'off', 'on', 'false', 'true', 'no', 'yes'];

    /**
     * Detect if we're running on PostgreSQL.
     */
    private function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * Add a CHECK constraint (PostgreSQL only, silently skipped on other DBs).
     *
     * @param  list<string>  $values
     */
    private function addCheck(string $table, string $col, array $values): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $vals = implode(', ', array_map(fn ($v) => "'{$v}'::character varying", $values));
        $qcol = "\"{$col}\"";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$col}_check CHECK (({$qcol})::text = ANY ((ARRAY[{$vals}])::text[]))");
    }

    /**
     * Add yes/no CHECK constraint (PostgreSQL only).
     */
    private function addYesNo(string $table, string $col): void
    {
        $this->addCheck($table, $col, self::YESNO);
    }

    public function up(): void
    {
        $this->createAors();
        $this->createAuths();
        $this->createContacts();
        $this->createDomainAliases();
        $this->createEndpointIdIps();
        $this->createEndpoints();
        $this->createGlobals();
        $this->createRegistrations();
        $this->createTransports();
        $this->createExtensions();
    }

    // ═══════════════ ps_aors ═══════════════
    private function createAors(): void
    {
        Schema::create('ps_aors', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('contact', 255)->nullable();
            $table->integer('default_expiration')->nullable();
            $table->string('mailboxes', 80)->nullable();
            $table->integer('max_contacts')->nullable();
            $table->integer('minimum_expiration')->nullable();
            $table->string('remove_existing', 255)->nullable();
            $table->integer('qualify_frequency')->nullable();
            $table->string('authenticate_qualify', 255)->nullable();
            $table->integer('maximum_expiration')->nullable();
            $table->string('outbound_proxy', 255)->nullable();
            $table->string('support_path', 255)->nullable();
            $table->float('qualify_timeout')->nullable();
            $table->string('voicemail_extension', 40)->nullable();
            $table->string('remove_unavailable', 255)->nullable();
            $table->string('qualify_2xx_only', 255)->nullable();

            $table->index(['qualify_frequency', 'contact'], 'ps_aors_qualify_frequency_contact_index');
        });

        foreach (['remove_existing', 'authenticate_qualify', 'support_path', 'remove_unavailable', 'qualify_2xx_only'] as $col) {
            $this->addYesNo('ps_aors', $col);
        }
    }

    // ═══════════════ ps_auths ═══════════════
    private function createAuths(): void
    {
        Schema::create('ps_auths', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('auth_type', 255)->nullable();
            $table->integer('nonce_lifetime')->nullable();
            $table->string('md5_cred', 40)->nullable();
            $table->string('password', 80)->nullable();
            $table->string('realm', 255)->nullable();
            $table->string('username', 255)->nullable();
            $table->string('refresh_token', 255)->nullable();
            $table->string('oauth_clientid', 255)->nullable();
            $table->string('oauth_secret', 255)->nullable();
            $table->string('password_digest', 1024)->nullable();
            $table->string('supported_algorithms_uas', 1024)->nullable();
            $table->string('supported_algorithms_uac', 1024)->nullable();
        });

        $this->addCheck('ps_auths', 'auth_type', ['md5', 'userpass', 'google_oauth']);
    }

    // ═══════════════ ps_contacts ═══════════════
    private function createContacts(): void
    {
        Schema::create('ps_contacts', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('uri', 511)->nullable();
            $table->bigInteger('expiration_time')->nullable();
            $table->integer('qualify_frequency')->nullable();
            $table->string('outbound_proxy', 255)->nullable();
            $table->text('path')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->float('qualify_timeout')->nullable();
            $table->string('reg_server', 255)->nullable();
            $table->string('authenticate_qualify', 255)->nullable();
            $table->string('via_addr', 40)->nullable();
            $table->integer('via_port')->nullable();
            $table->string('call_id', 255)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->string('prune_on_boot', 255)->nullable();
            $table->string('qualify_2xx_only', 255)->nullable();

            $table->unique(['id', 'reg_server'], 'ps_contacts_id_reg_server_unique');
            $table->index(['qualify_frequency', 'expiration_time'], 'ps_contacts_qualify_frequency_expiration_time_index');
        });

        foreach (['authenticate_qualify', 'prune_on_boot', 'qualify_2xx_only'] as $col) {
            $this->addYesNo('ps_contacts', $col);
        }
    }

    // ═══════════════ ps_domain_aliases ═══════════════
    private function createDomainAliases(): void
    {
        Schema::create('ps_domain_aliases', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('domain', 255)->nullable();
        });
    }

    // ═══════════════ ps_endpoint_id_ips ═══════════════
    private function createEndpointIdIps(): void
    {
        Schema::create('ps_endpoint_id_ips', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('endpoint', 255)->nullable();
            $table->string('match', 80)->nullable();
            $table->string('srv_lookups', 255)->nullable();
            $table->string('match_header', 255)->nullable();
            $table->string('match_request_uri', 255)->nullable();
        });

        $this->addYesNo('ps_endpoint_id_ips', 'srv_lookups');
    }

    // ═══════════════ ps_endpoints ═══════════════
    private function createEndpoints(): void
    {
        Schema::create('ps_endpoints', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('transport', 40)->nullable();
            $table->string('aors', 2048)->nullable();
            $table->string('auth', 255)->nullable();
            $table->string('context', 40)->nullable();
            $table->string('disallow', 200)->nullable();
            $table->string('allow', 200)->nullable();
            $table->string('direct_media', 255)->nullable();
            $table->string('connected_line_method', 255)->nullable();
            $table->string('direct_media_method', 255)->nullable();
            $table->string('direct_media_glare_mitigation', 255)->nullable();
            $table->string('disable_direct_media_on_nat', 255)->nullable();
            $table->string('dtmf_mode', 255)->nullable();
            $table->string('external_media_address', 40)->nullable();
            $table->string('force_rport', 255)->nullable();
            $table->string('ice_support', 255)->nullable();
            $table->string('identify_by', 80)->nullable();
            $table->string('mailboxes', 40)->nullable();
            $table->string('moh_suggest', 40)->nullable();
            $table->string('outbound_auth', 255)->nullable();
            $table->string('outbound_proxy', 255)->nullable();
            $table->string('rewrite_contact', 255)->nullable();
            $table->string('rtp_ipv6', 255)->nullable();
            $table->string('rtp_symmetric', 255)->nullable();
            $table->string('send_diversion', 255)->nullable();
            $table->string('send_pai', 255)->nullable();
            $table->string('send_rpid', 255)->nullable();
            $table->integer('timers_min_se')->nullable();
            $table->string('timers', 255)->nullable();
            $table->integer('timers_sess_expires')->nullable();
            $table->string('callerid', 40)->nullable();
            $table->string('callerid_privacy', 255)->nullable();
            $table->string('callerid_tag', 40)->nullable();
            $table->string('100rel', 255)->nullable();
            $table->string('aggregate_mwi', 255)->nullable();
            $table->string('trust_id_inbound', 255)->nullable();
            $table->string('trust_id_outbound', 255)->nullable();
            $table->string('use_ptime', 255)->nullable();
            $table->string('use_avpf', 255)->nullable();
            $table->string('media_encryption', 255)->nullable();
            $table->string('inband_progress', 255)->nullable();
            $table->string('call_group', 40)->nullable();
            $table->string('pickup_group', 40)->nullable();
            $table->string('named_call_group', 40)->nullable();
            $table->string('named_pickup_group', 40)->nullable();
            $table->integer('device_state_busy_at')->nullable();
            $table->string('fax_detect', 255)->nullable();
            $table->string('t38_udptl', 255)->nullable();
            $table->string('t38_udptl_ec', 255)->nullable();
            $table->integer('t38_udptl_maxdatagram')->nullable();
            $table->string('t38_udptl_nat', 255)->nullable();
            $table->string('t38_udptl_ipv6', 255)->nullable();
            $table->string('tone_zone', 40)->nullable();
            $table->string('language', 40)->nullable();
            $table->string('one_touch_recording', 255)->nullable();
            $table->string('record_on_feature', 40)->nullable();
            $table->string('record_off_feature', 40)->nullable();
            $table->string('rtp_engine', 40)->nullable();
            $table->string('allow_transfer', 255)->nullable();
            $table->string('allow_subscribe', 255)->nullable();
            $table->string('sdp_owner', 40)->nullable();
            $table->string('sdp_session', 40)->nullable();
            $table->string('tos_audio', 10)->nullable();
            $table->string('tos_video', 10)->nullable();
            $table->integer('sub_min_expiry')->nullable();
            $table->string('from_domain', 40)->nullable();
            $table->string('from_user', 40)->nullable();
            $table->string('mwi_from_user', 40)->nullable();
            $table->string('dtls_verify', 40)->nullable();
            $table->string('dtls_rekey', 40)->nullable();
            $table->string('dtls_cert_file', 200)->nullable();
            $table->string('dtls_private_key', 200)->nullable();
            $table->string('dtls_cipher', 200)->nullable();
            $table->string('dtls_ca_file', 200)->nullable();
            $table->string('dtls_ca_path', 200)->nullable();
            $table->string('dtls_setup', 255)->nullable();
            $table->string('srtp_tag_32', 255)->nullable();
            $table->string('media_address', 40)->nullable();
            $table->string('redirect_method', 255)->nullable();
            $table->text('set_var')->nullable();
            $table->integer('cos_audio')->nullable();
            $table->integer('cos_video')->nullable();
            $table->string('message_context', 40)->nullable();
            $table->string('force_avp', 255)->nullable();
            $table->string('media_use_received_transport', 255)->nullable();
            $table->string('accountcode', 80)->nullable();
            $table->string('user_eq_phone', 255)->nullable();
            $table->string('moh_passthrough', 255)->nullable();
            $table->string('media_encryption_optimistic', 255)->nullable();
            $table->string('rpid_immediate', 255)->nullable();
            $table->string('g726_non_standard', 255)->nullable();
            $table->integer('rtp_keepalive')->nullable();
            $table->integer('rtp_timeout')->nullable();
            $table->integer('rtp_timeout_hold')->nullable();
            $table->string('bind_rtp_to_media_address', 255)->nullable();
            $table->string('voicemail_extension', 40)->nullable();
            $table->string('mwi_subscribe_replaces_unsolicited', 255)->nullable();
            $table->string('deny', 95)->nullable();
            $table->string('permit', 95)->nullable();
            $table->string('acl', 40)->nullable();
            $table->string('contact_deny', 95)->nullable();
            $table->string('contact_permit', 95)->nullable();
            $table->string('contact_acl', 40)->nullable();
            $table->string('subscribe_context', 40)->nullable();
            $table->integer('fax_detect_timeout')->nullable();
            $table->string('contact_user', 80)->nullable();
            $table->string('preferred_codec_only', 255)->nullable();
            $table->string('asymmetric_rtp_codec', 255)->nullable();
            $table->string('rtcp_mux', 255)->nullable();
            $table->string('allow_overlap', 255)->nullable();
            $table->string('refer_blind_progress', 255)->nullable();
            $table->string('notify_early_inuse_ringing', 255)->nullable();
            $table->integer('max_audio_streams')->nullable();
            $table->integer('max_video_streams')->nullable();
            $table->string('webrtc', 255)->nullable();
            $table->string('dtls_fingerprint', 255)->nullable();
            $table->string('incoming_mwi_mailbox', 40)->nullable();
            $table->string('bundle', 255)->nullable();
            $table->string('dtls_auto_generate_cert', 255)->nullable();
            $table->string('follow_early_media_fork', 255)->nullable();
            $table->string('accept_multiple_sdp_answers', 255)->nullable();
            $table->string('suppress_q850_reason_headers', 255)->nullable();
            $table->string('trust_connected_line', 255)->nullable();
            $table->string('send_connected_line', 255)->nullable();
            $table->string('ignore_183_without_sdp', 255)->nullable();
            $table->string('codec_prefs_incoming_offer', 128)->nullable();
            $table->string('codec_prefs_outgoing_offer', 128)->nullable();
            $table->string('codec_prefs_incoming_answer', 128)->nullable();
            $table->string('codec_prefs_outgoing_answer', 128)->nullable();
            $table->string('stir_shaken', 255)->nullable();
            $table->string('send_history_info', 255)->nullable();
            $table->string('allow_unauthenticated_options', 255)->nullable();
            $table->string('t38_bind_udptl_to_media_address', 255)->nullable();
            $table->string('geoloc_incoming_call_profile', 80)->nullable();
            $table->string('geoloc_outgoing_call_profile', 80)->nullable();
            $table->string('incoming_call_offer_pref', 255)->nullable();
            $table->string('outgoing_call_offer_pref', 255)->nullable();
            $table->string('stir_shaken_profile', 80)->nullable();
            $table->string('security_negotiation', 255)->nullable();
            $table->string('security_mechanisms', 512)->nullable();
            $table->string('send_aoc', 255)->nullable();
            $table->string('overlap_context', 80)->nullable();
            $table->string('tenantid', 80)->nullable();
            $table->string('suppress_moh_on_sendonly', 255)->nullable();
        });

        // Yes/No CHECK constraints (PostgreSQL only)
        $ynCols = [
            'direct_media', 'disable_direct_media_on_nat', 'force_rport', 'ice_support',
            'rewrite_contact', 'rtp_ipv6', 'rtp_symmetric', 'send_diversion', 'send_pai',
            'send_rpid', 'aggregate_mwi', 'trust_id_inbound', 'trust_id_outbound',
            'use_ptime', 'use_avpf', 'inband_progress', 'fax_detect', 't38_udptl',
            't38_udptl_nat', 't38_udptl_ipv6', 'one_touch_recording', 'allow_transfer',
            'allow_subscribe', 'srtp_tag_32', 'force_avp', 'media_use_received_transport',
            'user_eq_phone', 'moh_passthrough', 'media_encryption_optimistic', 'rpid_immediate',
            'g726_non_standard', 'bind_rtp_to_media_address', 'mwi_subscribe_replaces_unsolicited',
            'preferred_codec_only', 'asymmetric_rtp_codec', 'rtcp_mux', 'allow_overlap',
            'refer_blind_progress', 'notify_early_inuse_ringing', 'webrtc', 'bundle',
            'dtls_auto_generate_cert', 'follow_early_media_fork', 'accept_multiple_sdp_answers',
            'suppress_q850_reason_headers', 'trust_connected_line', 'send_connected_line',
            'ignore_183_without_sdp', 'stir_shaken', 'send_history_info',
            'allow_unauthenticated_options', 't38_bind_udptl_to_media_address',
            'send_aoc', 'suppress_moh_on_sendonly',
        ];
        foreach ($ynCols as $col) {
            $this->addYesNo('ps_endpoints', $col);
        }

        // Enum CHECK constraints (PostgreSQL only)
        $enumCols = [
            'connected_line_method' => ['invite', 'reinvite', 'update'],
            'direct_media_method' => ['invite', 'reinvite', 'update'],
            'direct_media_glare_mitigation' => ['none', 'outgoing', 'incoming'],
            'dtmf_mode' => ['rfc4733', 'inband', 'info', 'auto', 'auto_info'],
            'timers' => ['forced', 'no', 'required', 'yes'],
            'callerid_privacy' => ['allowed_not_screened', 'allowed_passed_screened', 'allowed_failed_screened', 'allowed', 'prohib_not_screened', 'prohib_passed_screened', 'prohib_failed_screened', 'prohib', 'unavailable'],
            '100rel' => ['no', 'required', 'peer_supported', 'yes'],
            'media_encryption' => ['no', 'sdes', 'dtls'],
            't38_udptl_ec' => ['none', 'fec', 'redundancy'],
            'dtls_setup' => ['active', 'passive', 'actpass'],
            'redirect_method' => ['user', 'uri_core', 'uri_pjsip'],
            'dtls_fingerprint' => ['SHA-1', 'SHA-256'],
            'incoming_call_offer_pref' => ['local', 'local_first', 'remote', 'remote_first'],
            'outgoing_call_offer_pref' => ['local', 'local_first', 'remote', 'remote_first'],
            'security_negotiation' => ['no', 'mediasec'],
        ];
        foreach ($enumCols as $col => $vals) {
            $this->addCheck('ps_endpoints', $col, $vals);
        }
    }

    // ═══════════════ ps_globals ═══════════════
    private function createGlobals(): void
    {
        Schema::create('ps_globals', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->integer('max_forwards')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('default_outbound_endpoint', 40)->nullable();
            $table->string('debug', 40)->nullable();
            $table->string('endpoint_identifier_order', 127)->nullable();
            $table->integer('max_initial_qualify_time')->nullable();
            $table->string('default_from_user', 80)->nullable();
            $table->integer('keep_alive_interval')->nullable();
            $table->string('regcontext', 80)->nullable();
            $table->integer('contact_expiration_check_interval')->nullable();
            $table->string('default_voicemail_extension', 40)->nullable();
            $table->string('disable_multi_domain', 255)->nullable();
            $table->integer('unidentified_request_count')->nullable();
            $table->integer('unidentified_request_period')->nullable();
            $table->integer('unidentified_request_prune_interval')->nullable();
            $table->string('default_realm', 40)->nullable();
            $table->integer('mwi_tps_queue_high')->nullable();
            $table->integer('mwi_tps_queue_low')->nullable();
            $table->string('mwi_disable_initial_unsolicited', 255)->nullable();
            $table->string('ignore_uri_user_options', 255)->nullable();
            $table->string('use_callerid_contact', 255)->nullable();
            $table->string('send_contact_status_on_update_registration', 255)->nullable();
            $table->string('taskprocessor_overload_trigger', 255)->nullable();
            $table->string('norefersub', 255)->nullable();
            $table->string('allow_sending_180_after_183', 255)->nullable();
            $table->string('all_codecs_on_empty_reinvite', 255)->nullable();
            $table->string('default_auth_algorithms_uas', 1024)->nullable();
            $table->string('default_auth_algorithms_uac', 1024)->nullable();
        });

        foreach (['disable_multi_domain', 'mwi_disable_initial_unsolicited', 'ignore_uri_user_options', 'use_callerid_contact', 'send_contact_status_on_update_registration', 'norefersub', 'allow_sending_180_after_183', 'all_codecs_on_empty_reinvite'] as $col) {
            $this->addYesNo('ps_globals', $col);
        }
        $this->addCheck('ps_globals', 'taskprocessor_overload_trigger', ['none', 'global', 'pjsip_only']);
    }

    // ═══════════════ ps_registrations ═══════════════
    private function createRegistrations(): void
    {
        Schema::create('ps_registrations', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('auth_rejection_permanent', 255)->nullable();
            $table->string('client_uri', 255)->nullable();
            $table->string('contact_user', 40)->nullable();
            $table->integer('expiration')->nullable();
            $table->integer('max_retries')->nullable();
            $table->string('outbound_auth', 255)->nullable();
            $table->string('outbound_proxy', 255)->nullable();
            $table->integer('retry_interval')->nullable();
            $table->integer('forbidden_retry_interval')->nullable();
            $table->string('server_uri', 255)->nullable();
            $table->string('transport', 40)->nullable();
            $table->string('support_path', 255)->nullable();
            $table->integer('fatal_retry_interval')->nullable();
            $table->string('line', 255)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->string('support_outbound', 255)->nullable();
            $table->string('contact_header_params', 255)->nullable();
            $table->integer('max_random_initial_delay')->nullable();
            $table->string('security_negotiation', 255)->nullable();
            $table->string('security_mechanisms', 512)->nullable();
            $table->string('user_agent', 255)->nullable();
        });

        foreach (['auth_rejection_permanent', 'support_path', 'line', 'support_outbound'] as $col) {
            $this->addYesNo('ps_registrations', $col);
        }
        $this->addCheck('ps_registrations', 'security_negotiation', ['no', 'mediasec']);
    }

    // ═══════════════ ps_transports ═══════════════
    private function createTransports(): void
    {
        Schema::create('ps_transports', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->integer('async_operations')->nullable();
            $table->string('bind', 40)->nullable();
            $table->string('ca_list_file', 200)->nullable();
            $table->string('cert_file', 200)->nullable();
            $table->string('cipher', 200)->nullable();
            $table->string('domain', 40)->nullable();
            $table->string('external_media_address', 40)->nullable();
            $table->string('external_signaling_address', 40)->nullable();
            $table->integer('external_signaling_port')->nullable();
            $table->string('method', 255)->nullable();
            $table->string('local_net', 40)->nullable();
            $table->string('password', 40)->nullable();
            $table->string('priv_key_file', 200)->nullable();
            $table->string('protocol', 255)->nullable();
            $table->string('require_client_cert', 255)->nullable();
            $table->string('verify_client', 255)->nullable();
            $table->string('verify_server', 255)->nullable();
            $table->string('tos', 10)->nullable();
            $table->integer('cos')->nullable();
            $table->string('allow_reload', 255)->nullable();
            $table->string('symmetric_transport', 255)->nullable();
            $table->string('allow_wildcard_certs', 255)->nullable();
            $table->boolean('tcp_keepalive_enable')->nullable();
            $table->integer('tcp_keepalive_idle_time')->nullable();
            $table->integer('tcp_keepalive_interval_time')->nullable();
            $table->integer('tcp_keepalive_probe_count')->nullable();
        });

        foreach (['require_client_cert', 'verify_client', 'verify_server', 'allow_reload', 'symmetric_transport', 'allow_wildcard_certs'] as $col) {
            $this->addYesNo('ps_transports', $col);
        }
        $this->addCheck('ps_transports', 'method', ['default', 'unspecified', 'tlsv1', 'tlsv1_1', 'tlsv1_2', 'tlsv1_3', 'sslv2', 'sslv23', 'sslv3']);
        $this->addCheck('ps_transports', 'protocol', ['udp', 'tcp', 'tls', 'ws', 'wss', 'flow']);
    }

    // ═══════════════ ps_extensions ═══════════════
    private function createExtensions(): void
    {
        Schema::create('ps_extensions', function (Blueprint $table) {
            $table->id();
            $table->string('context', 40);
            $table->string('exten', 40);
            $table->integer('priority')->default(1);
            $table->string('app', 40);
            $table->string('appdata', 256)->nullable();

            $table->unique(['context', 'exten', 'priority']);
        });
    }

    public function down(): void
    {
        $tables = [
            'ps_extensions', 'ps_transports', 'ps_registrations',
            'ps_globals', 'ps_endpoints', 'ps_endpoint_id_ips',
            'ps_domain_aliases', 'ps_contacts', 'ps_auths', 'ps_aors',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
