<?php

namespace App\Enums;

/**
 * Firma o'zi shartnoma tuzib, kalitlarini panelga kiritadigan tashqi xizmatlar (reja §0).
 */
enum IntegrationProvider: string
{
    case Click = 'click';
    case Payme = 'payme';
    case SpeechKit = 'speechkit';
    case Firebase = 'firebase';
    case Telegram = 'telegram';
    case BackupServer = 'backup_server';

    public function label(): string
    {
        return match ($this) {
            self::Click => 'Click',
            self::Payme => 'Payme',
            self::SpeechKit => 'Yandex SpeechKit',
            self::Firebase => 'Firebase (FCM)',
            self::Telegram => 'Telegram (ogohlantirishlar)',
            self::BackupServer => 'Zaxira serveri (SFTP)',
        };
    }

    /**
     * Panel formasidagi maydonlar. `secret` maydonlar shifrlanib saqlanadi va panelda yashiriladi.
     *
     * @return list<array{key: string, label: string, secret: bool, type?: string, options?: list<string>}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Click => [
                ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false],
                ['key' => 'service_id', 'label' => 'Service ID', 'secret' => false],
                ['key' => 'merchant_user_id', 'label' => 'Merchant user ID', 'secret' => false],
                ['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true],
            ],
            self::Payme => [
                ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false],
                ['key' => 'key', 'label' => 'Key', 'secret' => true],
                ['key' => 'test_key', 'label' => 'Test key', 'secret' => true],
            ],
            self::SpeechKit => [
                ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
                ['key' => 'folder_id', 'label' => 'Folder ID', 'secret' => false],
                ['key' => 'voice', 'label' => 'Voice', 'secret' => false, 'type' => 'select', 'options' => ['zamira', 'yulduz', 'nigora']],
            ],
            self::Firebase => [
                ['key' => 'service_account', 'label' => 'Service account JSON', 'secret' => true, 'type' => 'textarea'],
            ],
            // Tizim muammolari (trunk uzildi, xizmat to'xtadi) — firmaning o'z boti va guruhiga
            self::Telegram => [
                ['key' => 'bot_token', 'label' => 'Bot token', 'secret' => true],
                ['key' => 'chat_id', 'label' => 'Chat ID', 'secret' => false],
            ],
            // Kunlik baza zaxirasi nusxasi boshqa serverga (server ishdan chiqsa ham ma'lumot saqlanadi)
            self::BackupServer => [
                ['key' => 'host', 'label' => 'Host', 'secret' => false],
                ['key' => 'port', 'label' => 'Port', 'secret' => false],
                ['key' => 'username', 'label' => 'Login', 'secret' => false],
                ['key' => 'password', 'label' => 'Password', 'secret' => true],
                ['key' => 'path', 'label' => 'Folder', 'secret' => false],
            ],
        };
    }
}
